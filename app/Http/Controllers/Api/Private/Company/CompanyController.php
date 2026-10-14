<?php

namespace App\Http\Controllers\Api\Private\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateCompanyRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\Company\AllCompanyCollection;
use App\Http\Resources\Company\CompanyResource;
use App\Services\Company\CompanyService;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyService $companyService)
    {
        $this->middleware('auth:api');
        $this->middleware('permission:all_companies', ['only' => ['allCompanies']]);
        $this->middleware('permission:create_company', ['only' => ['create']]);
        $this->middleware('permission:edit_company', ['only' => ['edit']]);
        $this->middleware('permission:update_company', ['only' => ['update']]);
        $this->middleware('permission:delete_company', ['only' => ['delete']]);
    }

    public function allCompanies(Request $request)
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'pageSize' => ['sometimes', 'integer', 'between:1,100'],
            'filter' => ['sometimes', 'array'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.status' => ['sometimes', 'integer', 'in:0,1'],
        ]);

        return response()->json(new AllCompanyCollection(
            $this->companyService->allCompanies($data['pageSize'] ?? 10),
        ));
    }

    public function create(CreateCompanyRequest $request)
    {
        $created = $this->companyService->createWithCustomer($request->validated());
        $customer = $created['customer'];

        return response()->json([
            'message' => 'Company created successfully.',
            'data' => [
                ...(new CompanyResource($created['company']))->resolve($request),
                'customer' => [
                    'customerId' => $customer->id,
                    'companyId' => $customer->company_id,
                    'firstname' => $customer->firstname,
                    'lastname' => $customer->lastname,
                    'username' => $customer->username,
                    'email' => $customer->email,
                    'status' => $customer->status,
                ],
            ],
        ]);
    }

    public function edit(Request $request)
    {
        $data = $request->validate(['companyId' => ['required', 'integer', 'min:1']]);

        return response()->json(new CompanyResource($this->companyService->editCompany($data['companyId'])));
    }

    public function update(UpdateCompanyRequest $request)
    {
        $this->companyService->updateCompany($request->validated());

        return response()->json(['message' => 'Company updated successfully.']);
    }

    public function delete(Request $request)
    {
        $data = $request->validate(['companyId' => ['required', 'integer', 'min:1']]);
        $this->companyService->deleteCompany($data['companyId']);

        return response()->json(['message' => 'Company deleted successfully.']);
    }
}
