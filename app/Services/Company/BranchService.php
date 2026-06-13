<?php

namespace App\Services\Company;

use App\Enums\Company\BranchStatus;
use App\Models\Company\Branch;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class BranchService{

    private $branch;
    public function __construct(Branch $branch)
    {
        $this->branch = $branch;
    }

    /*public function allCountries()
    {
        $user = QueryBuilder::for(Branch::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FilterBranch()), // Add a custom search filter
                AllowedFilter::exact('status'),
            ])->get();

        return $user;

    }*/

    public function createBranch(array $branchData): Branch
    {

        $branch = Branch::create([
            'name' => $branchData['name'],
            'status' => BranchStatus::from($branchData['status'])->value,
            'company_id' => $branchData['companyId']
        ]);

        return $branch;

    }

    public function editBranch(int $branchId)
    {
        return Branch::find($branchId);
    }

    public function updateBranch(array $branchData): Branch
    {

        $branch = Branch::find($branchData['branchId']);

        $branch->update([
            'name' => $branchData['name'],
            'status' => BranchStatus::from($branchData['status'])->value,
            'company_id' => $branchData['companyId']
        ]);

        return $branch;


    }


    public function deleteBranch(int $branchId)
    {

        return Branch::find($branchId)->delete();

    }


}
