<?php

namespace App\Services\User;

use App\Enums\User\TenantRole;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

class UserRoleAssignmentValidator
{
    public function role(User $caller, int $roleId): Role
    {
        $role = Role::query()->where('guard_name', 'api')->findOrFail($roleId);

        if ((new TenantContext($caller))->isInternal()) {
            return $role;
        }

        if (! in_array($role->name, $this->assignableRoleNames($caller), true)) {
            throw new AuthorizationException('Role is outside the caller authority.');
        }

        return $role;
    }

    public function assignableRoleNames(User $caller): array
    {
        if ($caller->hasRole(TenantRole::COMPANY_OWNER->value)) {
            return [
                TenantRole::COMPANY_MANAGER->value,
                TenantRole::BRANCH_MANAGER->value,
                TenantRole::EMPLOYEE->value,
            ];
        }

        if ($caller->hasRole(TenantRole::COMPANY_MANAGER->value)) {
            return [TenantRole::BRANCH_MANAGER->value, TenantRole::EMPLOYEE->value];
        }

        if ($caller->hasRole(TenantRole::BRANCH_MANAGER->value)) {
            return [TenantRole::EMPLOYEE->value];
        }

        return [];
    }
}
