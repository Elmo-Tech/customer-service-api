<?php

namespace App\Services\Select;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Enums\User\UserStatus;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Parameter\parameterValue;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\User\UserRoleAssignmentValidator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SelectService
{
    public function __construct(private readonly UserRoleAssignmentValidator $roleValidator) {}

    public function getSelects(string $requestedSelects, User $user): array
    {
        return collect(explode(',', $requestedSelects))
            ->map(fn (string $requestedSelect) => $this->selectPayload($requestedSelect, $user))
            ->filter()
            ->values()
            ->all();
    }

    private function selectPayload(string $requestedSelect, User $user): ?array
    {
        [$selectName, $parameter] = array_pad(explode('=', $requestedSelect, 2), 2, null);
        $options = match ($selectName) {
            'users' => $this->users($user),
            'roles' => $this->roles($user),
            'permissions' => $this->permissions($user),
            'companies' => $this->companies($user),
            'branches' => $this->branches($user, $parameter),
            'customers' => $this->customers($user),
            'parameters' => $this->parameters($parameter),
            default => null,
        };

        return $options === null ? null : ['label' => $selectName, 'options' => $options];
    }

    private function users(User $user): array
    {
        return $this->tenantContext($user)
            ->scopeUsers(User::query())
            ->where('status', UserStatus::ACTIVE->value)
            ->select(['id as value', 'name as label'])
            ->get()->all();
    }

    private function roles(User $user): array
    {
        $roles = Role::query()->where('guard_name', 'api');

        if (! $this->tenantContext($user)->isInternal()) {
            $roles->whereIn('name', $this->roleValidator->assignableRoleNames($user));
        }

        return $roles->get(['id as value', 'name as label'])->all();
    }

    private function permissions(User $user): array
    {
        if (! $this->tenantContext($user)->isInternal()) {
            return [];
        }

        return Permission::query()->get(['name as value', 'name as label'])->all();
    }

    private function companies(User $user): array
    {
        return $this->tenantContext($user)
            ->scopeCompanies(Company::query())
            ->where('status', CompanyStatus::ACTIVE->value)
            ->get(['id', 'name', 'uses_branches'])
            ->map(fn (Company $company) => [
                'value' => $company->id,
                'label' => $company->name,
                'usesBranches' => $company->uses_branches,
            ])->all();
    }

    private function branches(User $user, ?string $companyId): array
    {
        $branches = $this->tenantContext($user)->scopeBranches(Branch::query());
        $branches->where('status', BranchStatus::ACTIVE->value);

        if ($companyId !== null) {
            $branches->where('company_id', $companyId);
        }

        return $branches->get(['id as value', 'name as label'])->all();
    }

    private function customers(User $user): array
    {
        return $this->tenantContext($user)
            ->scopeCustomers(Customer::query())
            ->where('status', CustomerStatus::ACTIVE->value)
            ->get(['id', 'firstname', 'lastname'])
            ->map(fn (Customer $customer) => [
                'value' => $customer->id,
                'label' => $customer->getFullName(),
            ])->all();
    }

    private function parameters(?string $parameterId): array
    {
        if ($parameterId === null) {
            return [];
        }

        return parameterValue::query()
            ->where('parameter_id', $parameterId)
            ->get(['id as value', 'parameter_value as label'])
            ->all();
    }

    private function tenantContext(User $user): TenantContext
    {
        return new TenantContext($user);
    }
}
