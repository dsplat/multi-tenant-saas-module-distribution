<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Distribution\Services\CommissionEngine;
use MultiTenantSaas\Modules\Order\Events\OrderRefunded;
use Throwable;

/**
 * 订单退款 → 冲销未结算佣金
 *
 * 由 DistributionServiceProvider 在 Order 模块可用时注册（class_exists 守卫）。
 * 冲销失败只记日志：退款主流程不可被分销侧异常阻断。
 */
class OrderRefundedCommissionListener
{
    public function __construct(
        protected CommissionEngine $engine,
    ) {}

    public function handle(OrderRefunded $event): void
    {
        try {
            $this->engine->cancelByOrder($event->tenantId, $event->orderType, $event->orderId);
        } catch (Throwable $e) {
            Log::error("Distribution: commission cancel failed, order={$event->orderNo}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
