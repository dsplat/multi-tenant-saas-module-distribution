<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Distribution\Models\DistributionConfig;

/**
 * 分销全局配置服务
 *
 * 每个租户一行配置；读取时若租户未配置则返回平台默认值（不入库），
 * 写入时 upsert 租户级配置。
 */
class DistributionConfigService
{
    /**
     * 平台默认配置（合规红线：max_level 锁定 2 级）
     */
    public const DEFAULTS = [
        'enabled' => false,
        'max_level' => 2,
        'self_buy_rebate' => false,
        'audit_mode' => DistributionConfig::AUDIT_AUTO,
        'settle_days' => 7,
        'lock_days' => 0,
        'withdraw_min_amount' => 0,
        'withdraw_fee_rate' => 0.00,
    ];

    /**
     * 获取租户配置（数组形式，含默认值兜底）
     */
    public function getConfig(int $tenantId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $config = DistributionConfig::where('tenant_id', $tenantId)->first();

        if (! $config) {
            return self::DEFAULTS;
        }

        return [
            'enabled' => $config->isEnabled(),
            'max_level' => $config->max_level,
            'self_buy_rebate' => (bool) $config->self_buy_rebate,
            'audit_mode' => $config->audit_mode,
            'settle_days' => $config->settle_days,
            'lock_days' => $config->lock_days,
            'withdraw_min_amount' => (int) $config->withdraw_min_amount,
            'withdraw_fee_rate' => (float) $config->withdraw_fee_rate,
        ];
    }

    /**
     * 获取配置模型实例（不存在则返回填充默认值的新实例，不入库）
     */
    public function getModel(int $tenantId): DistributionConfig
    {
        TenantContext::setTenantId((string) $tenantId);

        return DistributionConfig::where('tenant_id', $tenantId)->first()
            ?? new DistributionConfig(array_merge(self::DEFAULTS, ['tenant_id' => $tenantId]));
    }

    /**
     * 更新（upsert）租户配置
     */
    public function updateConfig(int $tenantId, array $data): DistributionConfig
    {
        TenantContext::setTenantId((string) $tenantId);

        $config = DistributionConfig::firstOrNew(['tenant_id' => $tenantId]);

        $fillable = [
            'enabled', 'max_level', 'self_buy_rebate', 'audit_mode',
            'settle_days', 'lock_days', 'withdraw_min_amount', 'withdraw_fee_rate',
        ];

        $attributes = array_filter(
            array_intersect_key($data, array_flip($fillable)),
            fn ($v) => $v !== null
        );

        // 合规红线：分销层级强制不超过 2 级
        if (isset($attributes['max_level'])) {
            $attributes['max_level'] = min((int) $attributes['max_level'], 2);
        }

        $config->fill($attributes);
        $config->save();

        return $config->fresh();
    }

    /**
     * 分销是否启用
     */
    public function isEnabled(int $tenantId): bool
    {
        return (bool) $this->getConfig($tenantId)['enabled'];
    }
}
