<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Listeners;

use MultiTenantSaas\Modules\Distribution\Services\CommissionEngine;
use MultiTenantSaas\Modules\Order\Events\OrderPaid;

/**
 * 订单支付成功 → 分销计佣
 *
 * 由 DistributionServiceProvider 在 Order 模块可用时注册（class_exists 守卫）。
 * 同步执行：无规则/无分销员时为空操作。
 */
class OrderPaidCommissionListener
{
    public function __construct(
        protected CommissionEngine $engine,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $this->engine->handleOrderPaid($event);
    }
}
