<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Contracts;

use MultiTenantSaas\Modules\Distribution\Models\PayoutConfig;
use MultiTenantSaas\Modules\Distribution\Models\PayoutOrder;

/**
 * 打款终态查询扩展契约
 *
 * `PayoutDriverContract::transfer()` 的返回值只代表「渠道是否受理」，
 * 不代表已到账。支持查询的自动渠道实现本契约，由提现服务按原商户单号
 * 复核终态后才结算或退回余额；未实现本契约的驱动保持处理中，等待人工确认。
 *
 * state 取值：
 * - success    渠道明确成功（可置 paid）
 * - failed     渠道明确失败（可退回余额）
 * - processing 渠道仍在处理中（保持 processing，不得退款）
 * - unknown    查询失败/网络异常（保持 processing，不得退款）
 */
interface PayoutStatusQueryContract
{
    /**
     * @return array{state: string, channel_trade_no: ?string, fail_reason: ?string}
     */
    public function query(PayoutOrder $order, PayoutConfig $config): array;
}
