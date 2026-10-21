<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Contracts;

use MultiTenantSaas\Modules\Distribution\Support\DistributorProfile;

/**
 * 分销申请人档案解析扩展点
 *
 * 分销中心（C 端）在申请成为分销员前需要「申请人档案」。框架默认取 User 的
 * name/phone/avatar；项目侧若要求更完整的客户资料（如 scrm 的 Customer 档案），
 * 绑定本契约即可接管，缺档案时返回 null 让控制器给出项目话术。
 */
interface DistributorProfileContract
{
    public function resolve(int $tenantId, int $userId): ?DistributorProfile;
}
