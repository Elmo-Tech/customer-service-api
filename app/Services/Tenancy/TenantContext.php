<?php

namespace App\Services\Tenancy;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class TenantContext
{
    public function __construct(private readonly User $user) {}

    public function scopeTenantRows(Builder $query, string $companyColumn = 'company_id'): Builder
    {
        if ($this->isInternal()) {
            return $query;
        }

        return $query->where($companyColumn, $this->tenantCompanyId());
    }

    public function scopeCompanies(Builder $query): Builder
    {
        if ($this->isInternal()) {
            return $query;
        }

        return $query->whereKey($this->tenantCompanyId());
    }

    public function scopeTickets(Builder $query): Builder
    {
        if ($this->isInternal()) {
            return $query;
        }

        $query->where('company_id', $this->tenantCompanyId());

        if ($this->hasCompanyScope()) {
            return $query;
        }

        if ($this->user->hasRole(TenantRole::BRANCH_MANAGER->value)) {
            return $query->where('branch_id', $this->tenantBranchId());
        }

        if ($this->user->hasRole(TenantRole::EMPLOYEE->value)) {
            return $query->where('opened_by_user_id', $this->user->id);
        }

        throw new AuthorizationException('The tenant role has no ticket scope.');
    }

    public function scopeUsers(Builder $query): Builder
    {
        if ($this->isInternal()) {
            return $query;
        }

        $query->where('company_id', $this->tenantCompanyId());

        if ($this->hasCompanyScope()) {
            return $query;
        }

        if ($this->user->hasRole(TenantRole::BRANCH_MANAGER->value)) {
            return $query->where('branch_id', $this->tenantBranchId());
        }

        if ($this->user->hasRole(TenantRole::EMPLOYEE->value)) {
            return $query->whereKey($this->user->id);
        }

        throw new AuthorizationException('The tenant role has no user scope.');
    }

    public function scopeBranches(Builder $query): Builder
    {
        if ($this->isInternal()) {
            return $query;
        }

        $query->where('company_id', $this->tenantCompanyId());

        if ($this->hasCompanyScope() || ! $this->user->company->uses_branches) {
            return $query;
        }

        if ($this->user->hasAnyRole([TenantRole::BRANCH_MANAGER->value, TenantRole::EMPLOYEE->value])) {
            return $query->whereKey($this->tenantBranchId());
        }

        throw new AuthorizationException('The tenant role has no branch scope.');
    }

    public function scopeCustomers(Builder $query): Builder
    {
        if ($this->isInternal()) {
            return $query;
        }

        $query->where('company_id', $this->tenantCompanyId());

        if ($this->hasCompanyScope()) {
            return $query;
        }

        if ($this->user->hasRole(TenantRole::BRANCH_MANAGER->value)) {
            return $query->where('branch_id', $this->tenantBranchId());
        }

        if ($this->user->hasRole(TenantRole::EMPLOYEE->value)) {
            return $query->where('user_id', $this->user->id);
        }

        throw new AuthorizationException('The tenant role has no customer scope.');
    }

    public function canAccessCompany(?int $companyId): bool
    {
        if ($this->isInternal()) {
            return true;
        }

        return $companyId !== null && $companyId === $this->tenantCompanyId();
    }

    public function isInternal(): bool
    {
        return $this->user->account_type === AccountType::INTERNAL;
    }

    public function tenantCompanyId(): int
    {
        if ($this->user->account_type !== AccountType::TENANT) {
            throw new AuthorizationException('The account has no tenant access.');
        }

        $company = $this->user->company;

        if (! $company || (int) $company->status !== CompanyStatus::ACTIVE->value) {
            throw new AuthorizationException('The tenant company is inactive or missing.');
        }

        return $company->id;
    }

    public function tenantBranchId(): int
    {
        $branch = $this->user->branch;

        if (! $branch || (int) $branch->company_id !== $this->tenantCompanyId()) {
            throw new AuthorizationException('The tenant branch is missing or invalid.');
        }

        if ((int) $branch->status !== BranchStatus::ACTIVE->value) {
            throw new AuthorizationException('The tenant branch is inactive.');
        }

        return $branch->id;
    }

    private function hasCompanyScope(): bool
    {
        return $this->user->hasAnyRole([
            TenantRole::COMPANY_OWNER->value,
            TenantRole::COMPANY_MANAGER->value,
        ]);
    }

    public function canManageCompany(): bool
    {
        return $this->isInternal() || $this->hasCompanyScope();
    }

    public function canManageBranchAccounts(): bool
    {
        return $this->canManageCompany() || $this->user->hasRole(TenantRole::BRANCH_MANAGER->value);
    }
}
