<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Distribution\Models\CommissionRule;

/**
 * 佣金规则服务
 *
 * 负责佣金规则的 CRUD、启停，以及按分销层级匹配适用规则。
 * 规则按 level（1级/2级）+ apply_scope（适用范围）+ priority（优先级）匹配。
 */
class CommissionRuleService
{
    /**
     * 创建佣金规则
     */
    public function create(int $tenantId, array $data): CommissionRule
    {
        TenantContext::setTenantId((string) $tenantId);

        return CommissionRule::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'level' => (int) ($data['level'] ?? 1),
            'calc_type' => $data['calc_type'] ?? CommissionRule::CALC_RATE,
            'rate' => $data['rate'] ?? 0,
            'fixed_amount' => $data['fixed_amount'] ?? 0,
            'min_amount' => $data['min_amount'] ?? 0,
            'max_amount' => $data['max_amount'] ?? 0,
            'apply_scope' => $data['apply_scope'] ?? 'all',
            'scope_config' => $data['scope_config'] ?? null,
            'priority' => (int) ($data['priority'] ?? 0),
            'status' => $data['status'] ?? 'enabled',
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * 分页列表
     */
    public function paginate(int $tenantId, array $filters = [], int $perPage = 20): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = CommissionRule::where('tenant_id', $tenantId);

        if (isset($filters['level'])) {
            $query->where('level', (int) $filters['level']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->orderByDesc('commission_rule_id')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * 详情
     */
    public function find(int $tenantId, int $ruleId): CommissionRule
    {
        TenantContext::setTenantId((string) $tenantId);

        return CommissionRule::where('commission_rule_id', $ruleId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    /**
     * 更新
     */
    public function update(int $tenantId, int $ruleId, array $data): CommissionRule
    {
        TenantContext::setTenantId((string) $tenantId);

        $rule = $this->find($tenantId, $ruleId);

        $fillable = [
            'name', 'level', 'calc_type', 'rate', 'fixed_amount',
            'min_amount', 'max_amount', 'apply_scope', 'scope_config',
            'priority', 'status', 'description',
        ];
        $rule->update(array_intersect_key($data, array_flip($fillable)));

        return $rule->fresh();
    }

    /**
     * 启用/停用
     */
    public function setStatus(int $tenantId, int $ruleId, string $status): CommissionRule
    {
        TenantContext::setTenantId((string) $tenantId);

        $rule = $this->find($tenantId, $ruleId);
        $rule->update(['status' => $status]);

        return $rule->fresh();
    }

    /**
     * 删除（软删除）
     */
    public function delete(int $tenantId, int $ruleId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        return (bool) $this->find($tenantId, $ruleId)->delete();
    }

    /**
     * 匹配指定层级适用的佣金规则
     *
     * 匹配逻辑：
     * 1. 状态为 enabled
     * 2. level 等于目标层级
     * 3. apply_scope 命中（all 直接命中；其余按 scope_config 判断，默认命中）
     * 4. 按 priority 降序取第一条
     *
     * @return CommissionRule|null 无匹配规则返回 null（表示该层级不计佣）
     */
    public function matchRule(int $tenantId, int $level, array $context = []): ?CommissionRule
    {
        TenantContext::setTenantId((string) $tenantId);

        $rules = CommissionRule::where('tenant_id', $tenantId)
            ->where('status', 'enabled')
            ->where('level', $level)
            ->orderByDesc('priority')
            ->orderByDesc('commission_rule_id')
            ->get();

        foreach ($rules as $rule) {
            if ($this->scopeMatches($rule, $context)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * 判断规则适用范围是否命中
     */
    protected function scopeMatches(CommissionRule $rule, array $context): bool
    {
        if ($rule->apply_scope === 'all') {
            return true;
        }

        $config = $rule->scope_config ?? [];

        // 按订单类型匹配（order_type in scope_config.order_types）
        if ($rule->apply_scope === 'order_type' && isset($context['order_type'])) {
            $types = $config['order_types'] ?? [];

            return in_array($context['order_type'], $types, true);
        }

        // 按商品/活动匹配（goods_id in scope_config.goods_ids）
        if ($rule->apply_scope === 'goods' && isset($context['goods_id'])) {
            $ids = $config['goods_ids'] ?? [];

            return in_array($context['goods_id'], $ids, true);
        }

        // 未识别的 scope 默认命中，避免漏计佣
        return true;
    }
}
