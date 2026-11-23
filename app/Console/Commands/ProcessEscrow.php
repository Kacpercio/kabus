<?php

namespace App\Console\Commands;

use App\Models\EscrowTransfer;
use App\Models\Orders;
use App\Services\Monero\EscrowTransferProcessor;
use App\Services\Monero\MoneroWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Drives the escrow. Scheduled every minute in routes/console.php.
 *
 * 1. Reads incoming payments of open orders (and of finished orders inside the
 *    late payment window) and cancels expired, unpaid orders.
 * 2. Applies the 96 h auto-cancel and 192 h auto-complete rules.
 * 3. Sends scheduled vendor payouts and buyer refunds.
 */
class ProcessEscrow extends Command
{
    protected $signature = 'escrow:process';

    protected $description = 'Check order payments, apply automatic order status changes and send escrow payouts and refunds';

    public function handle(MoneroWallet $wallet, EscrowTransferProcessor $processor): int
    {
        $this->checkPayments($wallet);
        $this->expireOrdersWithoutAddress();

        $auto = Orders::processAllAutoStatusChanges();
        $this->line("Auto-cancelled: {$auto['cancelled']}, auto-completed: {$auto['completed']}");

        $transfers = $processor->processOutstanding();
        $this->line("Transfers sent: {$transfers['sent']}, still outstanding: {$transfers['waiting']}");

        $stuck = EscrowTransfer::outstanding()->where('attempts', '>=', 5)->count();
        if ($stuck > 0) {
            $this->warn("{$stuck} transfer(s) failed 5 or more times and need attention (see last_error in escrow_transfers).");
            Log::warning('Escrow transfers need attention', ['count' => $stuck]);
        }

        return self::SUCCESS;
    }

    private function checkPayments(MoneroWallet $wallet): void
    {
        $cutoff = now()->subHours((int) config('monero.late_payment_window_hours', 168));

        Orders::whereNotNull('payment_address')
            ->where(function ($query) use ($cutoff) {
                $query->whereNotIn('status', [Orders::STATUS_COMPLETED, Orders::STATUS_CANCELLED])
                    ->orWhere('completed_at', '>=', $cutoff)
                    ->orWhere('cancelled_at', '>=', $cutoff)
                    // Orders cancelled before the cancelled_at column existed.
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->where('status', Orders::STATUS_CANCELLED)
                            ->whereNull('cancelled_at')
                            ->where('updated_at', '>=', $cutoff);
                    });
            })
            ->each(function (Orders $order) use ($wallet) {
                try {
                    $order->checkPayments($wallet);

                    if ($order->status === Orders::STATUS_WAITING_PAYMENT) {
                        $order->expireIfUnpaid();
                    }
                } catch (\Throwable $e) {
                    Log::error('Escrow payment check failed', [
                        'order' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    /**
     * Orders whose buyer never opened the payment page have no address and
     * therefore no expiry. Nothing can have been paid, so cancel them once the
     * normal payment window has passed.
     */
    private function expireOrdersWithoutAddress(): void
    {
        $minutes = (int) config('monero.address_expiration_time', 1440);

        Orders::where('status', Orders::STATUS_WAITING_PAYMENT)
            ->whereNull('payment_address')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->each(function (Orders $order) {
                $order->markAsCancelled();
            });
    }
}
