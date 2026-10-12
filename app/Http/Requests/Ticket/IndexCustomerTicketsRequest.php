<?php

namespace App\Http\Requests\Ticket;

use App\Enums\Ticket\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCustomerTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'pageSize' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array:status,description'],
            'filter.status' => ['nullable', 'integer', Rule::in(TicketStatus::values())],
            'filter.description' => ['nullable', 'string'],
        ];
    }
}
