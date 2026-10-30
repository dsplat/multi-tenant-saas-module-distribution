<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Channel\Events\LiveCodeScanned;
use MultiTenantSaas\Modules\Distribution\Models\DistributionRelation;
use MultiTenantSaas\Modules\Distribution\Services\DistributionRelationService;
use MultiTenantSaas\Scopes\TenantScope;
use Throwable;

/**
 * 活码扫码 → 分销首触归因（Distribution 侧监听）
 *
 * Channel 侧发 LiveCodeScanned 事件（顶层携带活码 metadata 里的 distributor_id）后
 * 由本监听器完成分销关系绑定。原为 Channel 的 WechatScanEventListener::bindAttribution
 * 内联逻辑，P2 Stage0 解耦后上移，**行为逐字保持一致**：
 * - 仅当 distributor_id > 0 且扫码人已反查到 user_id 时才尝试
 * - 首触归因：库中已有关系一律不覆盖（渠道归因惯例：扫码只认第一次；
 *   `bindCustomer` 自身的锁定期保护是「过期可重绑」的第二道，层级不同，二者不冲突）
 * - 绑定失败（分销员已冻结/自己扫自己的码）只记 info，不抛（业务上就是不绑，不是故障）
 */
class LiveCodeScanAttributionListener
{
    /**
     * 归因来源标记（DistributionRelation.source）
     */
    public const SOURCE = 'wechat_scan';

    public function __construct(
        protected DistributionRelationService $distribution,
    ) {}

    public function handle(LiveCodeScanned $event): void
    {
        $distributorId = (int) ($event->distributorId ?? 0);
        $userId = $event->scannerData['user_id'] ?? null;

        if ($userId === null || $distributorId <= 0) {
            return;
        }

        $userId = (int) $userId;
        $tenantId = (int) $event->tenantId;

        TenantContext::setTenantId((string) $tenantId);

        $exists = DistributionRelation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            $this->distribution->bindCustomer($tenantId, $userId, $distributorId, self::SOURCE);
        } catch (Throwable $e) {
            // 分销员已冻结/自己扫自己的码 → 业务上就是不绑，不是故障
            Log::info('[WechatScan] 分销归因未绑定', [
                'tenant_id' => $tenantId,
                'live_code_id' => $event->liveCodeId !== null ? (int) $event->liveCodeId : null,
                'user_id' => $userId,
                'distributor_id' => $distributorId,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
