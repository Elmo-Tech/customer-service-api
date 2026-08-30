<?php

namespace App\Http\Requests\Ticket;

use App\Enums\Ticket\TicketStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateCustomerTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ticketId' => ['required', 'integer', 'exists:tickets,id'],
            'timelineToken' => ['required', 'string'],
            'status' => ['required', 'integer', Rule::in([
                TicketStatus::DONE->value,
                TicketStatus::REOPENED->value,
            ])],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors(),
        ], 401));
    }
}
