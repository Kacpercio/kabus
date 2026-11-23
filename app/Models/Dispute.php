<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Dispute extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    // Dispute status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_VENDOR_PREVAILS = 'vendor_prevails';
    public const STATUS_BUYER_PREVAILS = 'buyer_prevails';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'order_id',
        'status',
        'reason',
        'resolved_at',
        'resolved_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /**
     * Boot function from Laravel.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {
            // Set 30-character alphanumeric string if not set
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Str::random(30);
            }
        });
    }

    /**
     * Get the order that owns the dispute.
     */
    public function order()
    {
        return $this->belongsTo(Orders::class, 'order_id');
    }

    /**
     * Get the admin user who resolved the dispute.
     */
    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Get the messages for this dispute.
     */
    public function messages()
    {
        return $this->hasMany(DisputeMessage::class, 'dispute_id')
            ->orderBy('created_at', 'asc');
    }

    /**
     * Get the formatted status.
     */
    public function getFormattedStatus()
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'Active Dispute',
            self::STATUS_VENDOR_PREVAILS => 'Vendor Prevails',
            self::STATUS_BUYER_PREVAILS => 'Buyer Prevails',
            default => 'Unknown Status'
        };
    }

    /**
     * Resolve the dispute with vendor prevailing: complete the order and pay
     * the vendor.
     */
    public function resolveVendorPrevails($adminId)
    {
        return $this->resolve(self::STATUS_VENDOR_PREVAILS, $adminId);
    }

    /**
     * Resolve the dispute with buyer prevailing: cancel the order and refund
     * the buyer.
     */
    public function resolveBuyerPrevails($adminId)
    {
        return $this->resolve(self::STATUS_BUYER_PREVAILS, $adminId);
    }

    /**
     * Resolve the dispute and settle its order in one transaction.
     *
     * The dispute is only marked resolved when the order was still disputed
     * and its status change succeeded, so a dispute can never be closed
     * without moving the money.
     */
    protected function resolve(string $outcome, $adminId): bool
    {
        $resolved = DB::transaction(function () use ($outcome, $adminId) {
            $dispute = static::whereKey($this->getKey())->lockForUpdate()->first();

            if (!$dispute || $dispute->status !== self::STATUS_ACTIVE) {
                return false;
            }

            $order = $dispute->order;
            $settled = $outcome === self::STATUS_VENDOR_PREVAILS
                ? $order->markAsCompleted(resolvingDispute: true)
                : $order->markAsCancelled(resolvingDispute: true);

            if (!$settled) {
                return false;
            }

            $dispute->status = $outcome;
            $dispute->resolved_at = now();
            $dispute->resolved_by = $adminId;
            $dispute->save();

            return true;
        });

        $this->refresh();

        return $resolved;
    }

    /**
     * Get all disputes for the admin.
     */
    public static function getAllDisputes()
    {
        return self::with(['order', 'order.user', 'order.vendor'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get active disputes for the admin.
     */
    public static function getActiveDisputes()
    {
        return self::where('status', self::STATUS_ACTIVE)
            ->with(['order', 'order.user', 'order.vendor'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get resolved disputes for the admin.
     */
    public static function getResolvedDisputes()
    {
        return self::whereIn('status', [self::STATUS_VENDOR_PREVAILS, self::STATUS_BUYER_PREVAILS])
            ->with(['order', 'order.user', 'order.vendor', 'resolver'])
            ->orderBy('resolved_at', 'desc')
            ->get();
    }

    /**
     * Get all disputes for a user (as buyer).
     */
    public static function getUserDisputes($userId)
    {
        return self::whereHas('order', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->with(['order', 'order.vendor'])
        ->orderBy('created_at', 'desc')
        ->get();
    }

    /**
     * Get all disputes for a vendor.
     */
    public static function getVendorDisputes($vendorId)
    {
        return self::whereHas('order', function ($query) use ($vendorId) {
            $query->where('vendor_id', $vendorId);
        })
        ->with(['order', 'order.user'])
        ->orderBy('created_at', 'desc')
        ->get();
    }
}

/**
 * DisputeMessage model represents individual messages within a dispute.
 */
class DisputeMessage extends Model
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
        'dispute_id',
        'user_id',
        'message',
    ];

    /**
     * Boot function from Laravel.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {
            // Set 30-character alphanumeric string if not set
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Str::random(30);
            }
        });
    }

    /**
     * Get the dispute that owns the message.
     */
    public function dispute()
    {
        return $this->belongsTo(Dispute::class, 'dispute_id');
    }

    /**
     * Get the user who sent the message.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Check if the message is from an admin.
     */
    public function isFromAdmin()
    {
        return $this->user && $this->user->hasRole('admin');
    }

    /**
     * Check if the message is from the buyer.
     */
    public function isFromBuyer()
    {
        if (!$this->user || !$this->dispute || !$this->dispute->order) {
            return false;
        }

        return $this->user->id === $this->dispute->order->user_id;
    }

    /**
     * Check if the message is from the vendor.
     */
    public function isFromVendor()
    {
        if (!$this->user || !$this->dispute || !$this->dispute->order) {
            return false;
        }

        return $this->user->id === $this->dispute->order->vendor_id;
    }

    /**
     * Get the message type for UI display.
     */
    public function getMessageType()
    {
        if ($this->isFromAdmin()) {
            return 'admin';
        } elseif ($this->isFromBuyer()) {
            return 'buyer';
        } elseif ($this->isFromVendor()) {
            return 'vendor';
        } else {
            return 'unknown';
        }
    }
}