<?php

namespace App\Services\Monero;

use App\Models\EscrowTransfer;
use App\Models\ReturnAddress;
use App\Support\Xmr;
use Illuminate\Support\Facades\Log;

/**
 * Sends scheduled escrow transfers without ever paying twice.
 *
 * A transfer is signed first (prepareTransfer, not broadcast) and its tx hash
 * and metadata are saved. Only then is it broadcast (relay). If anything fails
 * after the save, the next run checks whether the wallet knows that tx hash and
 * otherwise relays the very same signed transaction again. A second, different
 * transaction for the same transfer is never built automatically; see
 * escrow:reprepare for the manual escape hatch.
 *
 * Transfers are never sent partially: when the unlocked balance is too low the
 * transfer waits for the next run.
 */
class EscrowTransferProcessor
{
    public function __construct(private MoneroWallet $wallet)
    {
    }

    /**
     * Process every outstanding transfer once.
     *
     * @return array{sent: int, waiting: int}
     */
    public function processOutstanding(): array
    {
        $sent = 0;
        $waiting = 0;

        EscrowTransfer::outstanding()
            ->orderBy('created_at')
            ->each(function (EscrowTransfer $transfer) use (&$sent, &$waiting) {
                $this->process($transfer) ? $sent++ : $waiting++;
            });

        return ['sent' => $sent, 'waiting' => $waiting];
    }

    /**
     * Try to move one transfer forward. Returns true once it is sent.
     */
    public function process(EscrowTransfer $transfer): bool
    {
        if (!$this->claim($transfer)) {
            return false;
        }

        try {
            $transfer->refresh();

            return $this->advance($transfer);
        } catch (\Throwable $e) {
            $transfer->attempts++;
            $transfer->last_error = $e->getMessage();
            $transfer->save();

            Log::error('Escrow transfer failed', [
                'transfer' => $transfer->id,
                'order' => $transfer->order_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            EscrowTransfer::whereKey($transfer->getKey())->update(['locked_until' => null]);
        }
    }

    /**
     * Take an exclusive lease on the transfer so that two workers cannot
     * process it at the same time.
     */
    private function claim(EscrowTransfer $transfer): bool
    {
        $seconds = (int) config('monero.transfer_lock_seconds', 120);

        return EscrowTransfer::whereKey($transfer->getKey())
            ->outstanding()
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<', now());
            })
            ->update(['locked_until' => now()->addSeconds($seconds)]) === 1;
    }

    private function advance(EscrowTransfer $transfer): bool
    {
        if ($transfer->status === EscrowTransfer::STATUS_PROCESSING) {
            return $this->relay($transfer);
        }

        if (empty($transfer->address)) {
            $address = ReturnAddress::where('user_id', $transfer->recipient_id)
                ->inRandomOrder()
                ->value('monero_address');

            if (!$address) {
                $transfer->status = EscrowTransfer::STATUS_AWAITING_ADDRESS;
                $transfer->last_error = 'Recipient has no return address.';
                $transfer->save();

                return false;
            }

            $transfer->address = $address;
            $transfer->status = EscrowTransfer::STATUS_PENDING;
            $transfer->save();
        }

        $amount = Xmr::toAtomic($transfer->amount_xmr);
        $needed = bcadd($amount, Xmr::toAtomic((string) config('monero.payout_fee_reserve', '0.01')));

        if (bccomp($this->wallet->unlockedBalance(), $needed) < 0) {
            $transfer->last_error = 'Waiting for enough unlocked balance.';
            $transfer->save();

            return false;
        }

        $prepared = $this->wallet->prepareTransfer($transfer->address, $amount);

        // Persist the signed transaction before broadcasting it.
        $transfer->status = EscrowTransfer::STATUS_PROCESSING;
        $transfer->tx_hash = $prepared['tx_hash'];
        $transfer->tx_metadata = $prepared['tx_metadata'];
        $transfer->attempts++;
        $transfer->last_error = null;
        $transfer->save();

        return $this->relay($transfer);
    }

    private function relay(EscrowTransfer $transfer): bool
    {
        if ($transfer->tx_hash && $this->wallet->hasOutgoingTransaction($transfer->tx_hash)) {
            return $this->markSent($transfer);
        }

        try {
            $this->wallet->relay($transfer->tx_metadata);
        } catch (\Throwable $e) {
            // The broadcast may have gone through even though the call failed.
            if ($this->wallet->hasOutgoingTransaction($transfer->tx_hash)) {
                return $this->markSent($transfer);
            }

            $transfer->attempts++;
            $transfer->last_error = 'Relay failed: ' . $e->getMessage();
            $transfer->save();

            Log::warning('Escrow transfer relay failed, will retry the same transaction', [
                'transfer' => $transfer->id,
                'tx_hash' => $transfer->tx_hash,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $this->markSent($transfer);
    }

    private function markSent(EscrowTransfer $transfer): bool
    {
        $transfer->status = EscrowTransfer::STATUS_SENT;
        $transfer->sent_at = now();
        $transfer->tx_metadata = null;
        $transfer->last_error = null;
        $transfer->save();

        $this->syncOrderSummary($transfer);

        Log::info('Escrow transfer sent', [
            'transfer' => $transfer->id,
            'order' => $transfer->order_id,
            'type' => $transfer->type,
            'amount' => $transfer->amount_xmr,
            'tx_hash' => $transfer->tx_hash,
        ]);

        return true;
    }

    /**
     * Keep the summary columns on orders (shown in the views) in step with the
     * ledger.
     */
    private function syncOrderSummary(EscrowTransfer $transfer): void
    {
        $order = $transfer->order;

        $sent = $order->escrowTransfers()
            ->where('type', $transfer->type)
            ->where('status', EscrowTransfer::STATUS_SENT);

        $total = Xmr::of($sent->sum('amount_xmr'));

        if ($transfer->type === EscrowTransfer::TYPE_VENDOR_PAYOUT) {
            $order->vendor_payment_amount = $total;
            $order->vendor_payment_address = $transfer->address;
            $order->vendor_payment_at = $transfer->sent_at;
        } else {
            $order->buyer_refund_amount = $total;
            $order->buyer_refund_address = $transfer->address;
            $order->buyer_refund_at = $transfer->sent_at;
        }

        $order->save();
    }
}
