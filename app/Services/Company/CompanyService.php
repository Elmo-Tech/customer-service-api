<?php

namespace App\Services\Company;

use App\Enums\Company\CompanyStatus;
use App\Filters\Company\FilterCompany;
use App\Models\Company\Company;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CompanyService
{
    private $company;

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    public function allCompanies()
    {
        $companies = QueryBuilder::for(Company::class)
            ->with('branches')
            ->allowedFilters([
                AllowedFilter::custom('search', new FilterCompany), // Add a custom search filter
                AllowedFilter::exact('status'),
            ])->get();

        return $companies;

    }

    public function createCompany(array $companyData): Company
    {

        $company = Company::create([
            'name' => $companyData['name'],
            'status' => CompanyStatus::from($companyData['status'])->value,
            'legacy_ticket_enabled' => false,
        ]);

        return $company;

    }

    public function editCompany(int $companyId)
    {
        return Company::with('branches')->find($companyId);
    }

    public function updateCompany(array $companyData): Company
    {

        $company = Company::find($companyData['companyId']);

        $attributes = [
            'name' => $companyData['name'],
            'status' => CompanyStatus::from($companyData['status'])->value,
        ];
        if (array_key_exists('legacyTicketEnabled', $companyData)) {
            $attributes['legacy_ticket_enabled'] = $companyData['legacyTicketEnabled'];
        }
        $company->update($attributes);

        return $company;

    }

    public function deleteCompany(int $companyId)
    {

        return Company::find($companyId)->delete();

    }
}
