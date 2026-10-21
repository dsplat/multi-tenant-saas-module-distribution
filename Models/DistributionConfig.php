<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class DistributionConfig extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'distribution_config_id';

    public const AUDIT_AUTO = 'auto';

    public const AUDIT_MANUAL = 'manual';

    protected $fillable = [
        'tenant_id', 'enabled', 'max_level', 'self_buy_rebate',
        'audit_mode', 'settle_days', 'lock_days',
        'withdraw_min_amount', 'withdraw_fee_rate',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_level' => 'integer',
            'self_buy_rebate' => 'boolean',
            'settle_days' => 'integer',
            'lock_days' => 'integer',
            'withdraw_min_amount' => 'integer',
            'withdraw_fee_rate' => 'decimal:2',
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->enabled;
    }
}
