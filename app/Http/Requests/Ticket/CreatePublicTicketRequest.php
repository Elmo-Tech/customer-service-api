<?php

namespace App\Http\Requests\Ticket;

use App\Enums\Ticket\TicketImportanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreatePublicTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ticketToken' => ['required', 'string'],
            'importance' => ['required', new Enum(TicketImportanceStatus::class)],
            'description' => ['required', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png', 'max:2048'],
            'tagId' => [
                'nullable',
                'integer',
                Rule::exists('parameter_values', 'id')
                    ->where('parameter_id', 1)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
