<?php

namespace App\Policies;

use App\Models\Company\Branch;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

class BranchPolicy
{
    public function view(User $user, Branch $branch): bool
    {
        return $user->can('edit_branch') && $this->contains($user, $branch);
    }

    public function create(User $user): bool
    {
        return $user->can('create_branch') && (new TenantContext($user))->canManageCompany();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('update_branch')
            && (new TenantContext($user))->canManageCompany()
            && $this->contains($user, $branch);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->can('delete_branch')
            && (new TenantContext($user))->canManageCompany()
            && $this->contains($user, $branch);
    }

    private function contains(User $user, Branch $branch): bool
    {
        return (new TenantContext($user))->scopeBranches(Branch::query())->whereKey($branch->id)->exists();
    }
}
