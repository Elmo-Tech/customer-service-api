<?php

namespace App\Http\Requests\Ticket;

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Enum;

class UpdateTicketRequest extends FormRequest
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
            'ticketId' => ['required'],
            'status' => ['required', new Enum(TicketStatus::class)],
            'importance' => ['required', new Enum(TicketImportanceStatus::class)],
            'description' => ['required', 'string'],
            'companyId' => ['required'],
            'branchId' => ['nullable', 'integer'],
            'customerId' => ['required'],
            'tagId' => ['nullable'],
            'closedAt' => ['nullable'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors(),
        ], 401));
    }
}
