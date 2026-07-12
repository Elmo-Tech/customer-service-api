<?php

namespace App\Http\Requests\Ticket;

use App\Enums\Ticket\TicketImportanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateAuthenticatedTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'importance' => ['required', new Enum(TicketImportanceStatus::class)],
            'description' => ['required', 'string'],
            'customerId' => ['required', 'integer'],
            'branchId' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],
            'tagId' => ['nullable', 'integer'],
        ];
    }
}
