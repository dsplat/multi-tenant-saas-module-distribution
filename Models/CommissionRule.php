<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class CommissionRule extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;
    use SerializesFriendlyDates;

    protected $primaryKey = 'commission_rule_id';

    public const CALC_RATE = 'rate';

    public const CALC_FIXED = 'fixed';

    protected $fillable = [
        'tenant_id', 'name', 'level', 'calc_type', 'rate', 'fixed_amount',
        'min_amount', 'max_amount', 'apply_scope', 'scope_config',
        'priority', 'status', 'description',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'rate' => 'decimal:2',
            'fixed_amount' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'scope_config' => 'array',
            'priority' => 'integer',
        ];
    }

    public function isEnabled(): bool
    {
        return $this->status === 'enabled';
    }

    /**
     * 按订单金额计算佣金
     */
    public function calculate(int $orderAmountFen): int
    {
        $commission = $this->calc_type === self::CALC_FIXED
            ? (int) $this->fixed_amount
            : (int) round($orderAmountFen * ((float) $this->rate / 100));

        // 上下限约束（单位分；max_amount > 0 时才封顶）
        if ((int) $this->min_amount > 0 && $commission < (int) $this->min_amount) {
            $commission = (int) $this->min_amount;
        }
        if ((int) $this->max_amount > 0 && $commission > (int) $this->max_amount) {
            $commission = (int) $this->max_amount;
        }

        return $commission;
    }
}
