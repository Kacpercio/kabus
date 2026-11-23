<?php

namespace App\Console\Commands;

use App\Models\EscrowTransfer;
use App\Services\Monero\MoneroWallet;
use Illuminate\Console\Command;

/**
 * Manual escape hatch for a transfer whose signed transaction can no longer be
 * broadcast (for example because its inputs were spent by another
 * transaction). Discards the signed transaction so the next escrow:process run
 * builds a new one.
 *
 * Only run this after checking in the wallet that the old transaction was
 * really not sent, otherwise the recipient is paid twice.
 */
class ReprepareEscrowTransfer extends Command
{
    protected $signature = 'escrow:reprepare {transfer : ID of the escrow transfer}';

    protected $description = 'Discard the signed transaction of a stuck escrow transfer so it is rebuilt (check the wallet first)';

    public function handle(MoneroWallet $wallet): int
    {
        $transfer = EscrowTransfer::find($this->argument('transfer'));

        if (!$transfer) {
            $this->error('Transfer not found.');

            return self::FAILURE;
        }

        if ($transfer->status !== EscrowTransfer::STATUS_PROCESSING) {
            $this->error("Transfer is {$transfer->status}; only processing transfers can be re-prepared.");

            return self::FAILURE;
        }

        if ($transfer->tx_hash && $wallet->hasOutgoingTransaction($transfer->tx_hash)) {
            $this->error("The wallet knows transaction {$transfer->tx_hash}: it was sent. Not re-preparing.");

            return self::FAILURE;
        }

        $this->line("Order: {$transfer->order_id}");
        $this->line("Amount: {$transfer->amount_xmr} XMR to {$transfer->address}");
        $this->line("Old tx hash: {$transfer->tx_hash}");
        $this->line("Last error: {$transfer->last_error}");

        if (!$this->confirm('Confirm that this transaction is not in the wallet or on the blockchain and should be rebuilt')) {
            return self::FAILURE;
        }

        $transfer->update([
            'status' => EscrowTransfer::STATUS_PENDING,
            'tx_hash' => null,
            'tx_metadata' => null,
            'attempts' => 0,
            'last_error' => 'Re-prepared manually; old tx hash ' . $transfer->tx_hash,
        ]);

        $this->info('Transfer reset; escrow:process will build a new transaction.');

        return self::SUCCESS;
    }
}
