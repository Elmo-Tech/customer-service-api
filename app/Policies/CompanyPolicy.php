<?php

namespace App\Policies;

use App\Models\Company\Company;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $user->can('edit_company') && (new TenantContext($user))->canAccessCompany($company->id);
    }

    public function create(User $user): bool
    {
        return $user->can('create_company') && (new TenantContext($user))->isInternal();
    }

    public function update(User $user, Company $company): bool
    {
        $context = new TenantContext($user);

        return $user->can('update_company')
            && $context->canManageCompany()
            && $context->canAccessCompany($company->id);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->can('delete_company') && (new TenantContext($user))->isInternal();
    }
}
