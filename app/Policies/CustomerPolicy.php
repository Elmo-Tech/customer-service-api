<?php

namespace App\Policies;

use App\Models\Company\Customer;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

class CustomerPolicy
{
    public function view(User $user, Customer $customer): bool
    {
        return $user->can('edit_customer') && $this->contains($user, $customer);
    }

    public function create(User $user): bool
    {
        return $user->can('create_customer') && (new TenantContext($user))->canManageBranchAccounts();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('update_customer')
            && (new TenantContext($user))->canManageBranchAccounts()
            && $this->contains($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('delete_customer')
            && (new TenantContext($user))->canManageBranchAccounts()
            && $this->contains($user, $customer);
    }

    private function contains(User $user, Customer $customer): bool
    {
        return (new TenantContext($user))->scopeCustomers(Customer::query())->whereKey($customer->id)->exists();
    }
}
