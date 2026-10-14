<?php

namespace App\Http\Controllers\Api\Private\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyContractRequest;
use App\Http\Requests\Company\UpdateCompanyContractRequest;
use App\Http\Resources\Company\CompanyContractCollection;
use App\Http\Resources\Company\CompanyContractResource;
use App\Services\Company\CompanyContractService;
use Illuminate\Http\Request;

class CompanyContractController extends Controller
{
    public function __construct(private readonly CompanyContractService $contracts)
    {
        $this->middleware('auth:api');
        $this->middleware('permission:all_company_contracts', ['only' => ['index', 'show']]);
        $this->middleware('permission:create_company_contract', ['only' => ['create', 'renew']]);
        $this->middleware('permission:update_company_contract', ['only' => ['update']]);
        $this->middleware('permission:delete_company_contract', ['only' => ['delete']]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'companyId' => ['required', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'pageSize' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return response()->json(new CompanyContractCollection(
            $this->contracts->allContracts($data['companyId'], $data['pageSize'] ?? 10),
        ));
    }

    public function show(Request $request)
    {
        $data = $this->contractIdentity($request);

        return response()->json(new CompanyContractResource(
            $this->contracts->show($data['companyId'], $data['contractId']),
        ));
    }

    public function create(StoreCompanyContractRequest $request)
    {
        return response()->json([
            'message' => 'Contract created successfully.',
            'data' => new CompanyContractResource($this->contracts->create($request->validated())),
        ]);
    }

    public function update(UpdateCompanyContractRequest $request)
    {
        return response()->json([
            'message' => 'Contract updated successfully.',
            'data' => new CompanyContractResource($this->contracts->update($request->validated())),
        ]);
    }

    public function renew(Request $request)
    {
        $data = $request->validate(['companyId' => ['required', 'integer', 'min:1']]);

        return response()->json([
            'message' => 'Contract renewed successfully.',
            'data' => new CompanyContractResource($this->contracts->renew($data['companyId'])),
        ]);
    }

    public function delete(Request $request)
    {
        $data = $this->contractIdentity($request);
        $this->contracts->delete($data['companyId'], $data['contractId']);

        return response()->json(['message' => 'Contract deleted successfully.']);
    }

    private function contractIdentity(Request $request): array
    {
        return $request->validate([
            'companyId' => ['required', 'integer', 'min:1'],
            'contractId' => ['required', 'integer', 'min:1'],
        ]);
    }
}
