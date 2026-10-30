<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class Withdrawal extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'withdrawal_id';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'distributor_id', 'amount', 'fee', 'actual_amount',
        'status', 'account_type', 'bank_name', 'bank_account', 'account_name',
        'remark', 'audit_remark', 'audited_at', 'paid_at', 'payout_order_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'actual_amount' => 'integer',
            'audited_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id', 'distributor_id');
    }

    public function payoutOrder(): BelongsTo
    {
        return $this->belongsTo(PayoutOrder::class, 'payout_order_id', 'payout_order_id');
    }
}
