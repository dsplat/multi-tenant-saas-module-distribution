<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class CommissionRecord extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'commission_record_id';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'distributor_id', 'order_type', 'order_id', 'order_no',
        'buyer_user_id', 'level', 'order_amount', 'commission_amount',
        'rule_id', 'status', 'settled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'order_amount' => 'integer',
            'commission_amount' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id', 'distributor_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
