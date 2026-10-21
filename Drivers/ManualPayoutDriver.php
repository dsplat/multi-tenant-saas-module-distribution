<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Drivers;

use MultiTenantSaas\Modules\Distribution\Contracts\PayoutDriverContract;
use MultiTenantSaas\Modules\Distribution\Models\PayoutConfig;
use MultiTenantSaas\Modules\Distribution\Models\PayoutOrder;

/**
 * 手动打款驱动（线下兜底）
 *
 * 不做任何线上转账，仅标记待人工处理。
 * 运营在后台确认线下打款完成后，手动将提现单置为已打款。
 */
class ManualPayoutDriver implements PayoutDriverContract
{
    public function getChannel(): string
    {
        return PayoutConfig::CHANNEL_MANUAL;
    }

    public function isAutomatic(): bool
    {
        return false;
    }

    public function transfer(PayoutOrder $order, PayoutConfig $config): array
    {
        // 手动渠道：保持 pending，等待运营线下打款后人工确认
        return [
            'success' => false,
            'channel_trade_no' => null,
            'fail_reason' => '等待人工线下打款',
        ];
    }
}
