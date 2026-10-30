<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Support;

/**
 * 分销申请人档案（身份解耦）
 *
 * 框架默认由 User 表解析；下游可绑定自己的客户档案实现（如 scrm 的 Customer），
 * 保持「无档案则提示先完善资料」等项目语义。
 */
final readonly class DistributorProfile
{
    public function __construct(
        public int $userId,
        public string $name,
        public ?string $phone = null,
        public ?string $avatar = null,
    ) {}
}
