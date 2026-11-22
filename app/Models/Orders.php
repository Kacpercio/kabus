<?php

namespace App\Models;

use App\Services\Monero\MoneroWallet;
use App\Support\Xmr;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Orders extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    // Order status constants
    public const STATUS_WAITING_PAYMENT = 'waiting_payment';
    public const STATUS_PAYMENT_RECEIVED = 'payment_received';
    public const STATUS_PRODUCT_SENT = 'product_sent';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DISPUTED = 'disputed';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'vendor_id',
        'unique_url',
        'subtotal',
        'commission',
        'total',
        'status',
        'shipping_address',
        'delivery_option',
        'encrypted_message',
        'is_paid',
        'is_sent',
        'is_completed',
        'is_disputed',
        'paid_at',
        'sent_at',
        'completed_at',
        'disputed_at',
        'payment_address',
        'payment_address_index',
        'required_xmr_amount',
        'total_received_xmr',
        'xmr_usd_rate',
        'expires_at',
        'payment_completed_at',
        'vendor_payment_amount',
        'vendor_payment_address',
        'vendor_payment_at',
        'buyer_refund_amount',
        'buyer_refund_address',
        'buyer_refund_at',
        'unconfirmed_received_xmr',
        'cancelled_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'subtotal' => 'decimal:2',
        'commission' => 'decimal:2',
        'total' => 'decimal:2',
        'required_xmr_amount' => 'decimal:12',
        'total_received_xmr' => 'decimal:12',
        'vendor_payment_amount' => 'decimal:12',
        'buyer_refund_amount' => 'decimal:12',
        'unconfirmed_received_xmr' => 'decimal:12',
        'xmr_usd_rate' => 'decimal:2',
        'is_paid' => 'boolean',
        'is_sent' => 'boolean',
        'is_completed' => 'boolean',
        'is_disputed' => 'boolean',
        'paid_at' => 'datetime',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'disputed_at' => 'datetime',
        'expires_at' => 'datetime',
        'payment_completed_at' => 'datetime',
        'vendor_payment_at' => 'datetime',
        'buyer_refund_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Boot function from Laravel.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {
            // Set UUID if not set
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }

            // Generate unique 30-character URL if not set
            if (empty($model->unique_url)) {
                $model->unique_url = Str::random(30);
            }

            // Set default status to waiting for payment
            if (empty($model->status)) {
                $model->status = self::STATUS_WAITING_PAYMENT;
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'unique_url';
    }

    /**
     * Get the user (buyer) that owns the order.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the vendor for this order.
     */
    public function vendor()
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    /**
     * Get the items for this order.
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }
    
    /**
     * Get the dispute for this order.
     */
    public function dispute()
    {
        return $this->hasOne(Dispute::class, 'order_id');
    }

    /**
     * Get the escrow transfers (vendor payouts and buyer refunds) of this order.
     */
    public function escrowTransfers()
    {
        return $this->hasMany(EscrowTransfer::class, 'order_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Locking
    |--------------------------------------------------------------------------
    |
    | Every status change runs on a freshly loaded copy of the order that is
    | locked with SELECT ... FOR UPDATE inside a transaction. Two concurrent
    | requests (double click, buyer and vendor at once, the scheduler and a
    | page view) therefore see each other's result instead of both acting on a
    | stale status.
    |
    */

    /**
     * Run $callback with a row-locked copy of this order inside a transaction,
     * then refresh this instance.
     */
    public function withLock(callable $callback)
    {
        $result = DB::transaction(function () use ($callback) {
            $locked = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            return $callback($locked);
        });

        $this->refresh();

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Incoming payments
    |--------------------------------------------------------------------------
    */

    /**
     * Generate a Monero subaddress for the order payment and fix the XMR price.
     *
     * @param  string|float  $xmrUsdRate
     */
    public function generatePaymentAddress(MoneroWallet $wallet, $xmrUsdRate): bool
    {
        try {
            return $this->withLock(function (Orders $order) use ($wallet, $xmrUsdRate) {
                if (!empty($order->payment_address)) {
                    return true;
                }

                if ($order->status !== self::STATUS_WAITING_PAYMENT) {
                    return false;
                }

                $order->required_xmr_amount = $order->calculateRequiredXmrAmount($xmrUsdRate);
                $order->xmr_usd_rate = $xmrUsdRate;

                $subaddress = $wallet->createSubaddress('Order Payment ' . $order->id);
                $order->payment_address = $subaddress['address'];
                $order->payment_address_index = $subaddress['index'];
                $order->expires_at = now()->addMinutes((int) config('monero.address_expiration_time', 1440));
                $order->save();

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Failed to generate payment address: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Read incoming payments for this order from the wallet and update the
     * order.
     *
     * Only payments with enough confirmations count towards the order; the rest
     * is stored as unconfirmed. Every confirmed payment counts, whatever its
     * size: small payments used to be ignored and were then never refunded.
     *
     * Funds that arrive after the order is finished (completed or cancelled)
     * are refunded to the buyer.
     */
    public function checkPayments(MoneroWallet $wallet): bool
    {
        if ($this->payment_address_index === null || empty($this->payment_address)) {
            return false;
        }

        $minConfirmations = (int) config('monero.order_min_confirmations', 10);
        $confirmed = '0';
        $unconfirmed = '0';

        foreach ($wallet->incomingTransfers((int) $this->payment_address_index) as $transfer) {
            $amount = Xmr::fromAtomic($transfer['amount']);

            if (!$transfer['in_pool'] && $transfer['confirmations'] >= $minConfirmations) {
                $confirmed = Xmr::add($confirmed, $amount);
            } else {
                $unconfirmed = Xmr::add($unconfirmed, $amount);
            }
        }

        return $this->withLock(function (Orders $order) use ($confirmed, $unconfirmed) {
            $order->total_received_xmr = $confirmed;
            $order->unconfirmed_received_xmr = $unconfirmed;

            if ($order->status === self::STATUS_WAITING_PAYMENT
                && $order->required_xmr_amount !== null
                && Xmr::isPositive($order->required_xmr_amount)
                && Xmr::cmp($confirmed, $order->required_xmr_amount) >= 0) {
                $order->status = self::STATUS_PAYMENT_RECEIVED;
                $order->is_paid = true;
                $order->paid_at = now();
                $order->payment_completed_at = now();
            }

            $order->save();

            if ($order->isFinal()) {
                $order->settleEscrow();
            }

            return true;
        });
    }

    /**
     * Check if the payment has expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Cancel the order if its payment window has passed without full payment.
     *
     * Call checkPayments() first so a payment that arrived in time is seen.
     * Orders with unconfirmed incoming funds are not cancelled until those
     * funds confirm. Confirmed partial payments are refunded.
     */
    public function expireIfUnpaid(): bool
    {
        return $this->withLock(function (Orders $order) {
            if ($order->status !== self::STATUS_WAITING_PAYMENT || !$order->isExpired()) {
                return false;
            }

            if (Xmr::isPositive($order->unconfirmed_received_xmr)) {
                return false;
            }

            $order->cancelLocked();

            return true;
        });
    }

    /**
     * @deprecated Use checkPayments() followed by expireIfUnpaid().
     */
    public function handleExpiredPayment(): bool
    {
        return $this->expireIfUnpaid();
    }

    /*
    |--------------------------------------------------------------------------
    | Automatic status changes
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the order should be auto-cancelled because it hasn't been marked as sent
     * within 96 hours (4 days) of payment being received.
     */
    public function shouldAutoCancelIfNotSent(): bool
    {
        if ($this->status !== self::STATUS_PAYMENT_RECEIVED) {
            return false;
        }

        return $this->paid_at && !$this->paid_at->copy()->addHours(96)->isFuture();
    }

    /**
     * Auto-cancel an order that hasn't been marked as sent within the time limit.
     */
    public function autoCancelIfNotSent(): bool
    {
        return $this->withLock(function (Orders $order) {
            if (!$order->shouldAutoCancelIfNotSent()) {
                return false;
            }

            $order->cancelLocked();

            return true;
        });
    }

    /**
     * Check if the order should be auto-completed because it hasn't been marked as completed
     * within 192 hours (8 days) of being marked as sent.
     */
    public function shouldAutoCompleteIfNotConfirmed(): bool
    {
        if ($this->status !== self::STATUS_PRODUCT_SENT || $this->is_disputed) {
            return false;
        }

        return $this->sent_at && !$this->sent_at->copy()->addHours(192)->isFuture();
    }

    /**
     * Auto-complete an order that hasn't been marked as completed within the time limit.
     */
    public function autoCompleteIfNotConfirmed(): bool
    {
        return $this->withLock(function (Orders $order) {
            if (!$order->shouldAutoCompleteIfNotConfirmed()) {
                return false;
            }

            $order->completeLocked();

            return true;
        });
    }

    /**
     * Get the time remaining before an order is auto-cancelled if not marked as sent.
     *
     * @return \Illuminate\Support\Carbon|null
     */
    public function getAutoCancelDeadline()
    {
        if ($this->status !== self::STATUS_PAYMENT_RECEIVED || !$this->paid_at) {
            return null;
        }

        return $this->paid_at->copy()->addHours(96);
    }

    /**
     * Get the time remaining before an order is auto-completed if not marked as completed.
     *
     * @return \Illuminate\Support\Carbon|null
     */
    public function getAutoCompleteDeadline()
    {
        if ($this->status !== self::STATUS_PRODUCT_SENT || !$this->sent_at || $this->is_disputed) {
            return null;
        }

        return $this->sent_at->copy()->addHours(192);
    }

    /**
     * Find all orders that need to be auto-cancelled because they haven't been
     * marked as sent within 96 hours of payment received.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function findOrdersToAutoCancel()
    {
        return self::where('status', self::STATUS_PAYMENT_RECEIVED)
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', now()->subHours(96))
            ->get();
    }

    /**
     * Find all orders that need to be auto-completed because they haven't been
     * marked as completed within 192 hours of being marked as sent.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function findOrdersToAutoComplete()
    {
        return self::where('status', self::STATUS_PRODUCT_SENT)
            ->where('is_disputed', false)
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', now()->subHours(192))
            ->get();
    }

    /**
     * Process all orders that need auto status changes.
     *
     * Runs from the escrow:process scheduled command, not on page views.
     *
     * @return array Array containing counts of cancelled and completed orders
     */
    public static function processAllAutoStatusChanges()
    {
        $cancelCount = 0;
        $completeCount = 0;

        foreach (self::findOrdersToAutoCancel() as $order) {
            if ($order->autoCancelIfNotSent()) {
                $cancelCount++;
            }
        }

        foreach (self::findOrdersToAutoComplete() as $order) {
            if ($order->autoCompleteIfNotConfirmed()) {
                $completeCount++;
            }
        }

        return [
            'cancelled' => $cancelCount,
            'completed' => $completeCount
        ];
    }

    /**
     * Calculate required XMR amount based on USD price and current XMR rate.
     *
     * @param  string|float  $xmrUsdRate
     */
    public function calculateRequiredXmrAmount($xmrUsdRate): string
    {
        if (!is_numeric($xmrUsdRate) || $xmrUsdRate <= 0) {
            throw new \InvalidArgumentException('XMR rate must be greater than zero');
        }

        return bcdiv((string) $this->total, Xmr::of($xmrUsdRate), Xmr::SCALE);
    }

    /*
    |--------------------------------------------------------------------------
    | Status transitions
    |--------------------------------------------------------------------------
    */

    /** Whether the order reached a final status. */
    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    /**
     * Whether $userId may cancel the order in its current status.
     *
     * - The buyer may cancel only before the vendor ships.
     * - The vendor may cancel until the order completes (the buyer is refunded).
     * - Nobody may cancel a disputed order; an admin resolves the dispute.
     */
    public function canBeCancelledBy(?string $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        if ($userId === $this->user_id) {
            return in_array($this->status, [
                self::STATUS_WAITING_PAYMENT,
                self::STATUS_PAYMENT_RECEIVED,
            ], true);
        }

        if ($userId === $this->vendor_id) {
            return in_array($this->status, [
                self::STATUS_WAITING_PAYMENT,
                self::STATUS_PAYMENT_RECEIVED,
                self::STATUS_PRODUCT_SENT,
            ], true);
        }

        return false;
    }

    /**
     * Mark the order as paid.
     */
    public function markAsPaid()
    {
        return $this->withLock(function (Orders $order) {
            if ($order->status !== self::STATUS_WAITING_PAYMENT) {
                return false;
            }

            $order->status = self::STATUS_PAYMENT_RECEIVED;
            $order->is_paid = true;
            $order->paid_at = now();
            $order->save();

            return true;
        });
    }

    /**
     * Mark the order as sent.
     */
    public function markAsSent()
    {
        return $this->withLock(function (Orders $order) {
            if ($order->status !== self::STATUS_PAYMENT_RECEIVED) {
                return false;
            }

            $order->status = self::STATUS_PRODUCT_SENT;
            $order->is_sent = true;
            $order->sent_at = now();
            $order->save();

            return true;
        });
    }

    /**
     * Mark the order as completed and schedule the vendor payout.
     *
     * A disputed order can only be completed by resolving the dispute.
     */
    public function markAsCompleted(bool $resolvingDispute = false)
    {
        return $this->withLock(function (Orders $order) use ($resolvingDispute) {
            $allowed = $resolvingDispute ? self::STATUS_DISPUTED : self::STATUS_PRODUCT_SENT;

            if ($order->status !== $allowed) {
                return false;
            }

            $order->completeLocked();

            return true;
        });
    }

    /**
     * Cancel the order on behalf of the buyer or the vendor.
     *
     * The permission check runs on the locked row, so a vendor marking the
     * order as sent at the same moment cannot let the buyer cancel a shipped
     * order.
     */
    public function cancelBy(?string $userId): bool
    {
        return $this->withLock(function (Orders $order) use ($userId) {
            if (!$order->canBeCancelledBy($userId)) {
                return false;
            }

            $order->cancelLocked();

            return true;
        });
    }

    /**
     * Mark the order as cancelled and refund what the buyer paid.
     *
     * Without $resolvingDispute only undisputed, unfinished orders can be
     * cancelled. Prefer cancelBy() for user actions.
     */
    public function markAsCancelled(bool $resolvingDispute = false)
    {
        return $this->withLock(function (Orders $order) use ($resolvingDispute) {
            $allowed = $resolvingDispute
                ? [self::STATUS_DISPUTED]
                : [self::STATUS_WAITING_PAYMENT, self::STATUS_PAYMENT_RECEIVED, self::STATUS_PRODUCT_SENT];

            if (!in_array($order->status, $allowed, true)) {
                return false;
            }

            $order->cancelLocked();

            return true;
        });
    }

    /**
     * Complete a locked order: update status, reduce stock, schedule payout.
     */
    protected function completeLocked(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->is_completed = true;
        $this->completed_at = now();
        $this->is_disputed = false;
        $this->save();

        foreach ($this->items()->get() as $item) {
            $quantity = $item->quantity;
            if ($item->bulk_option && isset($item->bulk_option['amount'])) {
                $quantity = $item->quantity * $item->bulk_option['amount'];
            }

            // Atomic decrement that never goes below zero.
            Product::whereKey($item->product_id)
                ->where('stock_amount', '>=', $quantity)
                ->decrement('stock_amount', $quantity);
        }

        $this->settleEscrow();
    }

    /**
     * Cancel a locked order and schedule the buyer refund.
     */
    protected function cancelLocked(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_at = now();
        $this->is_disputed = false;
        $this->save();

        $this->settleEscrow();
    }

    /*
    |--------------------------------------------------------------------------
    | Escrow settlement
    |--------------------------------------------------------------------------
    */

    /**
     * Schedule transfers for every confirmed XMR of a finished order that no
     * transfer covers yet. Must run on a locked order.
     *
     * - Completed: the vendor gets the order price minus the marketplace
     *   commission, once. Anything paid on top of the price (overpayment, a
     *   late payment) is refunded to the buyer in full.
     * - Cancelled: the buyer gets everything back minus the cancellation
     *   commission (monero.cancelled_order_commission_percentage).
     *
     * Running it again creates nothing new unless more money arrived.
     */
    public function settleEscrow(): void
    {
        if (!$this->isFinal()) {
            return;
        }

        $covered = Xmr::of(
            $this->escrowTransfers()->sum('gross_amount_xmr')
        );
        $uncovered = Xmr::sub($this->total_received_xmr, $covered);

        if (!Xmr::isPositive($uncovered)) {
            return;
        }

        if ($this->status === self::STATUS_COMPLETED) {
            $hasPayout = $this->escrowTransfers()
                ->where('type', EscrowTransfer::TYPE_VENDOR_PAYOUT)
                ->exists();

            if (!$hasPayout) {
                $price = Xmr::of($this->required_xmr_amount ?? $uncovered);
                $gross = Xmr::cmp($uncovered, $price) > 0 ? $price : $uncovered;

                // The buyer paid subtotal + commission; the vendor's share is subtotal / total.
                $net = Xmr::of(bcdiv(
                    bcmul($gross, (string) $this->subtotal, Xmr::SCALE + 4),
                    (string) $this->total,
                    Xmr::SCALE
                ));

                $this->scheduleTransfer(EscrowTransfer::TYPE_VENDOR_PAYOUT, $this->vendor_id, $gross, $net);
                $uncovered = Xmr::sub($uncovered, $gross);
            }

            // Overpayment or late payment: give it back without commission.
            if (Xmr::cmp($uncovered, (string) config('monero.refund_dust_threshold', '0.0005')) >= 0) {
                $this->scheduleTransfer(EscrowTransfer::TYPE_BUYER_REFUND, $this->user_id, $uncovered, $uncovered);
            }

            return;
        }

        // Cancelled
        if (Xmr::cmp($uncovered, (string) config('monero.refund_dust_threshold', '0.0005')) < 0) {
            return;
        }

        $commissionPercentage = (string) config('monero.cancelled_order_commission_percentage', 1.0);
        $keep = bcdiv(bcmul($uncovered, $commissionPercentage, Xmr::SCALE + 4), '100', Xmr::SCALE);
        $net = Xmr::sub($uncovered, $keep);

        $this->scheduleTransfer(EscrowTransfer::TYPE_BUYER_REFUND, $this->user_id, $uncovered, $net);
    }

    /**
     * Record a transfer to be sent by the escrow processor.
     */
    protected function scheduleTransfer(string $type, string $recipientId, string $gross, string $net): EscrowTransfer
    {
        $address = ReturnAddress::where('user_id', $recipientId)->inRandomOrder()->value('monero_address');

        $transfer = $this->escrowTransfers()->create([
            'type' => $type,
            'recipient_id' => $recipientId,
            'gross_amount_xmr' => $gross,
            'amount_xmr' => $net,
            'address' => $address,
            'status' => $address ? EscrowTransfer::STATUS_PENDING : EscrowTransfer::STATUS_AWAITING_ADDRESS,
            'last_error' => $address ? null : 'Recipient has no return address.',
        ]);

        Log::info('Escrow transfer scheduled', [
            'order' => $this->id,
            'transfer' => $transfer->id,
            'type' => $type,
            'amount' => $net,
        ]);

        return $transfer;
    }

    /**
     * @deprecated Payouts are scheduled by markAsCompleted() and sent by the
     *             escrow:process command. Kept for old queued jobs.
     */
    public function processVendorPayment()
    {
        if ($this->status === self::STATUS_COMPLETED) {
            $this->withLock(fn (Orders $order) => $order->settleEscrow());
        }

        return true;
    }

    /**
     * @deprecated Refunds are scheduled by markAsCancelled() and sent by the
     *             escrow:process command.
     */
    public function processBuyerRefund()
    {
        if ($this->status === self::STATUS_CANCELLED) {
            $this->withLock(fn (Orders $order) => $order->settleEscrow());
        }

        return true;
    }

    /**
     * Get the formatted status.
     */
    public function getFormattedStatus()
    {
        return match($this->status) {
            self::STATUS_WAITING_PAYMENT => 'Waiting for Payment',
            self::STATUS_PAYMENT_RECEIVED => 'Payment Received',
            self::STATUS_PRODUCT_SENT => 'Product Sent',
            self::STATUS_COMPLETED => 'Order Completed',
            self::STATUS_CANCELLED => 'Order Cancelled',
            self::STATUS_DISPUTED => 'Order Disputed',
            default => 'Unknown Status'
        };
    }
    
    /**
     * Open a dispute for the order.
     *
     * @return Dispute|false
     */
    public function openDispute($reason)
    {
        return $this->withLock(function (Orders $order) use ($reason) {
            // Only allow disputes for orders in "product sent" status
            if ($order->status !== self::STATUS_PRODUCT_SENT) {
                return false;
            }

            $order->status = self::STATUS_DISPUTED;
            $order->is_disputed = true;
            $order->disputed_at = now();
            $order->save();

            $dispute = new Dispute([
                'order_id' => $order->id,
                'status' => Dispute::STATUS_ACTIVE,
                'reason' => $reason
            ]);
            $dispute->save();

            return $dispute;
        });
    }

    /**
     * Check if the order has an active dispute.
     */
    public function hasActiveDispute()
    {
        return $this->dispute && $this->dispute->status === Dispute::STATUS_ACTIVE;
    }

    /**
     * Get all orders for a user (as buyer).
     */
    public static function getUserOrders($userId)
    {
        return self::where('user_id', $userId)
            ->with(['items', 'vendor'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all orders for a vendor.
     */
    public static function getVendorOrders($vendorId)
    {
        return self::where('vendor_id', $vendorId)
            ->with(['items', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Find order by its unique URL.
     */
    public static function findByUrl($url)
    {
        return self::where('unique_url', $url)
            ->with(['items', 'user', 'vendor'])
            ->first();
    }

    /**
     * Check if a user has too many cancelled orders from a specific vendor.
     * 
     * @param int $userId
     * @param int $vendorId
     * @param int $limit
     * @return bool
     */
    public static function hasExcessiveCancelledOrders($userId, $vendorId, $limit = 4)
    {
        $cancelledCount = self::where('user_id', $userId)
            ->where('vendor_id', $vendorId)
            ->where('status', self::STATUS_CANCELLED)
            ->count();
            
        return $cancelledCount >= $limit;
    }
    
    /**
     * Check if a user has a pending payment order from a specific vendor.
     * 
     * @param int $userId
     * @param int $vendorId
     * @return bool
     */
    public static function hasPendingPaymentOrder($userId, $vendorId)
    {
        return self::where('user_id', $userId)
            ->where('vendor_id', $vendorId)
            ->where('status', self::STATUS_WAITING_PAYMENT)
            ->exists();
    }
    
    /**
     * Check if a user can create a new order with a specific vendor.
     * 
     * @param int $userId
     * @param int $vendorId
     * @return array [bool $canCreate, string $reason]
     */
    public static function canCreateNewOrder($userId, $vendorId)
    {
        // Check for excessive cancelled orders
        if (self::hasExcessiveCancelledOrders($userId, $vendorId)) {
            return [false, 'You have too many cancelled orders with this vendor. Please contact support.'];
        }
        
        // Check for pending payment orders
        if (self::hasPendingPaymentOrder($userId, $vendorId)) {
            return [false, 'You already have a pending payment order with this vendor. Please complete or cancel it before creating a new order.'];
        }
        
        return [true, ''];
    }

    /**
     * Create an order from cart items.
     */
    public static function createFromCart($user, $cartItems, $subtotal, $commission, $total)
    {
        // Get vendor ID from the first cart item
        $vendorId = $cartItems->first()->product->user_id;

        // Create the order
        $order = self::create([
            'user_id' => $user->id,
            'vendor_id' => $vendorId,
            'subtotal' => $subtotal,
            'commission' => $commission,
            'total' => $total,
            'status' => self::STATUS_WAITING_PAYMENT
        ]);

        // Create order items
        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_description' => $product->description,
                'price' => $cartItem->price,
                'quantity' => $cartItem->quantity,
                'measurement_unit' => $product->measurement_unit,
                'delivery_option' => $cartItem->selected_delivery_option,
                'bulk_option' => $cartItem->selected_bulk_option,
            ]);

            // Save encrypted message if exists
            if ($cartItem->encrypted_message) {
                $order->encrypted_message = $cartItem->encrypted_message;
                $order->save();
            }
        }

        return $order;
    }
}

/**
 * OrderItem model represents individual items within an order.
 */
class OrderItem extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'product_description',
        'price',
        'quantity',
        'measurement_unit',
        'delivery_option',
        'bulk_option',
        'delivery_text'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'delivery_option' => 'array',
        'bulk_option' => 'array'
    ];

    /**
     * Boot function from Laravel.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {
            // Set UUID if not set
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the order that owns this item.
     */
    public function order()
    {
        return $this->belongsTo(Orders::class, 'order_id');
    }

    /**
     * Get the original product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Calculate total price for this item.
     */
    public function getTotalPrice()
    {
        // Base price multiplied by quantity
        $basePrice = $this->price * $this->quantity;
        
        // Add delivery option price if set
        $deliveryPrice = 0;
        if (isset($this->delivery_option['price'])) {
            $deliveryPrice = (float) $this->delivery_option['price'];
        }
        
        return $basePrice + $deliveryPrice;
    }

    /**
     * Get formatted delivery option.
     */
    public function getFormattedDeliveryOption()
    {
        if (!$this->delivery_option) {
            return null;
        }

        return [
            'description' => $this->delivery_option['description'] ?? 'N/A',
            'price' => isset($this->delivery_option['price']) 
                ? '$' . number_format($this->delivery_option['price'], 2) 
                : 'N/A'
        ];
    }

    /**
     * Get formatted bulk option.
     */
    public function getFormattedBulkOption()
    {
        if (!$this->bulk_option) {
            return null;
        }

        $unit = $this->measurement_unit;
        $formattedUnit = Product::getMeasurementUnits()[$unit] ?? $unit;

        return [
            'amount' => $this->bulk_option['amount'] ?? 0,
            'price' => isset($this->bulk_option['price']) 
                ? '$' . number_format($this->bulk_option['price'], 2) 
                : 'N/A',
            'display_text' => sprintf('%s %s for $%s',
                number_format($this->bulk_option['amount'] ?? 0),
                $formattedUnit,
                number_format($this->bulk_option['price'] ?? 0, 2)
            )
        ];
    }
}
