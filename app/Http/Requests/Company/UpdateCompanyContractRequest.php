<?php

namespace App\Http\Requests\Company;

class UpdateCompanyContractRequest extends StoreCompanyContractRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'contractId' => ['required', 'integer', 'min:1']];
    }
}
