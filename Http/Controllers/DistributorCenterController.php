<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Distribution\Contracts\DistributorProfileContract;
use MultiTenantSaas\Modules\Distribution\Models\CommissionRecord;
use MultiTenantSaas\Modules\Distribution\Models\Distributor;
use MultiTenantSaas\Modules\Distribution\Services\DistributionConfigService;
use MultiTenantSaas\Modules\Distribution\Services\DistributionRelationService;
use MultiTenantSaas\Modules\Distribution\Services\DistributorLedgerService;
use MultiTenantSaas\Modules\Distribution\Services\WithdrawalService;
use MultiTenantSaas\Modules\Distribution\Support\DistributorProfile;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * C 端分销员中心控制器（创业变现载体）
 *
 * 面向终端用户（H5），通过认证用户关联客户档案，再定位其分销员身份。
 * 仅提供查询/操作自己的分销数据，不暴露 distributorId 路径参数。
 */
class DistributorCenterController extends BaseController
{
    public function __construct(
        protected DistributionRelationService $relationService,
        protected DistributionConfigService $configService,
        protected DistributorLedgerService $ledgerService,
        protected WithdrawalService $withdrawalService,
        protected DistributorProfileContract $profiles,
    ) {}

    /**
     * 分销中心首页（身份 + 概览 + 配置）
     */
    public function profile(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $profile = $this->resolveProfile($request);
        $config = $this->configService->getConfig($tenantId);

        $distributor = $profile
            ? $this->relationService->getCustomerDistributor($tenantId, $profile->userId)
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => (bool) $config['enabled'],
                'isDistributor' => $distributor !== null,
                'distributor' => $distributor ? $this->transformProfile($distributor) : null,
                'config' => [
                    'maxLevel' => $config['max_level'],
                    'withdrawMinAmount' => $config['withdraw_min_amount'],
                    'withdrawFeeRate' => $config['withdraw_fee_rate'],
                    'settleDays' => $config['settle_days'],
                ],
            ],
        ]);
    }

    /**
     * 申请成为分销员
     */
    public function apply(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $profile = $this->resolveProfile($request);

        if (! $profile) {
            return response()->json(['success' => false, 'message' => '请先完善客户资料'], 422);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'parentInviteCode' => 'nullable|string|max:32',
        ]);

        $user = $request->user();

        $distributor = $this->relationService->apply($tenantId, [
            'user_id' => $user instanceof User ? $user->user_id : null,
            'name' => $validated['name'] ?? $profile->name,
            'phone' => $validated['phone'] ?? $profile->phone,
            'parent_invite_code' => $validated['parentInviteCode'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $this->transformProfile($distributor)], 201);
    }

    /**
     * 客户归因绑定（扫码海报/邀请链接进入后绑定到分销员）
     *
     * 由 H5 在登录后携带 QR 中的 ref（distributor_id）调用，
     * 建立客户-分销员归属关系，后续该客户下单即触发上级链计佣。
     * 幂等：锁定期内重复绑定不报错，返回既有关系。
     */
    public function bind(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $profile = $this->resolveProfile($request);

        if (! $profile) {
            return response()->json(['success' => false, 'message' => '请先完善客户资料'], 422);
        }

        $validated = $request->validate([
            'distributor_id' => 'required|integer',
            'source' => 'nullable|string|max:50',
        ]);

        try {
            $relation = $this->relationService->bindCustomer(
                $tenantId,
                $profile->userId,
                (int) $validated['distributor_id'],
                $validated['source'] ?? 'poster',
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'distributor_id' => (int) $relation->distributor_id,
                    'locked_until' => $relation->locked_until?->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (UnprocessableEntityHttpException $e) {
            // 分销员不存在/未激活/自绑等：静默降级，不阻断用户浏览
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * 我的邀请信息（邀请码 + 邀请链接）
     */
    public function invite(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $distributor = $this->resolveDistributor($request);

        if (! $distributor) {
            return response()->json(['success' => false, 'message' => '您还不是分销员'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'inviteCode' => $distributor->invite_code,
                'inviteUrl' => $this->buildInviteUrl($distributor->invite_code),
            ],
        ]);
    }

    /**
     * 我的团队（直接 + 间接下级）
     */
    public function team(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $distributor = $this->resolveDistributor($request);

        if (! $distributor) {
            return response()->json(['success' => true, 'data' => ['direct' => [], 'indirect' => 0]]);
        }

        $tree = $this->relationService->getTeamTree($tenantId, (int) $distributor->distributor_id);

        $direct = [];
        $indirect = 0;
        foreach ($tree as $item) {
            $direct[] = $this->transformMember($item['distributor']);
            foreach ($item['children'] as $child) {
                $indirect++;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'direct' => $direct,
                'indirectCount' => $indirect,
                'subordinateCount' => (int) $distributor->subordinate_count,
            ],
        ]);
    }

    /**
     * 我的佣金记录
     */
    public function commissions(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        TenantContext::setTenantId((string) $tenantId);

        $distributor = $this->resolveDistributor($request);

        if (! $distributor) {
            return response()->json(['success' => true, 'data' => [], 'total' => 0]);
        }

        $query = CommissionRecord::where('tenant_id', $tenantId)
            ->where('distributor_id', $distributor->distributor_id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->orderByDesc('created_at')
            ->orderByDesc('commission_record_id')
            ->paginate((int) $request->input('pageSize', 20));

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn ($r) => $this->transformCommission($r))->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    /**
     * 我的账本流水
     */
    public function ledger(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $distributor = $this->resolveDistributor($request);

        if (! $distributor) {
            return response()->json(['success' => true, 'data' => [], 'total' => 0]);
        }

        $result = $this->ledgerService->paginate($tenantId, (int) $distributor->distributor_id, (int) $request->input('pageSize', 20));

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    /**
     * 申请提现
     */
    public function requestWithdrawal(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $distributor = $this->resolveDistributor($request);

        if (! $distributor) {
            return response()->json(['success' => false, 'message' => '您还不是分销员'], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'accountType' => 'nullable|string|max:20',
            'bankName' => 'nullable|string|max:100',
            'bankAccount' => 'nullable|string|max:100',
            'accountName' => 'nullable|string|max:100',
            'remark' => 'nullable|string|max:255',
        ]);

        $withdrawal = $this->withdrawalService->request($tenantId, (int) $distributor->distributor_id, [
            'amount' => $validated['amount'],
            'account_type' => $validated['accountType'] ?? null,
            'bank_name' => $validated['bankName'] ?? null,
            'bank_account' => $validated['bankAccount'] ?? null,
            'account_name' => $validated['accountName'] ?? null,
            'remark' => $validated['remark'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $withdrawal], 201);
    }

    /**
     * 我的提现记录
     */
    public function withdrawals(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $distributor = $this->resolveDistributor($request);

        if (! $distributor) {
            return response()->json(['success' => true, 'data' => [], 'total' => 0]);
        }

        $result = $this->withdrawalService->paginate($tenantId, [
            'distributor_id' => $distributor->distributor_id,
            'status' => $request->input('status'),
        ], (int) $request->input('pageSize', 20));

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    // ========== 内部方法 ==========

    /**
     * 解析当前认证用户的分销员身份
     */
    protected function resolveDistributor(Request $request): ?Distributor
    {
        $profile = $this->resolveProfile($request);

        if (! $profile) {
            return null;
        }

        $tenantId = (int) TenantContext::getId();

        return $this->relationService->getCustomerDistributor($tenantId, $profile->userId);
    }

    /**
     * 解析当前认证用户的申请人档案（框架默认取 User；下游可绑定更严格的档案实现）
     */
    protected function resolveProfile(Request $request): ?DistributorProfile
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return $this->profiles->resolve((int) TenantContext::getId(), (int) $user->user_id);
    }

    protected function buildInviteUrl(string $inviteCode): string
    {
        // 租户规范域名优先（自定义域名 approved > {slug}.{wildcard_base} > {tenant_id}.{wildcard_base}），
        // 无租户上下文/无规范域名时回退 app.url（与 EnforceCanonicalEntry 同规则）
        $canonicalHost = app(DomainService::class)->getCanonicalHost((int) TenantContext::getId());
        $base = $canonicalHost ? "https://{$canonicalHost}" : config('app.url');

        return rtrim((string) $base, '/') . '/h5/distribution/apply?invite=' . $inviteCode;
    }

    protected function transformProfile(Distributor $d): array
    {
        return [
            'id' => (int) $d->distributor_id,
            'name' => $d->name,
            'avatar' => $d->avatar ?? '',
            'level' => (int) $d->level,
            'inviteCode' => $d->invite_code,
            'status' => $d->status,
            'totalCommission' => (int) $d->total_commission,
            'availableCommission' => (int) $d->available_commission,
            'frozenCommission' => (int) $d->frozen_commission,
            'subordinateCount' => (int) $d->subordinate_count,
            'createdAt' => $d->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    protected function transformMember(Distributor $d): array
    {
        return [
            'id' => (int) $d->distributor_id,
            'name' => $d->name,
            'avatar' => $d->avatar ?? '',
            'level' => (int) $d->level,
            'status' => $d->status,
            'createdAt' => $d->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    protected function transformCommission(CommissionRecord $r): array
    {
        return [
            'id' => (int) $r->commission_record_id,
            'orderNo' => $r->order_no ?? '',
            'level' => (int) $r->level,
            'orderAmount' => (int) $r->order_amount,
            'commissionAmount' => (int) $r->commission_amount,
            'status' => $r->status,
            'settledAt' => $r->settled_at?->format('Y-m-d H:i:s'),
            'createdAt' => $r->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
