<?php

namespace App\Services\Customer;

use App\Enums\Company\CustomerStatus;
use App\Enums\User\TenantRole;
use App\Filters\Customer\FilterCustomer;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CustomerService
{
    public function allCustomers(User $caller)
    {
        $customers = (new TenantContext($caller))->scopeCustomers(Customer::query());

        return QueryBuilder::for($customers)->allowedFilters([
            AllowedFilter::custom('search', new FilterCustomer),
            AllowedFilter::exact('status'),
            AllowedFilter::exact('company', 'company_id'),
        ])->get();
    }

    public function createCustomer(User $caller, array $customerFields): Customer
    {
        $context = $this->managementContext($caller);
        $companyId = $context->isInternal()
            ? (int) $customerFields['companyId']
            : $context->tenantCompanyId();
        $branchId = $this->authorizedBranchId($caller, $companyId, $customerFields['branchId'] ?? null);
        $this->assertUniqueName($companyId, $customerFields);

        return Customer::create($this->attributes($customerFields, $companyId, $branchId));
    }

    public function editCustomer(User $caller, int $customerId): Customer
    {
        return (new TenantContext($caller))->scopeCustomers(Customer::query())->findOrFail($customerId);
    }

    public function updateCustomer(User $caller, array $customerFields): Customer
    {
        $context = $this->managementContext($caller);
        $customer = $context->scopeCustomers(Customer::query())->findOrFail($customerFields['customerId']);
        $this->assertUnchangedCompany($customer, $customerFields);
        $branchId = $this->authorizedBranchId($caller, $customer->company_id, $customerFields['branchId'] ?? null);
        $this->assertUniqueName($customer->company_id, $customerFields, $customer->id);
        $customer->update($this->attributes($customerFields, $customer->company_id, $branchId));

        return $customer;
    }

    public function deleteCustomer(User $caller, int $customerId): bool
    {
        $context = $this->managementContext($caller);

        return (bool) $context->scopeCustomers(Customer::query())->findOrFail($customerId)->delete();
    }

    private function managementContext(User $caller): TenantContext
    {
        $context = new TenantContext($caller);

        if (! $context->canManageBranchAccounts()) {
            throw new AuthorizationException;
        }

        return $context;
    }

    private function authorizedBranchId(User $caller, int $companyId, ?int $requestedBranchId): ?int
    {
        $context = new TenantContext($caller);
        $branchId = $caller->hasRole(TenantRole::BRANCH_MANAGER->value)
            ? $context->tenantBranchId()
            : $requestedBranchId;

        if (! Company::query()->findOrFail($companyId)->uses_branches && $branchId !== null) {
            throw ValidationException::withMessages(['branchId' => 'This company does not use branches.']);
        }

        if ($branchId !== null && ! Branch::query()->whereKey($branchId)->where('company_id', $companyId)->exists()) {
            throw ValidationException::withMessages(['branchId' => 'Branch is outside the customer company.']);
        }

        return $branchId;
    }

    private function assertUniqueName(int $companyId, array $customerFields, ?int $exceptId = null): void
    {
        $duplicate = Customer::query()->where('company_id', $companyId)
            ->where('firstname', $customerFields['firstname'])
            ->where('lastname', $customerFields['lastname'])
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['firstname' => 'Customer already exists in this company.']);
        }
    }

    private function assertUnchangedCompany(Customer $customer, array $customerFields): void
    {
        if ((int) $customerFields['companyId'] !== (int) $customer->company_id) {
            throw ValidationException::withMessages(['companyId' => 'Customer company cannot be changed.']);
        }
    }

    private function attributes(array $fields, int $companyId, ?int $branchId): array
    {
        return [
            'firstname' => $fields['firstname'],
            'lastname' => $fields['lastname'],
            'pin' => $fields['pin'],
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'status' => CustomerStatus::from($fields['status'])->value,
            'email' => $fields['email'] ?? null,
        ];
    }
}
