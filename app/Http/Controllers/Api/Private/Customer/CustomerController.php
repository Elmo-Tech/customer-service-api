<?php

namespace App\Http\Controllers\Api\Private\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\Customer\AllCustomerCollection;
use App\Http\Resources\Customer\CustomerResource;
use App\Models\Company\Customer;
use App\Services\Customer\CustomerService;
use App\Utils\PaginateCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class CustomerController extends Controller
{
    protected $customerService;
    public function __construct(CustomerService $customerService)
    {
        $this->middleware('auth:api');
        $this->middleware('permission:all_customers', ['only' => ['allCustomers']]);
        $this->middleware('permission:create_customer', ['only' => ['create']]);
        $this->middleware('permission:edit_customer', ['only' => ['edit']]);
        $this->middleware('permission:update_customer', ['only' => ['update']]);
        $this->middleware('permission:delete_customer', ['only' => ['delete']]);
        $this->customerService = $customerService;
    }

    /**
     * Display a listing of the resource.
     */
    public function allCustomers(Request $request)
    {
        $allCustomers = $this->customerService->allCustomers();

        return response()->json(
            new AllCustomerCollection(PaginateCollection::paginate($allCustomers, $request->pageSize?$request->pageSize:10))
        , 200);

    }

    /**
     * Show the form for creating a new resource.
     */

    public function create(CreateCustomerRequest $createCustomerRequest)
    {

        try {
            DB::beginTransaction();

            $customerData = $createCustomerRequest->validated();

            $customerExists = Customer::where('firstname', $customerData['firstname'])->where('lastname', $customerData['lastname'])->where('company_id', $customerData['companyId'])->first();

            if ($customerExists) {

                return response()->json([
                    'message' => 'تم اضافة هذه الحساب من قبل'
                ], 401);
            }

            $customer = $this->customerService->createCustomer($createCustomerRequest->validated());

            DB::commit();

            return response()->json([
                'message' => 'تم اضافة حساب جديد بنجاح'
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
        $customer  =  $this->customerService->editCustomer($request->customerId);

        return response()->json(
            new CustomerResource($customer)//new UserResource($user)
        ,200);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCustomerRequest $updateCustomerRequest)
    {

        try {
            DB::beginTransaction();

            $customerData = $updateCustomerRequest->validated();
            $customerExists = Customer::where('firstname', $customerData['firstname'])->where('lastname', $customerData['lastname'])->where('company_id', $customerData['companyId'])->whereNot('id', $customerData['customerId'])->first();

            if ($customerExists) {

                return response()->json([
                    'message' => 'تم اضافة هذه الحساب من قبل'
                ]);
            }

            $this->customerService->updateCustomer($customerData);

            DB::commit();
            return response()->json([
                 'message' => 'تم تحديث بيانات الحساب!'
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
            $this->customerService->deleteCustomer($request->customerId);
            DB::commit();
            return response()->json([
                'message' => 'تم حذف الحساب بنجاح!'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

}
