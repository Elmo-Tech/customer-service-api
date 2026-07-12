<?php

namespace App\Http\Controllers\Api\Private\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\Customer\AllCustomerCollection;
use App\Http\Resources\Customer\CustomerResource;
use App\Models\Company\Customer;
use App\Services\Customer\CustomerService;
use App\Services\Export\ResourceCsvExporter;
use App\Utils\PaginateCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly ResourceCsvExporter $csv,
    ) {
        $this->middleware('auth:api');
        $this->middleware('permission:all_customers')->only('allCustomers');
        $this->middleware('permission:create_customer')->only('create');
        $this->middleware('permission:edit_customer')->only('edit');
        $this->middleware('permission:update_customer')->only('update');
        $this->middleware('permission:delete_customer')->only('delete');
        $this->middleware('permission:export_customers|all_customers')->only('export');
    }

    public function export(Request $request)
    {
        $customers = $this->customerService->allCustomers($request->user())->load(['company', 'branch']);

        return $this->csv->response('customers', ['Name', 'Email', 'Status', 'Company', 'Branch'],
            $customers->map(fn (Customer $customer) => [
                $customer->getFullName(), $customer->email, $customer->status,
                $customer->company?->name, $customer->branch?->name,
            ]),
        );
    }

    public function allCustomers(Request $request): JsonResponse
    {
        $customers = $this->customerService->allCustomers($request->user());

        return response()->json(new AllCustomerCollection(
            PaginateCollection::paginate($customers, $request->integer('pageSize', 10)),
        ));
    }

    public function create(CreateCustomerRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->customerService->createCustomer($request->user(), $request->validated()));

        return response()->json(['message' => 'تم اضافة حساب جديد بنجاح']);
    }

    public function edit(Request $request): JsonResponse
    {
        $customer = $this->customerService->editCustomer($request->user(), $request->integer('customerId'));

        return response()->json(new CustomerResource($customer));
    }

    public function update(UpdateCustomerRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->customerService->updateCustomer($request->user(), $request->validated()));

        return response()->json(['message' => 'تم تحديث بيانات الحساب!']);
    }

    public function delete(Request $request): JsonResponse
    {
        DB::transaction(fn () => $this->customerService->deleteCustomer(
            $request->user(),
            $request->integer('customerId'),
        ));

        return response()->json(['message' => 'تم حذف الحساب بنجاح!']);
    }
}
