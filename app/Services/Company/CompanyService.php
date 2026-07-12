<?php

namespace App\Services\Company;

use App\Enums\Company\CompanyStatus;
use App\Filters\Company\FilterCompany;
use App\Models\Company\Company;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CompanyService
{
    public function allCompanies(User $caller)
    {
        $companies = (new TenantContext($caller))->scopeCompanies(Company::query())->with('branches');

        return QueryBuilder::for($companies)->allowedFilters([
            AllowedFilter::custom('search', new FilterCompany),
            AllowedFilter::exact('status'),
        ])->get();
    }

    public function createCompany(User $caller, array $companyFields): Company
    {
        $this->assertInternal($caller);

        return Company::create([
            'name' => $companyFields['name'],
            'status' => CompanyStatus::from($companyFields['status'])->value,
            'uses_branches' => $companyFields['usesBranches'] ?? true,
        ]);
    }

    public function editCompany(User $caller, int $companyId): Company
    {
        return (new TenantContext($caller))->scopeCompanies(Company::query())
            ->with('branches')->findOrFail($companyId);
    }

    public function updateCompany(User $caller, array $companyFields): Company
    {
        $context = new TenantContext($caller);

        if (! $context->canManageCompany()) {
            throw new AuthorizationException;
        }

        $company = $context->scopeCompanies(Company::query())->findOrFail($companyFields['companyId']);
        $company->update([
            'name' => $companyFields['name'],
            'status' => CompanyStatus::from($companyFields['status'])->value,
        ]);

        return $company;
    }

    public function deleteCompany(User $caller, int $companyId): bool
    {
        $this->assertInternal($caller);

        return (bool) Company::query()->findOrFail($companyId)->delete();
    }

    private function assertInternal(User $caller): void
    {
        if (! (new TenantContext($caller))->isInternal()) {
            throw new AuthorizationException;
        }
    }
}
