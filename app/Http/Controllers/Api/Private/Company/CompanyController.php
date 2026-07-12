<?php

namespace App\Http\Controllers\Api\Private\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\Company\AllCompanyCollection;
use App\Http\Resources\Company\CompanyResource;
use App\Services\Company\BranchService;
use App\Services\Company\CompanyService;
use App\Services\Export\ResourceCsvExporter;
use App\Utils\PaginateCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    public function __construct(
        private readonly CompanyService $companyService,
        private readonly BranchService $branchService,
        private readonly ResourceCsvExporter $csv,
    ) {
        $this->middleware('auth:api');
        $this->middleware('permission:all_companies')->only('allCompanies');
        $this->middleware('permission:create_company')->only('create');
        $this->middleware('permission:edit_company')->only('edit');
        $this->middleware('permission:update_company')->only('update');
        $this->middleware('permission:delete_company')->only('delete');
        $this->middleware('permission:export_companies|all_companies')->only('export');
    }

    public function export(Request $request)
    {
        $companies = $this->companyService->allCompanies($request->user());

        return $this->csv->response('companies', ['Name', 'Status', 'Branch Mode', 'Branch Count'],
            $companies->map(fn ($company) => [
                $company->name, $company->status, $company->uses_branches ? 'Enabled' : 'Branchless',
                $company->branches->count(),
            ]),
        );
    }

    public function allCompanies(Request $request): JsonResponse
    {
        $companies = $this->companyService->allCompanies($request->user());

        return response()->json(new AllCompanyCollection(
            PaginateCollection::paginate($companies, $request->integer('pageSize', 10)),
        ));
    }

    public function create(CreateCompanyRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $companyFields = $request->validated();
            $company = $this->companyService->createCompany($request->user(), $companyFields);

            foreach ($companyFields['branches'] ?? [] as $branchFields) {
                $this->branchService->createBranch($request->user(), [
                    ...$branchFields,
                    'companyId' => $company->id,
                ]);
            }
        });

        return response()->json(['message' => 'تم اضافة شركة جديد بنجاح']);
    }

    public function edit(Request $request): JsonResponse
    {
        $company = $this->companyService->editCompany($request->user(), $request->integer('companyId'));

        return response()->json(new CompanyResource($company));
    }

    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->companyService->updateCompany($request->user(), $request->validated()));

        return response()->json(['message' => 'تم تحديث بيانات الشركة!']);
    }

    public function delete(Request $request): JsonResponse
    {
        DB::transaction(fn () => $this->companyService->deleteCompany(
            $request->user(),
            $request->integer('companyId'),
        ));

        return response()->json(['message' => 'تم حذف البلد بنجاح!']);
    }
}
