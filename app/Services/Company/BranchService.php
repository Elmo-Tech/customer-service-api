<?php

namespace App\Services\Company;

use App\Enums\Company\BranchStatus;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class BranchService
{
    public function createBranch(User $caller, array $branchFields): Branch
    {
        $context = $this->managementContext($caller);
        $companyId = $context->isInternal()
            ? (int) $branchFields['companyId']
            : $context->tenantCompanyId();
        $this->assertBranchCreationAllowed($context, $companyId);

        return Branch::create([
            'name' => $branchFields['name'],
            'status' => BranchStatus::from($branchFields['status'])->value,
            'company_id' => $companyId,
        ]);
    }

    public function editBranch(User $caller, int $branchId): Branch
    {
        return (new TenantContext($caller))->scopeBranches(Branch::query())->findOrFail($branchId);
    }

    public function updateBranch(User $caller, array $branchFields): Branch
    {
        $context = $this->managementContext($caller);
        $branch = $context->scopeBranches(Branch::query())->findOrFail($branchFields['branchId']);
        $this->assertUnchangedCompany($branch, $branchFields);
        $branch->update([
            'name' => $branchFields['name'],
            'status' => BranchStatus::from($branchFields['status'])->value,
        ]);

        return $branch;
    }

    public function deleteBranch(User $caller, int $branchId): bool
    {
        $context = $this->managementContext($caller);

        return (bool) $context->scopeBranches(Branch::query())->findOrFail($branchId)->delete();
    }

    private function managementContext(User $caller): TenantContext
    {
        $context = new TenantContext($caller);

        if (! $context->canManageCompany()) {
            throw new AuthorizationException;
        }

        return $context;
    }

    private function assertBranchCreationAllowed(TenantContext $context, int $companyId): void
    {
        if (! $context->canAccessCompany($companyId)) {
            throw ValidationException::withMessages(['companyId' => 'Company is outside the authorized scope.']);
        }

        if (! Company::query()->findOrFail($companyId)->uses_branches) {
            throw ValidationException::withMessages(['companyId' => 'This company does not use branches.']);
        }
    }

    private function assertUnchangedCompany(Branch $branch, array $branchFields): void
    {
        if (isset($branchFields['companyId']) && (int) $branchFields['companyId'] !== (int) $branch->company_id) {
            throw ValidationException::withMessages(['companyId' => 'Branch company cannot be changed.']);
        }
    }
}
