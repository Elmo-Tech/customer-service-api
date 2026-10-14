<?php

namespace App\Http\Requests\Company;

use App\Enums\Company\CompanyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:companies,name'],
            'status' => ['required', new Enum(CompanyStatus::class)],
            'customer' => ['required', 'array:firstname,lastname,username,email,pin'],
            'customer.firstname' => ['required', 'string', 'max:255'],
            'customer.lastname' => ['required', 'string', 'max:255'],
            'customer.username' => ['required', 'string', 'max:255', 'unique:customers,username'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.pin' => ['required', 'string', 'max:255'],
            'contract' => ['sometimes', 'array:startDate,endDate', 'min:2'],
            'contract.startDate' => ['required_with:contract', 'date_format:Y-m-d', 'before_or_equal:today'],
            'contract.endDate' => ['required_with:contract', 'date_format:Y-m-d', 'after_or_equal:contract.startDate'],
        ];
    }
}
