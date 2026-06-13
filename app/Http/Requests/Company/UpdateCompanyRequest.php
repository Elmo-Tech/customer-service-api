<?php

namespace App\Http\Requests\Company;

use App\Enums\Company\CompanyStatus;
use App\Enums\Country\BaycottStatus;
use App\Enums\Country\CountryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rules\Enum;


class UpdateCompanyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'companyId' => 'required',
            'name' =>['required', "unique:companies,name,{$this->companyId}"],
            'status' => ['required', new Enum(CompanyStatus::class)],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()
        ], 401));
    }

}
