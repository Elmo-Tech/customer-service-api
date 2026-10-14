<?php

namespace App\Services\Company;

use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Filters\Company\FilterCompany;
use App\Models\Company\Company;
use App\Services\Customer\CustomerService;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CompanyService
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CompanyContractService $contracts,
    ) {}

    public function allCompanies(int $pageSize = 10)
    {
        return QueryBuilder::for(Company::class)
            ->with(['activeContract', 'latestContract'])
            ->allowedFilters([
                AllowedFilter::custom('search', new FilterCompany),
                AllowedFilter::exact('status'),
            ])->orderBy('id')->paginate($pageSize);
    }

    public function createCompany(array $companyData): Company
    {
        return Company::create([
            'name' => $companyData['name'],
            'status' => CompanyStatus::from($companyData['status'])->value,
            'legacy_ticket_enabled' => false,
        ]);
    }

    public function createWithCustomer(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $company = $this->createCompany($data);
            $company->branches()->create(['name' => $company->name, 'status' => $company->status]);
            $customer = $this->customers->createCustomer([
                ...$data['customer'],
                'companyId' => $company->id,
                'status' => CustomerStatus::ACTIVE->value,
            ]);
            if (isset($data['contract'])) {
                $this->contracts->create([...$data['contract'], 'companyId' => $company->id]);
            }

            return [
                'company' => $company->load(['activeContract', 'latestContract']),
                'customer' => $customer,
            ];
        });
    }

    public function editCompany(int $companyId): Company
    {
        return Company::with(['activeContract', 'latestContract'])->findOrFail($companyId);
    }

    public function updateCompany(array $data): Company
    {
        return DB::transaction(function () use ($data) {
            $company = Company::whereKey($data['companyId'])->lockForUpdate()->firstOrFail();
            $company->update([
                'name' => $data['name'],
                'status' => CompanyStatus::from($data['status'])->value,
            ]);
            // The oldest surviving branch is the internal default, as in the existing flow.
            $branch = $company->branches()->orderBy('id')->lockForUpdate()->first();
            $attributes = ['name' => $company->name, 'status' => $company->status];
            if ($branch) {
                $branch->update($attributes);
            } else {
                $company->branches()->create($attributes);
            }

            return $company;
        });
    }

    public function deleteCompany(int $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail()->delete();
        });
    }
}
