<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Distribution\Models\DistributionRelation;
use MultiTenantSaas\Modules\Distribution\Models\Distributor;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 分销关系链服务
 *
 * 职责：
 * 1. 客户-分销员归因绑定（含锁定期保护，锁定内不可被抢绑）
 * 2. 分销员上级链解析（合规锁定 2 级：直接上级 + 间接上级）
 * 3. 团队结构（直接/间接下级树）
 * 4. 分销员生命周期（申请、审核、冻结、统计）
 */
class DistributionRelationService
{
    public function __construct(
        protected DistributionConfigService $configService,
    ) {}

    // ========== 客户归因绑定 ==========

    /**
     * 绑定客户到分销员（首次绑定或锁定期外可重绑）
     *
     * @return DistributionRelation 绑定后的关系
     */
    public function bindCustomer(int $tenantId, int $userId, int $distributorId, ?string $source = null): DistributionRelation
    {
        TenantContext::setTenantId((string) $tenantId);

        $distributor = Distributor::where('distributor_id', $distributorId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $distributor || ! $distributor->isActive()) {
            throw new UnprocessableEntityHttpException('Distributor not active');
        }

        // 不允许自己绑定自己（自购归因走 self_buy_rebate 配置）
        if ((int) $distributor->user_id === $userId) {
            throw new UnprocessableEntityHttpException('Cannot bind self');
        }

        $lockDays = (int) $this->configService->getConfig($tenantId)['lock_days'];

        $relation = DistributionRelation::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        // 锁定期保护：锁定未到期即**不可被抢绑**，归因保持不变。
        // 静默返回既有关系而不抛：调用方是扫码/邀请码/海报等触点流程，抢绑失败不是故障。
        // （此前这里用 firstOrNew()->fill()->save() 无条件覆盖 → 锁定期形同虚设，
        //   与类/方法注释「锁定内不可被抢绑」不符，见 backlog BL-S30。）
        if ($relation !== null && $relation->isLocked()) {
            return $relation;
        }

        $relation ??= new DistributionRelation;

        $relation->fill([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'distributor_id' => $distributorId,
            'source' => $source,
            'locked_until' => $lockDays > 0 ? now()->addDays($lockDays) : null,
        ]);

        $relation->save();

        return $relation;
    }

    /**
     * 查询客户归属的分销员（不存在返回 null）
     */
    public function getCustomerDistributor(int $tenantId, int $userId): ?Distributor
    {
        TenantContext::setTenantId((string) $tenantId);

        $relation = DistributionRelation::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if (! $relation) {
            return null;
        }

        return Distributor::where('distributor_id', $relation->distributor_id)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    // ========== 上级链解析（计佣核心） ==========

    /**
     * 获取买家的上级分销员链（用于多级计佣）
     *
     * 返回按层级排序的分销员数组：[1级=>直接上级, 2级=>间接上级]
     * 合规红线：最多返回 max_level（锁定 2）级。
     *
     * @param  int  $buyerUserId  买家用户(User)ID
     * @return array<int, Distributor> level => Distributor
     */
    public function getUplineChain(int $tenantId, int $buyerUserId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $maxLevel = (int) $this->configService->getConfig($tenantId)['max_level'];
        $maxLevel = min($maxLevel, 2); // 合规锁定

        $chain = [];

        // 1级：买家归属的分销员
        $first = $this->getCustomerDistributor($tenantId, $buyerUserId);
        if (! $first || ! $first->isActive()) {
            return $chain;
        }
        $chain[1] = $first;

        // 2级：直接上级的上级
        if ($maxLevel >= 2 && $first->parent_id) {
            $second = Distributor::where('distributor_id', $first->parent_id)
                ->where('tenant_id', $tenantId)
                ->first();
            if ($second && $second->isActive()) {
                $chain[2] = $second;
            }
        }

        return $chain;
    }

    // ========== 团队结构 ==========

    /**
     * 获取分销员的团队树（直接下级 + 间接下级，2 级）
     */
    public function getTeamTree(int $tenantId, int $distributorId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $direct = Distributor::where('tenant_id', $tenantId)
            ->where('parent_id', $distributorId)
            ->orderByDesc('created_at')
            ->orderByDesc('distributor_id')
            ->get();

        $tree = [];
        foreach ($direct as $member) {
            $indirect = Distributor::where('tenant_id', $tenantId)
                ->where('parent_id', $member->distributor_id)
                ->orderByDesc('created_at')
                ->orderByDesc('distributor_id')
                ->get();

            $tree[] = [
                'distributor' => $member,
                'children' => $indirect->values(),
            ];
        }

        return $tree;
    }

    // ========== 分销员生命周期 ==========

    /**
     * 申请成为分销员
     *
     * @param  array  $data  含 user_id/name/avatar/phone/parent_invite_code
     */
    public function apply(int $tenantId, array $data): Distributor
    {
        TenantContext::setTenantId((string) $tenantId);

        $config = $this->configService->getConfig($tenantId);
        if (! $config['enabled']) {
            throw new UnprocessableEntityHttpException('Distribution disabled');
        }

        // 已存在则直接返回（幂等）
        $existing = Distributor::where('tenant_id', $tenantId)
            ->when(! empty($data['user_id']), fn ($q) => $q->where('user_id', $data['user_id']))
            ->first();

        if ($existing) {
            return $existing;
        }

        // 解析上级（通过邀请码）
        $parentId = null;
        if (! empty($data['parent_invite_code'])) {
            $parent = Distributor::where('tenant_id', $tenantId)
                ->where('invite_code', $data['parent_invite_code'])
                ->where('status', Distributor::STATUS_ACTIVE)
                ->first();
            $parentId = $parent?->distributor_id;
        }

        $status = $config['audit_mode'] === 'auto'
            ? Distributor::STATUS_ACTIVE
            : Distributor::STATUS_PENDING;

        $distributor = Distributor::create([
            'tenant_id' => $tenantId,
            'user_id' => $data['user_id'] ?? null,
            'name' => $data['name'] ?? '分销员',
            'avatar' => $data['avatar'] ?? null,
            'phone' => $data['phone'] ?? null,
            'level' => 1,
            'invite_code' => Distributor::generateInviteCode($tenantId),
            'parent_id' => $parentId,
            'status' => $status,
            'applied_at' => now(),
            'approved_at' => $status === Distributor::STATUS_ACTIVE ? now() : null,
        ]);

        // 上级团队计数 +1
        if ($parentId) {
            Distributor::where('distributor_id', $parentId)->increment('subordinate_count');
        }

        return $distributor;
    }

    /**
     * 审核分销员（通过/驳回）
     */
    public function audit(int $tenantId, int $distributorId, bool $approved, ?string $remark = null): Distributor
    {
        TenantContext::setTenantId((string) $tenantId);

        $distributor = Distributor::where('distributor_id', $distributorId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $distributor->update([
            'status' => $approved ? Distributor::STATUS_ACTIVE : Distributor::STATUS_FROZEN,
            'audit_remark' => $remark,
            'approved_at' => $approved ? now() : $distributor->approved_at,
        ]);

        return $distributor->fresh();
    }

    /**
     * 冻结/解冻分销员
     */
    public function setStatus(int $tenantId, int $distributorId, string $status): Distributor
    {
        TenantContext::setTenantId((string) $tenantId);

        $distributor = Distributor::where('distributor_id', $distributorId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $distributor->update(['status' => $status]);

        return $distributor->fresh();
    }
}
