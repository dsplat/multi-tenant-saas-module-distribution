<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Distribution\Models\Distributor;
use MultiTenantSaas\Modules\Distribution\Services\CommissionRuleService;
use MultiTenantSaas\Modules\Distribution\Services\DistributionConfigService;
use MultiTenantSaas\Modules\Distribution\Services\DistributionRelationService;
use MultiTenantSaas\Modules\Distribution\Services\PayoutConfigService;
use MultiTenantSaas\Modules\Distribution\Services\WithdrawalService;

/**
 * 分销系统 B 端管理控制器
 *
 * 响应字段对齐前端契约 src/Modules/distribution/api/distribution.ts（camelCase）。
 */
class DistributionController extends BaseController
{
    public function __construct(
        protected DistributionRelationService $relationService,
        protected CommissionRuleService $ruleService,
        protected WithdrawalService $withdrawalService,
        protected DistributionConfigService $configService,
        protected PayoutConfigService $payoutConfigService,
    ) {}

    // ========== 分销员管理 ==========

    public function distributors(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        TenantContext::setTenantId((string) $tenantId);

        $query = Distributor::where('tenant_id', $tenantId)->with('parent');

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }
        if ($request->filled('level')) {
            $query->where('level', (int) $request->input('level'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->orderByDesc('created_at')
            ->orderByDesc('distributor_id')
            ->paginate((int) $request->input('pageSize', 20));

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn ($d) => $this->transformDistributor($d))->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function distributorDetail($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        TenantContext::setTenantId((string) $tenantId);

        $distributor = Distributor::where('distributor_id', $id)
            ->where('tenant_id', $tenantId)
            ->with('parent')
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->transformDistributor($distributor)]);
    }

    public function updateDistributorStatus(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:active,frozen',
        ]);

        $tenantId = (int) TenantContext::getId();
        $distributor = $this->relationService->setStatus($tenantId, (int) $id, $validated['status']);

        return response()->json(['success' => true, 'data' => $this->transformDistributor($distributor)]);
    }

    public function distributorTree(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        TenantContext::setTenantId((string) $tenantId);

        $rootId = $request->input('rootId');

        if ($rootId) {
            $tree = $this->relationService->getTeamTree($tenantId, (int) $rootId);
            $nodes = collect($tree)->map(fn ($item) => $this->transformTreeNode($item['distributor'], $item['children']))->all();

            return response()->json(['success' => true, 'data' => $nodes]);
        }

        // 无 rootId：返回所有顶级分销员及其团队
        $roots = Distributor::where('tenant_id', $tenantId)
            ->whereNull('parent_id')
            ->orderByDesc('created_at')
            ->orderByDesc('distributor_id')
            ->get();

        $nodes = $roots->map(function ($root) use ($tenantId) {
            $tree = $this->relationService->getTeamTree($tenantId, (int) $root->distributor_id);
            $children = collect($tree)->map(fn ($item) => $this->transformTreeNode($item['distributor'], $item['children']))->all();

            return $this->transformTreeNode($root, $children);
        })->all();

        return response()->json(['success' => true, 'data' => $nodes]);
    }

    // ========== 佣金规则管理 ==========

    public function commissionRules(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $result = $this->ruleService->paginate($tenantId, [
            'status' => $request->input('status'),
        ], (int) $request->input('pageSize', 20));

        return response()->json([
            'success' => true,
            'data' => collect($result['data'])->map(fn ($r) => $this->transformRule($r))->all(),
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    public function createCommissionRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'level' => 'required|integer|in:1,2',
            'calcType' => 'nullable|in:rate,fixed',
            'rate' => 'nullable|numeric|min:0|max:100',
            'fixedAmount' => 'nullable|integer|min:0',
            'minAmount' => 'nullable|integer|min:0',
            'maxAmount' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        $tenantId = (int) TenantContext::getId();
        $rule = $this->ruleService->create($tenantId, $this->ruleInput($validated));

        return response()->json(['success' => true, 'data' => $this->transformRule($rule)], 201);
    }

    public function updateCommissionRule(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'level' => 'sometimes|integer|in:1,2',
            'calcType' => 'sometimes|in:rate,fixed',
            'rate' => 'sometimes|numeric|min:0|max:100',
            'fixedAmount' => 'sometimes|integer|min:0',
            'minAmount' => 'sometimes|integer|min:0',
            'maxAmount' => 'sometimes|integer|min:0',
            'description' => 'sometimes|nullable|string|max:255',
        ]);

        $tenantId = (int) TenantContext::getId();
        $rule = $this->ruleService->update($tenantId, (int) $id, $this->ruleInput($validated));

        return response()->json(['success' => true, 'data' => $this->transformRule($rule)]);
    }

    public function deleteCommissionRule($id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->ruleService->delete($tenantId, (int) $id);

        return response()->json(['success' => true, 'message' => '规则已删除']);
    }

    public function toggleCommissionRule(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:enabled,disabled',
        ]);

        $tenantId = (int) TenantContext::getId();
        $rule = $this->ruleService->setStatus($tenantId, (int) $id, $validated['status']);

        return response()->json(['success' => true, 'data' => $this->transformRule($rule)]);
    }

    // ========== 提现管理 ==========

    public function withdrawals(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        $result = $this->withdrawalService->paginate($tenantId, [
            'status' => $request->input('status'),
        ], (int) $request->input('pageSize', 20));

        return response()->json([
            'success' => true,
            'data' => collect($result['data'])->map(fn ($w) => $this->transformWithdrawal($w))->all(),
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    public function approveWithdrawal(Request $request, $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $withdrawal = $this->withdrawalService->audit($tenantId, (int) $id, true, $request->input('remark'));

        // 审核通过后尝试执行打款（自动渠道直接打款；手动渠道保持 approved 等待人工确认）
        $withdrawal = $this->withdrawalService->payout($tenantId, (int) $withdrawal->withdrawal_id);

        return response()->json(['success' => true, 'data' => $this->transformWithdrawal($withdrawal)]);
    }

    public function rejectWithdrawal(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'remark' => 'required|string|max:255',
        ]);

        $tenantId = (int) TenantContext::getId();
        $withdrawal = $this->withdrawalService->audit($tenantId, (int) $id, false, $validated['remark']);

        return response()->json(['success' => true, 'data' => $this->transformWithdrawal($withdrawal)]);
    }

    public function confirmWithdrawalPaid(Request $request, $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $withdrawal = $this->withdrawalService->confirmPaid($tenantId, (int) $id, $request->input('channelTradeNo'));

        return response()->json(['success' => true, 'data' => $this->transformWithdrawal($withdrawal)]);
    }

    // ========== 分销/打款配置 ==========

    public function getConfig(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data' => $this->configService->getConfig($tenantId),
        ]);
    }

    public function updateConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'maxLevel' => 'sometimes|integer|in:1,2',
            'selfBuyRebate' => 'sometimes|boolean',
            'auditMode' => 'sometimes|in:auto,manual',
            'settleDays' => 'sometimes|integer|min:0|max:90',
            'lockDays' => 'sometimes|integer|min:0|max:365',
            'withdrawMinAmount' => 'sometimes|integer|min:0',
            'withdrawFeeRate' => 'sometimes|numeric|min:0|max:100',
        ]);

        $tenantId = (int) TenantContext::getId();
        $config = $this->configService->updateConfig($tenantId, [
            'enabled' => $validated['enabled'] ?? null,
            'max_level' => $validated['maxLevel'] ?? null,
            'self_buy_rebate' => $validated['selfBuyRebate'] ?? null,
            'audit_mode' => $validated['auditMode'] ?? null,
            'settle_days' => $validated['settleDays'] ?? null,
            'lock_days' => $validated['lockDays'] ?? null,
            'withdraw_min_amount' => $validated['withdrawMinAmount'] ?? null,
            'withdraw_fee_rate' => $validated['withdrawFeeRate'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $config]);
    }

    public function getPayoutConfig(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $config = $this->payoutConfigService->getConfig($tenantId);

        return response()->json(['success' => true, 'data' => $config->toArray()]);
    }

    public function updatePayoutConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => 'sometimes|in:manual,wechat_transfer,alipay_transfer',
            'merchantId' => 'sometimes|nullable|string|max:100',
            'serialNo' => 'sometimes|nullable|string|max:100',
            'privateKey' => 'sometimes|nullable|string',
            'apiv3Key' => 'sometimes|nullable|string|max:255',
            'extra' => 'sometimes|nullable|array',
            'status' => 'sometimes|in:enabled,disabled',
        ]);

        $tenantId = (int) TenantContext::getId();
        $config = $this->payoutConfigService->updateConfig($tenantId, [
            'channel' => $validated['channel'] ?? null,
            'merchant_id' => $validated['merchantId'] ?? null,
            'serial_no' => $validated['serialNo'] ?? null,
            'private_key' => $validated['privateKey'] ?? null,
            'apiv3_key' => $validated['apiv3Key'] ?? null,
            'extra' => $validated['extra'] ?? null,
            'status' => $validated['status'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $config->toArray()]);
    }

    // ========== 转换器 ==========

    protected function transformDistributor(Distributor $d): array
    {
        return [
            'id' => (int) $d->distributor_id,
            'name' => $d->name,
            'avatar' => $d->avatar ?? '',
            'phone' => $d->phone ?? '',
            'level' => (int) $d->level,
            'inviteCode' => $d->invite_code,
            'totalCommission' => (int) $d->total_commission,
            'availableCommission' => (int) $d->available_commission,
            'frozenCommission' => (int) $d->frozen_commission,
            'subordinateCount' => (int) $d->subordinate_count,
            'parentId' => $d->parent_id ? (int) $d->parent_id : null,
            'parentName' => $d->parent?->name ?? '',
            'status' => $d->status,
            'createdAt' => $d->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $d->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    protected function transformTreeNode(Distributor $d, array $children = []): array
    {
        return [
            'id' => (int) $d->distributor_id,
            'name' => $d->name,
            'avatar' => $d->avatar ?? '',
            'level' => (int) $d->level,
            'totalCommission' => (int) $d->total_commission,
            'subordinateCount' => (int) $d->subordinate_count,
            'children' => $children,
        ];
    }

    protected function transformRule($r): array
    {
        return [
            'id' => (int) $r->commission_rule_id,
            'name' => $r->name,
            'level' => (int) $r->level,
            'calcType' => $r->calc_type,
            'rate' => (float) $r->rate,
            'fixedAmount' => (int) $r->fixed_amount,
            'minAmount' => (int) $r->min_amount,
            'maxAmount' => (int) $r->max_amount,
            'status' => $r->status,
            'description' => $r->description ?? '',
            'createdAt' => $r->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $r->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    protected function transformWithdrawal($w): array
    {
        return [
            'id' => (int) $w->withdrawal_id,
            'distributorId' => (int) $w->distributor_id,
            'distributorName' => $w->distributor?->name ?? '',
            'amount' => (int) $w->amount,
            'fee' => (int) $w->fee,
            'actualAmount' => (int) $w->actual_amount,
            'status' => $w->status,
            'accountType' => $w->account_type ?? '',
            'bankName' => $w->bank_name ?? '',
            'bankAccount' => $w->bank_account ?? '',
            'accountName' => $w->account_name ?? '',
            'remark' => $w->remark ?? '',
            'auditRemark' => $w->audit_remark ?? '',
            'createdAt' => $w->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $w->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 前端 camelCase 入参 -> 服务层 snake_case 字段
     */
    protected function ruleInput(array $data): array
    {
        return array_filter([
            'name' => $data['name'] ?? null,
            'level' => $data['level'] ?? null,
            'calc_type' => $data['calcType'] ?? null,
            'rate' => $data['rate'] ?? null,
            'fixed_amount' => $data['fixedAmount'] ?? null,
            'min_amount' => $data['minAmount'] ?? null,
            'max_amount' => $data['maxAmount'] ?? null,
            'description' => $data['description'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
