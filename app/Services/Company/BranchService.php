<?php

namespace App\Services\Company;

use App\Enums\Company\BranchStatus;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public function createBranch(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $company = Company::whereKey($data['companyId'])->lockForUpdate()->firstOrFail();

            return $company->branches()->create([
                'name' => $data['name'],
                'status' => BranchStatus::from($data['status'])->value,
            ]);
        });
    }

    public function editBranch(int $branchId): Branch
    {
        return Branch::whereHas('company')->findOrFail($branchId);
    }

    public function updateBranch(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $branch = $this->lockBranch($data['branchId']);
            $branch->update([
                'name' => $data['name'],
                'status' => BranchStatus::from($data['status'])->value,
            ]);

            return $branch;
        });
    }

    public function deleteBranch(int $branchId): void
    {
        DB::transaction(function () use ($branchId) {
            $this->lockBranch($branchId)->delete();
        });
    }

    private function lockBranch(int $branchId): Branch
    {
        $branch = $this->editBranch($branchId);
        Company::whereKey($branch->company_id)->lockForUpdate()->firstOrFail();

        return Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();
    }
}
