<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class Distributor extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;
    use SerializesFriendlyDates;

    protected $primaryKey = 'distributor_id';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FROZEN = 'frozen';

    protected $fillable = [
        'tenant_id', 'user_id', 'name', 'avatar', 'phone',
        'level', 'invite_code', 'parent_id',
        'total_commission', 'available_commission', 'frozen_commission',
        'subordinate_count', 'status', 'audit_remark', 'applied_at', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'total_commission' => 'integer',
            'available_commission' => 'integer',
            'frozen_commission' => 'integer',
            'subordinate_count' => 'integer',
            'applied_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'distributor_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'distributor_id');
    }

    public function commissionRecords(): HasMany
    {
        return $this->hasMany(CommissionRecord::class, 'distributor_id', 'distributor_id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class, 'distributor_id', 'distributor_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * 生成租户内唯一邀请码
     */
    public static function generateInviteCode(int $tenantId): string
    {
        do {
            $code = strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8));
        } while (static::where('tenant_id', $tenantId)->where('invite_code', $code)->exists());

        return $code;
    }
}
