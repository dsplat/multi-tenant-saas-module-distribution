<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Channel\Events\LiveCodeScanned;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Distribution\Contracts\DistributorProfileContract;
use MultiTenantSaas\Modules\Distribution\Listeners\LiveCodeScanAttributionListener;
use MultiTenantSaas\Modules\Distribution\Listeners\OrderPaidCommissionListener;
use MultiTenantSaas\Modules\Distribution\Listeners\OrderRefundedCommissionListener;
use MultiTenantSaas\Modules\Distribution\Services\PayoutDriverResolver;
use MultiTenantSaas\Modules\Distribution\Services\UserDistributorProfileResolver;
use MultiTenantSaas\Modules\Order\Events\OrderPaid;
use MultiTenantSaas\Modules\Order\Events\OrderRefunded;
use MultiTenantSaas\Support\OptionalModule;

/**
 * 分销模块（分销员/佣金/提现/打款，自 scrm 物理归位）
 *
 * 零硬业务依赖：
 * - 申请人档案走 DistributorProfileContract，默认取框架 User，下游可覆盖绑定；
 * - 计佣/冲销/扫码归因在 Order/Channel 事件类存在时才注册监听器
 *   （extra.saas.dependencies 保持空，避免依赖模块未启用时把分销整体门控掉）。
 *
 * 覆写 loadModuleRoutes() 仅把基类写死的 api/v1 换成 config('distribution.route_prefix')，
 * 供下游沿用既有 api/v1/biz 契约。
 *
 * 下游接线注意：scrm 侧 EventServiceProvider 里同名的
 * CalculateCommissionListener / CancelCommissionListener / LiveCodeScanAttributionListener
 * 注册必须移除，否则事件会双重触发（计佣有唯一键幂等兜底，归因无）。
 */
class DistributionServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'distribution';

    private const MW_VERIFY_OPERATOR_TENANT = 'MultiTenantSaas\Modules\Infrastructure\Http\Middleware\VerifyOperatorTenant';

    private const MW_OPTIONAL_SANCTUM_AUTH = 'MultiTenantSaas\Modules\Infrastructure\Http\Middleware\OptionalSanctumAuth';

    protected function registerModuleBindings(): void
    {
        $this->app->bindIf(DistributorProfileContract::class, UserDistributorProfileResolver::class);
        $this->app->singleton(PayoutDriverResolver::class);
    }

    protected function bootModule(): void
    {
        $this->registerListeners();
    }

    private function registerListeners(): void
    {
        if (class_exists(OrderPaid::class)) {
            Event::listen(OrderPaid::class, OrderPaidCommissionListener::class);
        }

        if (class_exists(OrderRefunded::class)) {
            Event::listen(OrderRefunded::class, OrderRefundedCommissionListener::class);
        }

        if (class_exists(LiveCodeScanned::class)) {
            Event::listen(LiveCodeScanned::class, LiveCodeScanAttributionListener::class);
        }
    }

    protected function loadModuleRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        OptionalModule::ensure(self::MW_VERIFY_OPERATOR_TENANT, '分销模块路由加载', 'Infrastructure');

        $moduleDir = $this->getModulePath();
        $moduleGate = 'module.enabled:' . $this->moduleGateName();
        $prefix = (string) config('distribution.route_prefix', 'api/v1');

        $apiRoute = $moduleDir . '/Routes/api.php';
        if (file_exists($apiRoute)) {
            Route::middleware(['api', 'auth:sanctum', 'throttle:api', 'tenant.identify', self::MW_VERIFY_OPERATOR_TENANT, $moduleGate])
                ->prefix($prefix)
                ->group($apiRoute);
        }

        $optionalRoute = $moduleDir . '/Routes/optional.php';
        if (file_exists($optionalRoute)) {
            Route::middleware(['api', 'throttle:api', 'tenant.identify', self::MW_OPTIONAL_SANCTUM_AUTH, $moduleGate])
                ->prefix($prefix)
                ->group($optionalRoute);
        }
    }
}
