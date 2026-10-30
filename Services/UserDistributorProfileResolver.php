<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Distribution\Contracts\DistributorProfileContract;
use MultiTenantSaas\Modules\Distribution\Support\DistributorProfile;

/**
 * 默认档案解析：直接取认证 User 的 name/phone/avatar。
 *
 * 下游需要更严格的档案条件（如客户资料完整性）时覆盖绑定即可。
 */
class UserDistributorProfileResolver implements DistributorProfileContract
{
    public function resolve(int $tenantId, int $userId): ?DistributorProfile
    {
        $user = User::query()->where('user_id', $userId)->first();

        if ($user === null) {
            return null;
        }

        return new DistributorProfile(
            userId: (int) $user->user_id,
            name: (string) $user->name,
            phone: $user->phone,
            avatar: $user->avatar,
        );
    }
}
