<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'companyId' => ['required', 'integer', 'min:1'],
            'startDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'endDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:startDate'],
        ];
    }
}
