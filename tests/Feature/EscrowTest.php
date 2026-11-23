<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Dispute;
use App\Models\EscrowTransfer;
use App\Models\Orders;
use App\Models\Product;
use App\Models\ReturnAddress;
use App\Models\User;
use App\Services\Monero\EscrowTransferProcessor;
use App\Services\Monero\MoneroWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeMoneroWallet;
use Tests\TestCase;

class EscrowTest extends TestCase
{
    use RefreshDatabase;

    private FakeMoneroWallet $wallet;
    private User $buyer;
    private User $vendor;
    private Product $product;

    private const BUYER_ADDRESS = '4BuyerReturnAddress';
    private const VENDOR_ADDRESS = '4VendorReturnAddress';

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet = new FakeMoneroWallet();
        $this->app->instance(MoneroWallet::class, $this->wallet);

        config([
            'monero.order_min_confirmations' => 10,
            'monero.cancelled_order_commission_percentage' => 1.0,
            'monero.refund_dust_threshold' => '0.0005',
            'monero.payout_fee_reserve' => '0.01',
        ]);

        $this->buyer = $this->makeUser('buyer');
        $this->vendor = $this->makeUser('vendor');
        ReturnAddress::create(['user_id' => $this->buyer->id, 'monero_address' => self::BUYER_ADDRESS]);
        ReturnAddress::create(['user_id' => $this->vendor->id, 'monero_address' => self::VENDOR_ADDRESS]);

        $category = Category::create(['name' => 'Books']);
        $this->product = Product::create([
            'user_id' => $this->vendor->id,
            'category_id' => $category->id,
            'name' => 'Book',
            'description' => 'A book',
            'price' => 100,
            'type' => 'cargo',
            'stock_amount' => 10,
            'measurement_unit' => 'piece',
            'delivery_options' => [['description' => 'Post', 'price' => 0]],
        ]);
    }

    private function makeUser(string $name): User
    {
        return User::create([
            'username' => $name . Str::random(4),
            'password' => 'Secret123!',
            'mnemonic' => 'test mnemonic',
            'reference_id' => Str::random(16),
        ]);
    }

    /**
     * Order for 100 USD + 2 USD commission at 102 USD/XMR = exactly 1 XMR.
     */
    private function makeOrder(): Orders
    {
        $order = Orders::create([
            'user_id' => $this->buyer->id,
            'vendor_id' => $this->vendor->id,
            'subtotal' => 100,
            'commission' => 2,
            'total' => 102,
        ]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => 'Book',
            'product_description' => 'A book',
            'price' => 100,
            'quantity' => 1,
            'measurement_unit' => 'piece',
        ]);

        $this->assertTrue($order->generatePaymentAddress($this->wallet, '102'));
        $this->assertSame('1.000000000000', $order->required_xmr_amount);

        return $order;
    }

    private function paidOrder(): Orders
    {
        $order = $this->makeOrder();
        $this->wallet->receive($order->payment_address_index, '1');
        $order->checkPayments($this->wallet);
        $this->assertSame(Orders::STATUS_PAYMENT_RECEIVED, $order->status);

        return $order;
    }

    private function sentOrder(): Orders
    {
        $order = $this->paidOrder();
        $this->assertTrue($order->markAsSent());

        return $order;
    }

    private function processTransfers(): array
    {
        return app(EscrowTransferProcessor::class)->processOutstanding();
    }

    // ---------------------------------------------------------------------
    // Cancellation rules
    // ---------------------------------------------------------------------

    public function test_buyer_cannot_cancel_after_vendor_sent_the_order(): void
    {
        $order = $this->sentOrder();

        $this->actingAs($this->buyer)
            ->post(route('orders.mark-cancelled', $order->unique_url))
            ->assertSessionHas('error');

        $this->assertSame(Orders::STATUS_PRODUCT_SENT, $order->fresh()->status);
        $this->assertSame(0, EscrowTransfer::count());
    }

    public function test_buyer_cannot_cancel_a_disputed_order(): void
    {
        $order = $this->sentOrder();
        $order->openDispute('Package never arrived');

        $this->actingAs($this->buyer)
            ->post(route('orders.mark-cancelled', $order->unique_url))
            ->assertSessionHas('error');

        $this->assertSame(Orders::STATUS_DISPUTED, $order->fresh()->status);
        $this->assertSame(0, EscrowTransfer::count());
    }

    public function test_buyer_can_cancel_before_shipping_and_is_refunded_minus_fee(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->buyer)
            ->post(route('orders.mark-cancelled', $order->unique_url))
            ->assertSessionHas('success');

        $this->assertSame(Orders::STATUS_CANCELLED, $order->fresh()->status);

        $this->processTransfers();
        $this->assertSame('0.990000000000', $this->wallet->sentTo(self::BUYER_ADDRESS));
    }

    public function test_vendor_can_cancel_a_shipped_order_and_buyer_is_refunded(): void
    {
        $order = $this->sentOrder();

        $this->actingAs($this->vendor)
            ->post(route('orders.mark-cancelled', $order->unique_url))
            ->assertSessionHas('success');

        $this->processTransfers();
        $this->assertSame('0.990000000000', $this->wallet->sentTo(self::BUYER_ADDRESS));
        $this->assertSame('0.000000000000', $this->wallet->sentTo(self::VENDOR_ADDRESS));
    }

    // ---------------------------------------------------------------------
    // Payment detection and expiry
    // ---------------------------------------------------------------------

    public function test_payment_made_in_time_is_not_cancelled_when_seen_after_expiry(): void
    {
        $order = $this->makeOrder();
        $this->wallet->receive($order->payment_address_index, '1');

        $this->travel(2)->days();

        $this->actingAs($this->buyer)->get(route('orders.show', $order->unique_url));

        $order->refresh();
        $this->assertSame(Orders::STATUS_PAYMENT_RECEIVED, $order->status);
        $this->assertTrue($order->is_paid);
    }

    public function test_expired_partial_payment_is_refunded(): void
    {
        $order = $this->makeOrder();
        $this->wallet->receive($order->payment_address_index, '0.05'); // below the old 10% threshold

        $this->travel(2)->days();
        $this->artisan('escrow:process')->assertSuccessful();

        $this->assertSame(Orders::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame('0.049500000000', $this->wallet->sentTo(self::BUYER_ADDRESS));
    }

    public function test_unconfirmed_payment_does_not_pay_the_order_or_let_it_expire(): void
    {
        $order = $this->makeOrder();
        $this->wallet->receive($order->payment_address_index, '1', confirmations: 0);

        $order->checkPayments($this->wallet);
        $this->assertSame(Orders::STATUS_WAITING_PAYMENT, $order->status);
        $this->assertSame('1.000000000000', $order->unconfirmed_received_xmr);

        $this->travel(2)->days();
        $this->assertFalse($order->expireIfUnpaid());
        $this->assertSame(Orders::STATUS_WAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_payment_with_too_few_confirmations_does_not_count(): void
    {
        $order = $this->makeOrder();
        $this->wallet->receive($order->payment_address_index, '1', confirmations: 3);

        $order->checkPayments($this->wallet);

        $this->assertSame(Orders::STATUS_WAITING_PAYMENT, $order->status);
        $this->assertSame('0.000000000000', $order->total_received_xmr);
    }

    public function test_payment_arriving_after_cancellation_is_refunded(): void
    {
        $order = $this->makeOrder();
        $this->assertTrue($order->cancelBy($this->buyer->id));

        $this->wallet->receive($order->payment_address_index, '1');
        $this->artisan('escrow:process')->assertSuccessful();

        $this->assertSame('0.990000000000', $this->wallet->sentTo(self::BUYER_ADDRESS));
    }

    // ---------------------------------------------------------------------
    // Payouts
    // ---------------------------------------------------------------------

    public function test_completion_pays_the_vendor_exactly_once(): void
    {
        $order = $this->sentOrder();

        $this->assertTrue($order->markAsCompleted());
        $this->assertFalse($order->markAsCompleted()); // double click
        $order->processVendorPayment();                // legacy queued job
        $order->checkPayments($this->wallet);          // scheduler re-check

        $this->assertSame(1, EscrowTransfer::count());

        $this->processTransfers();
        $this->processTransfers();

        // 1 XMR * 100 / 102
        $this->assertSame('0.980392156862', $this->wallet->sentTo(self::VENDOR_ADDRESS));
        $this->assertCount(1, $this->wallet->relayed);
        $this->assertSame(9, $this->product->fresh()->stock_amount);
    }

    public function test_overpayment_is_returned_to_the_buyer_on_completion(): void
    {
        $order = $this->makeOrder();
        $this->wallet->receive($order->payment_address_index, '1.2');
        $order->checkPayments($this->wallet);
        $order->markAsSent();
        $order->markAsCompleted();

        $this->processTransfers();

        $this->assertSame('0.980392156862', $this->wallet->sentTo(self::VENDOR_ADDRESS));
        $this->assertSame('0.200000000000', $this->wallet->sentTo(self::BUYER_ADDRESS));
    }

    public function test_payout_waits_for_balance_instead_of_paying_partially(): void
    {
        $order = $this->sentOrder();
        $order->markAsCompleted();

        $this->wallet->unlockedBalance = Xmr('0.5');
        $this->processTransfers();

        $this->assertCount(0, $this->wallet->relayed);
        $this->assertSame(EscrowTransfer::STATUS_PENDING, EscrowTransfer::first()->status);

        $this->wallet->unlockedBalance = Xmr('5');
        $this->processTransfers();

        $this->assertSame('0.980392156862', $this->wallet->sentTo(self::VENDOR_ADDRESS));
    }

    public function test_lost_relay_response_does_not_pay_twice(): void
    {
        $order = $this->sentOrder();
        $order->markAsCompleted();

        $this->wallet->relayThenThrow = 1;
        $this->processTransfers();
        $this->processTransfers();

        $this->assertCount(1, $this->wallet->relayed);
        $this->assertSame(EscrowTransfer::STATUS_SENT, EscrowTransfer::first()->status);
    }

    public function test_failed_relay_retries_the_same_transaction(): void
    {
        $order = $this->sentOrder();
        $order->markAsCompleted();

        $this->wallet->failRelays = 1;
        $this->processTransfers();

        $transfer = EscrowTransfer::first();
        $this->assertSame(EscrowTransfer::STATUS_PROCESSING, $transfer->status);
        $hash = $transfer->tx_hash;

        $this->processTransfers();

        $this->assertCount(1, $this->wallet->prepared);
        $this->assertCount(1, $this->wallet->relayed);
        $this->assertSame($hash, $this->wallet->relayed[0]['tx_hash']);
        $this->assertSame(EscrowTransfer::STATUS_SENT, $transfer->fresh()->status);
    }

    public function test_payout_waits_for_a_return_address(): void
    {
        ReturnAddress::where('user_id', $this->vendor->id)->delete();

        $order = $this->sentOrder();
        $order->markAsCompleted();
        $this->processTransfers();

        $this->assertSame(EscrowTransfer::STATUS_AWAITING_ADDRESS, EscrowTransfer::first()->status);

        ReturnAddress::create(['user_id' => $this->vendor->id, 'monero_address' => self::VENDOR_ADDRESS]);
        $this->processTransfers();

        $this->assertSame('0.980392156862', $this->wallet->sentTo(self::VENDOR_ADDRESS));
        $this->assertSame('0.980392156862', $order->fresh()->vendor_payment_amount);
    }

    // ---------------------------------------------------------------------
    // Disputes
    // ---------------------------------------------------------------------

    public function test_buyer_prevails_refunds_the_buyer(): void
    {
        $order = $this->sentOrder();
        $dispute = $order->openDispute('Package never arrived');

        $this->assertTrue($dispute->resolveBuyerPrevails($this->vendor->id));

        $this->processTransfers();
        $this->assertSame(Dispute::STATUS_BUYER_PREVAILS, $dispute->fresh()->status);
        $this->assertSame('0.990000000000', $this->wallet->sentTo(self::BUYER_ADDRESS));
    }

    public function test_vendor_prevails_pays_the_vendor(): void
    {
        $order = $this->sentOrder();
        $dispute = $order->openDispute('Package never arrived');

        $this->assertTrue($dispute->resolveVendorPrevails($this->buyer->id));

        $this->processTransfers();
        $this->assertSame(Orders::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame('0.980392156862', $this->wallet->sentTo(self::VENDOR_ADDRESS));
    }

    public function test_dispute_stays_open_when_its_order_can_no_longer_be_settled(): void
    {
        $order = $this->sentOrder();
        $dispute = $order->openDispute('Package never arrived');

        // Simulate an order that left the disputed state some other way.
        Orders::whereKey($order->id)->update(['status' => Orders::STATUS_CANCELLED]);

        $this->assertFalse($dispute->resolveVendorPrevails($this->buyer->id));
        $this->assertSame(Dispute::STATUS_ACTIVE, $dispute->fresh()->status);
        $this->assertSame(0, EscrowTransfer::count());
    }
}

function Xmr(string $xmr): string
{
    return \App\Support\Xmr::toAtomic($xmr);
}
