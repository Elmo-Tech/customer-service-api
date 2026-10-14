<?php

namespace App\Http\Requests\Branch;

use App\Enums\Company\BranchStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branchId' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', new Enum(BranchStatus::class)],
        ];
    }
}
