<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class DistributionRelation extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'relation_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'distributor_id', 'source', 'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'locked_until' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id', 'distributor_id');
    }

    /**
     * 关系是否仍在锁定期内（不可被抢绑）
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
