<?php

namespace App\Http\Requests\Company;

use App\Enums\Company\CompanyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'companyId' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255', Rule::unique('companies', 'name')->ignore($this->integer('companyId'))],
            'status' => ['required', new Enum(CompanyStatus::class)],
        ];
    }
}
