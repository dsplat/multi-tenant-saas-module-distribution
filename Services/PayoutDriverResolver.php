<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use MultiTenantSaas\Modules\Distribution\Contracts\PayoutDriverContract;
use MultiTenantSaas\Modules\Distribution\Drivers\ManualPayoutDriver;
use MultiTenantSaas\Modules\Distribution\Drivers\WechatTransferDriver;
use MultiTenantSaas\Modules\Distribution\Models\PayoutConfig;

/**
 * 打款驱动解析器
 *
 * 注册并按渠道解析打款驱动（对齐 GroupBotDispatcher 模式）。
 * 未匹配渠道时回退到手动驱动，保证流程不中断。
 */
class PayoutDriverResolver
{
    /** @var array<string, PayoutDriverContract> */
    protected array $drivers = [];

    public function __construct()
    {
        $this->register(new ManualPayoutDriver);
        $this->register(new WechatTransferDriver);
    }

    public function register(PayoutDriverContract $driver): void
    {
        $this->drivers[$driver->getChannel()] = $driver;
    }

    /**
     * 按渠道解析驱动（未知渠道回退手动）
     */
    public function resolve(?string $channel): PayoutDriverContract
    {
        if ($channel && isset($this->drivers[$channel])) {
            return $this->drivers[$channel];
        }

        return $this->drivers[PayoutConfig::CHANNEL_MANUAL] ?? new ManualPayoutDriver;
    }
}
