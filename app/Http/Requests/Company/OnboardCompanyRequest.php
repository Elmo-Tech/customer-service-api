<?php

namespace App\Http\Requests\Company;

use App\Enums\Company\CompanyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OnboardCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company.name' => ['required', 'string', 'max:255'],
            'company.status' => ['required', Rule::enum(CompanyStatus::class)],
            'company.usesBranches' => ['required', 'boolean'],
            'branches' => ['array'],
            'branches.*.key' => ['required', 'string', 'max:64', 'distinct'],
            'branches.*.name' => ['required', 'string', 'max:255'],
            'owner' => ['required', 'array'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'owner.email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'owner.roleId' => ['required', 'integer', 'exists:roles,id'],
            'owner.branchKey' => ['nullable', 'string'],
            'accounts' => ['array'],
            'accounts.*.name' => ['required', 'string', 'max:255'],
            'accounts.*.username' => ['required', 'string', 'max:255', 'distinct', 'unique:users,username'],
            'accounts.*.email' => ['required', 'email', 'max:255', 'distinct', 'unique:users,email'],
            'accounts.*.roleId' => ['required', 'integer', 'exists:roles,id'],
            'accounts.*.branchKey' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $owner = $this->input('owner', []);
            $accounts = $this->input('accounts', []);
            if (collect($accounts)->contains('username', $owner['username'] ?? null)) {
                $validator->errors()->add('owner.username', 'Owner username must be unique in the request.');
            }
            if (collect($accounts)->contains('email', $owner['email'] ?? null)) {
                $validator->errors()->add('owner.email', 'Owner email must be unique in the request.');
            }
        });
    }
}
