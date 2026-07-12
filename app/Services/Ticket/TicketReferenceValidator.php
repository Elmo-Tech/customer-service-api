<?php

namespace App\Services\Ticket;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use Illuminate\Validation\ValidationException;

class TicketReferenceValidator
{
    public function validate(int $companyId, int $customerId, ?int $branchId): void
    {
        $company = Company::query()->findOrFail($companyId);
        $this->validateActiveCompany($company);
        $this->validateCustomer($companyId, $customerId);

        if ($company->uses_branches) {
            $this->validateRequiredBranch($companyId, $branchId);

            return;
        }

        if ($branchId !== null) {
            throw ValidationException::withMessages(['branchId' => 'Branch must be empty for this company.']);
        }
    }

    private function validateCustomer(int $companyId, int $customerId): void
    {
        $customerExists = Customer::query()
            ->whereKey($customerId)
            ->where('company_id', $companyId)
            ->where('status', CustomerStatus::ACTIVE->value)
            ->exists();

        if (! $customerExists) {
            throw ValidationException::withMessages(['customerId' => 'Customer does not belong to the ticket company.']);
        }
    }

    private function validateActiveCompany(Company $company): void
    {
        if ((int) $company->status !== CompanyStatus::ACTIVE->value) {
            throw ValidationException::withMessages(['companyId' => 'Ticket company is inactive.']);
        }
    }

    private function validateRequiredBranch(int $companyId, ?int $branchId): void
    {
        $branchExists = $branchId !== null && Branch::query()
            ->whereKey($branchId)
            ->where('company_id', $companyId)
            ->where('status', BranchStatus::ACTIVE->value)
            ->exists();

        if (! $branchExists) {
            throw ValidationException::withMessages(['branchId' => 'An active company branch is required.']);
        }
    }
}
