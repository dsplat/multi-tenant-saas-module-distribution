<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Contracts;

use MultiTenantSaas\Modules\Distribution\Models\PayoutConfig;
use MultiTenantSaas\Modules\Distribution\Models\PayoutOrder;

/**
 * 打款驱动契约
 *
 * 统一提现打款的渠道接口。每个渠道（手动/微信商家转账/支付宝转账）实现此契约，
 * 由 PayoutDriverResolver 根据 payout_configs.channel 选择对应驱动。
 *
 * 驱动模式对齐群机器人 GroupBotDriverContract。
 */
interface PayoutDriverContract
{
    /**
     * 获取驱动对应的打款渠道
     *
     * @return string 如 'manual', 'wechat_transfer', 'alipay_transfer'
     */
    public function getChannel(): string;

    /**
     * 是否自动打款（false 表示需人工线下处理）
     */
    public function isAutomatic(): bool;

    /**
     * 执行打款
     *
     * @param  PayoutOrder  $order  打款单（含 amount / account_info / trade_no）
     * @param  PayoutConfig  $config  租户打款配置（含商户密钥等）
     * @return array{success: bool, channel_trade_no: ?string, fail_reason: ?string}
     */
    public function transfer(PayoutOrder $order, PayoutConfig $config): array;
}
