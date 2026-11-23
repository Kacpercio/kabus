<?php

namespace App\Services\Monero;

/**
 * The subset of monero-wallet-rpc that the escrow needs.
 *
 * All amounts are in atomic units (piconero) as strings, so callers never
 * touch floats.
 */
interface MoneroWallet
{
    /**
     * Create a new subaddress in account 0.
     *
     * @return array{address: string, index: int}
     */
    public function createSubaddress(string $label): array;

    /**
     * Incoming transfers (confirmed and in the mempool) to one subaddress.
     *
     * @return list<array{txid: string, amount: string, confirmations: int, in_pool: bool}>
     */
    public function incomingTransfers(int $subaddressIndex): array;

    /** Unlocked balance of account 0 in atomic units. */
    public function unlockedBalance(): string;

    /**
     * Build and sign a transfer without broadcasting it.
     *
     * @return array{tx_hash: string, tx_metadata: string}
     */
    public function prepareTransfer(string $address, string $atomicAmount): array;

    /**
     * Broadcast a transaction prepared with prepareTransfer(). Relaying the
     * same metadata twice is harmless: it is the same transaction.
     */
    public function relay(string $txMetadata): string;

    /** Whether the wallet knows an outgoing transaction with this hash. */
    public function hasOutgoingTransaction(string $txHash): bool;
}
