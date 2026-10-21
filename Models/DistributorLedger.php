<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class DistributorLedger extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'ledger_id';

    // 流水类型
    public const TYPE_COMMISSION = 'commission';       // 佣金入账（结算）

    public const TYPE_WITHDRAW = 'withdraw';           // 提现扣减

    public const TYPE_WITHDRAW_RETURN = 'withdraw_return'; // 提现驳回退回

    public const TYPE_ADJUST = 'adjust';               // 人工调整

    protected $fillable = [
        'tenant_id', 'distributor_id', 'type', 'amount', 'balance_after',
        'related_type', 'related_id', 'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id', 'distributor_id');
    }
}
