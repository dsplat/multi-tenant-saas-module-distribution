<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Distribution\Models\PayoutConfig;

/**
 * 租户打款配置服务
 *
 * 每租户一行（upsert）。私钥等敏感字段写入时存储、读取时隐藏（Model $hidden）。
 */
class PayoutConfigService
{
    /**
     * 获取租户打款配置（不存在返回填充默认的新实例，不入库）
     */
    public function getConfig(int $tenantId): PayoutConfig
    {
        TenantContext::setTenantId((string) $tenantId);

        return PayoutConfig::where('tenant_id', $tenantId)->first()
            ?? new PayoutConfig(['tenant_id' => $tenantId, 'channel' => PayoutConfig::CHANNEL_MANUAL, 'status' => 'enabled']);
    }

    /**
     * 更新（upsert）租户打款配置
     *
     * 私钥字段为空字符串时不覆盖原值（避免编辑时误清空）。
     */
    public function updateConfig(int $tenantId, array $data): PayoutConfig
    {
        TenantContext::setTenantId((string) $tenantId);

        $config = PayoutConfig::firstOrNew(['tenant_id' => $tenantId]);

        $fillable = ['channel', 'merchant_id', 'serial_no', 'apiv3_key', 'extra', 'status'];
        $config->fill(array_filter(
            array_intersect_key($data, array_flip($fillable)),
            fn ($v) => $v !== null
        ));

        // 私钥仅在显式传入非空时更新
        if (! empty($data['private_key'])) {
            $config->private_key = $data['private_key'];
        }

        $config->save();

        return $config->fresh();
    }
}
