<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Distribution\Models\PayoutConfig;
use MultiTenantSaas\Modules\Distribution\Models\PayoutOrder;
use MultiTenantSaas\Modules\Distribution\Services\WithdrawalService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 按原商户单号对账处理中的打款单。
 *
 * 自动渠道受理/超时后提现单停在 processing；本命令调用驱动 query() 确认终态：
 * success → 提现 paid；failed → 提现 failed 并退余额；processing/unknown → 保持。
 * 建议由下游调度器每 5~10 分钟执行一次（模块不强行注册 schedule）。
 */
class ReconcilePayoutsCommand extends Command
{
    protected $signature = 'distribution:reconcile-payouts
        {--tenant= : 只处理指定租户 ID}
        {--limit=100 : 每个租户单次最多处理多少笔}
        {--min-age=5 : 只处理创建超过 N 分钟的打款单，避免与在途请求竞争}';

    protected $description = '查询处理中的自动打款单终态，确认后回写提现单';

    public function handle(WithdrawalService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subMinutes(max(0, (int) $this->option('min-age')));
        // 只对账「已发送/已受理」的单；pending 是尚未发出（或发送前中断），
        // 由 payout() 以原 trade_no 重提，绝不能被对账先改成 processing 后失去发送路径。
        $statuses = [PayoutOrder::STATUS_PROCESSING];

        $tenantOption = $this->option('tenant');
        $tenantIds = $tenantOption !== null && $tenantOption !== ''
            ? [(int) $tenantOption]
            : TenantScope::allowUnscoped(fn () => PayoutOrder::query()
                ->whereIn('status', $statuses)
                ->where('channel', '!=', PayoutConfig::CHANNEL_MANUAL)
                ->where('created_at', '<=', $cutoff)
                ->where(function ($query) {
                    $query->whereNull('next_reconcile_at')
                        ->orWhere('next_reconcile_at', '<=', now());
                })
                ->distinct()
                ->pluck('tenant_id')
                ->map(fn ($id): int => (int) $id)
                ->all());

        $processed = 0;
        $failed = 0;

        try {
            foreach ($tenantIds as $tenantId) {
                TenantContext::setTenantId((string) $tenantId);

                $orders = PayoutOrder::where('tenant_id', $tenantId)
                    ->whereIn('status', $statuses)
                    ->where('channel', '!=', PayoutConfig::CHANNEL_MANUAL)
                    ->where('created_at', '<=', $cutoff)
                    ->where(function ($query) {
                        $query->whereNull('next_reconcile_at')
                            ->orWhere('next_reconcile_at', '<=', now());
                    })
                    ->orderBy('next_reconcile_at')
                    ->orderBy('payout_order_id')
                    ->limit($limit)
                    ->get();

                foreach ($orders as $order) {
                    $processed++;

                    try {
                        $service->reconcile($tenantId, (int) $order->payout_order_id);
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error('Distribution: payout reconcile failed', [
                            'tenant_id' => $tenantId,
                            'payout_order_id' => $order->payout_order_id,
                            'trade_no' => $order->trade_no,
                            'error' => $e->getMessage(),
                        ]);
                        $this->error("reconcile failed: tenant={$tenantId} payout_order={$order->payout_order_id} {$e->getMessage()}");
                    }
                }
            }
        } finally {
            TenantContext::clear();
        }

        $this->info("distribution:reconcile-payouts processed={$processed} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
