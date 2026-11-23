<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One outgoing escrow movement: a vendor payout or a buyer refund.
 *
 * Lifecycle:
 *   pending / awaiting_address -> processing (signed, tx_hash stored) -> sent
 *
 * The transaction is signed and its hash stored before it is broadcast, so
 * after a crash or timeout the processor can check whether that exact
 * transaction went out instead of building a second one.
 */
class EscrowTransfer extends Model
{
    use HasUuids;

    public const TYPE_VENDOR_PAYOUT = 'vendor_payout';
    public const TYPE_BUYER_REFUND = 'buyer_refund';

    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_ADDRESS = 'awaiting_address';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';

    protected $fillable = [
        'order_id',
        'type',
        'recipient_id',
        'gross_amount_xmr',
        'amount_xmr',
        'address',
        'status',
        'tx_hash',
        'tx_metadata',
        'attempts',
        'last_error',
        'locked_until',
        'sent_at',
    ];

    protected $hidden = [
        'tx_metadata',
    ];

    protected $casts = [
        'gross_amount_xmr' => 'decimal:12',
        'amount_xmr' => 'decimal:12',
        'attempts' => 'integer',
        'locked_until' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Orders::class, 'order_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /** Transfers the processor still has to work on. */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_AWAITING_ADDRESS,
            self::STATUS_PROCESSING,
        ]);
    }
}
