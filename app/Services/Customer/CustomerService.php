<?php

namespace App\Services\Customer;

use App\Enums\Company\CustomerStatus;
use App\Filters\Customer\FilterCustomer;
use App\Models\Company\Customer;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CustomerService{

    private $customer;
    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    public function allCustomers()
    {
        $customers = QueryBuilder::for(Customer::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FilterCustomer()), // Add a custom search filter
                AllowedFilter::exact('status'),
                AllowedFilter::exact('company', 'company_id'),
            ])->get();

        return $customers;

    }

    public function createCustomer(array $customerData): Customer
    {

        $customer = Customer::create([
            'firstname' => $customerData['firstname'],
            'lastname' => $customerData['lastname'],
            'pin' => $customerData['pin'],
            'company_id' => $customerData['companyId'],
            'status' => CustomerStatus::from($customerData['status'])->value,
            'email' => $customerData['email']??null,
        ]);

        return $customer;

    }

    public function editCustomer(int $customerId)
    {
        return Customer::find($customerId);
    }

    public function updateCustomer(array $customerData): Customer
    {

        $customer = Customer::find($customerData['customerId']);

        $customer->update([
            'firstname' => $customerData['firstname'],
            'lastname' => $customerData['lastname'],
            'pin' => $customerData['pin'],
            'company_id' => $customerData['companyId'],
            'status' => CustomerStatus::from($customerData['status'])->value,
            'email' => $customerData['email']??null,

        ]);

        return $customer;


    }


    public function deleteCustomer(int $customerId)
    {

        return Customer::find($customerId)->delete();

    }


}
