<?php

namespace App\Services\Auth;

use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Enums\User\UserStatus;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

class AccountAccess
{
    public function isActive(?User $user): bool
    {
        if (! $user || $user->status !== UserStatus::ACTIVE) {
            return false;
        }

        if ($user->account_type === AccountType::INTERNAL) {
            return true;
        }

        return $this->activeTenant($user);
    }

    private function activeTenant(User $user): bool
    {
        try {
            $context = new TenantContext($user);
            $context->tenantCompanyId();

            $branchRoleRequiresBranch = $user->company->uses_branches && $user->hasAnyRole([
                TenantRole::BRANCH_MANAGER->value,
                TenantRole::EMPLOYEE->value,
            ]);
            if ($user->branch_id !== null || $branchRoleRequiresBranch) {
                $context->tenantBranchId();
            }

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
