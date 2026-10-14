<?php

namespace App\Http\Requests\Branch;

use App\Enums\Company\BranchStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', new Enum(BranchStatus::class)],
            'companyId' => ['required', 'integer', 'min:1'],
        ];
    }
}
