<?php

namespace App\Services\Monero;

use MoneroIntegrations\MoneroPhp\jsonRPCClient;

/**
 * MoneroWallet backed by monero-wallet-rpc.
 *
 * Talks JSON-RPC directly (through the monerophp client) because the
 * walletRPC wrapper cannot request tx metadata for a delayed relay and its
 * relay_tx() method is broken.
 */
class RpcMoneroWallet implements MoneroWallet
{
    private jsonRPCClient $client;

    public function __construct(
        string $host,
        int $port,
        bool $ssl,
        ?string $user = null,
        ?string $password = null,
        private int $accountIndex = 0,
    ) {
        $protocol = $ssl ? 'https' : 'http';
        $this->client = new jsonRPCClient("{$protocol}://{$host}:{$port}/", $user, $password, $ssl);
    }

    public function createSubaddress(string $label): array
    {
        $result = $this->call('create_address', [
            'account_index' => $this->accountIndex,
            'label' => $label,
        ]);

        return [
            'address' => $result['address'],
            'index' => (int) $result['address_index'],
        ];
    }

    public function incomingTransfers(int $subaddressIndex): array
    {
        $result = $this->call('get_transfers', [
            'in' => true,
            'pool' => true,
            'account_index' => $this->accountIndex,
            'subaddr_indices' => [$subaddressIndex],
        ]);

        $transfers = [];
        foreach (['in' => false, 'pool' => true] as $key => $inPool) {
            foreach ($result[$key] ?? [] as $transfer) {
                $transfers[] = [
                    'txid' => (string) $transfer['txid'],
                    'amount' => (string) $transfer['amount'],
                    'confirmations' => $inPool ? 0 : (int) ($transfer['confirmations'] ?? 0),
                    'in_pool' => $inPool,
                ];
            }
        }

        return $transfers;
    }

    public function unlockedBalance(): string
    {
        $result = $this->call('get_balance', ['account_index' => $this->accountIndex]);

        return (string) $result['unlocked_balance'];
    }

    public function prepareTransfer(string $address, string $atomicAmount): array
    {
        $result = $this->call('transfer', [
            'destinations' => [['address' => $address, 'amount' => (int) $atomicAmount]],
            'account_index' => $this->accountIndex,
            'priority' => 1,
            'do_not_relay' => true,
            'get_tx_hex' => false,
            'get_tx_metadata' => true,
        ]);

        return [
            'tx_hash' => (string) $result['tx_hash'],
            'tx_metadata' => (string) $result['tx_metadata'],
        ];
    }

    public function relay(string $txMetadata): string
    {
        $result = $this->call('relay_tx', ['hex' => $txMetadata]);

        return (string) $result['tx_hash'];
    }

    public function hasOutgoingTransaction(string $txHash): bool
    {
        try {
            $result = $this->call('get_transfer_by_txid', [
                'txid' => $txHash,
                'account_index' => $this->accountIndex,
            ]);
        } catch (\RuntimeException $e) {
            // The wallet answers with an RPC error when it does not know the txid.
            return false;
        }

        $transfer = $result['transfer'] ?? null;

        return $transfer !== null && in_array($transfer['type'] ?? null, ['out', 'pending', 'pool'], true);
    }

    private function call(string $method, array $params): array
    {
        $result = $this->client->_run($method, $params, 'json_rpc');

        return is_array($result) ? $result : [];
    }
}
