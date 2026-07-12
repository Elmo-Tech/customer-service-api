<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

class UserPolicy
{
    public function view(User $caller, User $user): bool
    {
        return $caller->can('edit_user') && $this->contains($caller, $user);
    }

    public function create(User $caller): bool
    {
        return $caller->can('create_user') && (new TenantContext($caller))->canManageBranchAccounts();
    }

    public function update(User $caller, User $user): bool
    {
        return $caller->can('update_user')
            && (new TenantContext($caller))->canManageBranchAccounts()
            && $this->contains($caller, $user);
    }

    public function delete(User $caller, User $user): bool
    {
        return $caller->can('delete_user')
            && (new TenantContext($caller))->canManageBranchAccounts()
            && $this->contains($caller, $user);
    }

    private function contains(User $caller, User $user): bool
    {
        return (new TenantContext($caller))->scopeUsers(User::query())->whereKey($user->id)->exists();
    }
}
