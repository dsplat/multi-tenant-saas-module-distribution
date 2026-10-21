<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class PayoutConfig extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'payout_config_id';

    public const CHANNEL_MANUAL = 'manual';

    public const CHANNEL_WECHAT_TRANSFER = 'wechat_transfer';

    public const CHANNEL_ALIPAY_TRANSFER = 'alipay_transfer';

    protected $fillable = [
        'tenant_id', 'channel', 'merchant_id', 'serial_no',
        'private_key', 'apiv3_key', 'extra', 'status',
    ];

    protected $hidden = [
        'private_key', 'apiv3_key',
    ];

    protected function casts(): array
    {
        return [
            'extra' => 'array',
        ];
    }

    public function isAuto(): bool
    {
        return $this->status === 'enabled'
            && in_array($this->channel, [self::CHANNEL_WECHAT_TRANSFER, self::CHANNEL_ALIPAY_TRANSFER], true);
    }
}
