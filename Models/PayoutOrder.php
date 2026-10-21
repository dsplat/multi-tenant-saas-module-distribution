<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class PayoutOrder extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'payout_order_id';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'withdrawal_id', 'channel', 'amount', 'account_info',
        'trade_no', 'channel_trade_no', 'status', 'fail_reason', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'account_info' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class, 'withdrawal_id', 'withdrawal_id');
    }

    /**
     * 生成本地打款流水号
     */
    public static function generateTradeNo(): string
    {
        return 'PO' . date('YmdHis') . strtoupper(substr(uniqid(), -6));
    }
}
