<?php

namespace Tests\Support;

use App\Services\Monero\MoneroWallet;
use App\Support\Xmr;

/**
 * In-memory monero-wallet-rpc for tests.
 */
class FakeMoneroWallet implements MoneroWallet
{
    /** @var array<int, list<array{txid: string, amount: string, confirmations: int, in_pool: bool}>> */
    public array $incoming = [];

    public string $unlockedBalance = '100000000000000'; // 100 XMR

    /** @var array<string, array{address: string, amount: string}> */
    public array $prepared = [];

    /** @var list<array{tx_hash: string, address: string, amount: string}> */
    public array $relayed = [];

    /** Relay calls that should throw before reaching the network. */
    public int $failRelays = 0;

    /** Relay calls that broadcast but then throw (lost response). */
    public int $relayThenThrow = 0;

    private int $nextIndex = 1;
    private int $nextTx = 1;

    public function createSubaddress(string $label): array
    {
        $index = $this->nextIndex++;

        return ['address' => "8FakeSubaddress{$index}", 'index' => $index];
    }

    /** Simulate an incoming payment. */
    public function receive(int $subaddressIndex, string $xmr, int $confirmations = 20): void
    {
        $this->incoming[$subaddressIndex][] = [
            'txid' => 'in' . count($this->incoming[$subaddressIndex] ?? []) . '-' . $subaddressIndex,
            'amount' => Xmr::toAtomic($xmr),
            'confirmations' => $confirmations,
            'in_pool' => $confirmations === 0,
        ];
    }

    public function incomingTransfers(int $subaddressIndex): array
    {
        return $this->incoming[$subaddressIndex] ?? [];
    }

    public function unlockedBalance(): string
    {
        return $this->unlockedBalance;
    }

    public function prepareTransfer(string $address, string $atomicAmount): array
    {
        $hash = 'tx' . $this->nextTx++;
        $this->prepared[$hash] = ['address' => $address, 'amount' => $atomicAmount];

        return ['tx_hash' => $hash, 'tx_metadata' => 'meta:' . $hash];
    }

    public function relay(string $txMetadata): string
    {
        if ($this->failRelays > 0) {
            $this->failRelays--;
            throw new \RuntimeException('daemon unavailable');
        }

        $hash = substr($txMetadata, strlen('meta:'));

        if (!$this->hasOutgoingTransaction($hash)) {
            $this->relayed[] = ['tx_hash' => $hash] + $this->prepared[$hash];
            $this->unlockedBalance = bcsub($this->unlockedBalance, $this->prepared[$hash]['amount']);
        }

        if ($this->relayThenThrow > 0) {
            $this->relayThenThrow--;
            throw new \RuntimeException('timeout');
        }

        return $hash;
    }

    public function hasOutgoingTransaction(string $txHash): bool
    {
        foreach ($this->relayed as $tx) {
            if ($tx['tx_hash'] === $txHash) {
                return true;
            }
        }

        return false;
    }

    /** Total XMR sent to an address. */
    public function sentTo(string $address): string
    {
        $atomic = '0';
        foreach ($this->relayed as $tx) {
            if ($tx['address'] === $address) {
                $atomic = bcadd($atomic, $tx['amount']);
            }
        }

        return Xmr::fromAtomic($atomic);
    }
}
