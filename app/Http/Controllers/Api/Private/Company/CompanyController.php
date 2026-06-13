<?php

namespace App\Http\Controllers\Api\Private\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\Company\AllCompanyCollection;
use App\Http\Resources\Company\CompanyResource;
use App\Models\Company\Branch;
use App\Services\Company\BranchService;
use App\Services\Company\CompanyService;
use App\Utils\PaginateCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class CompanyController extends Controller
{
    protected $companyService;
    protected $branchService;

    public function __construct(CompanyService $companyService, BranchService $branchService)
    {
        $this->middleware('auth:api');
        $this->middleware('permission:all_companies', ['only' => ['allCompanies']]);
        $this->middleware('permission:create_company', ['only' => ['create']]);
        $this->middleware('permission:edit_company', ['only' => ['edit']]);
        $this->middleware('permission:update_company', ['only' => ['update']]);
        $this->middleware('permission:delete_company', ['only' => ['delete']]);
        $this->companyService = $companyService;
        $this->branchService = $branchService;
    }

    /**
     * Display a listing of the resource.
     */
    public function allCompanies(Request $request)
    {
        $allCompanies = $this->companyService->allCompanies();

        return response()->json(
            new AllCompanyCollection(PaginateCollection::paginate($allCompanies, $request->pageSize?$request->pageSize:10))
        , 200);

    }

    /**
     * Show the form for creating a new resource.
     */

    public function create(CreateCompanyRequest $createCompanyRequest)
    {

        try {
            DB::beginTransaction();

            $company = $this->companyService->createCompany($createCompanyRequest->validated());

            foreach ($createCompanyRequest->validated()['branches'] as $key => $branch) {

                $this->branchService->createBranch([...$branch, 'companyId' => $company->id]);

            }

            DB::commit();

            return response()->json([
                'message' => 'تم اضافة شركة جديد بنجاح'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

    /**
     * Show the form for editing the specified resource.
     */

    public function edit(Request $request)
    {
        $company  =  $this->companyService->editCompany($request->companyId);

        return response()->json(
            new CompanyResource($company)//new UserResource($user)
        ,200);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCompanyRequest $updateCompanyRequest)
    {

        try {
            DB::beginTransaction();

            $company = $this->companyService->updateCompany($updateCompanyRequest->validated());

            $companyFirstBranch = Branch::where('company_id', $company->id)->first();

            $this->branchService->updateBranch([
                'branchId' => $companyFirstBranch->id,
                'name' => $company->name . "-" . "main",
                'status' => $company->status,
                'companyId' => $company->id
            ]);

            DB::commit();
            return response()->json([
                 'message' => 'تم تحديث بيانات الشركة!'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(Request $request)
    {

        try {
            DB::beginTransaction();
            $this->companyService->deleteCompany($request->companyId);
            DB::commit();
            return response()->json([
                'message' => 'تم حذف البلد بنجاح!'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

}
