<?php

namespace App\Http\Requests\Ticket;

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:search,status,importance,company,branch,customer,tag,assignee,fromDate,toDate'],
            'filter.search' => ['nullable', 'string', 'max:255'],
            'filter.status' => ['nullable', 'integer', Rule::enum(TicketStatus::class)],
            'filter.importance' => ['nullable', 'integer', Rule::enum(TicketImportanceStatus::class)],
            'filter.company' => ['nullable', 'integer'],
            'filter.branch' => ['nullable', 'integer'],
            'filter.customer' => ['nullable', 'integer'],
            'filter.tag' => ['nullable', 'integer'],
            'filter.assignee' => ['nullable', 'integer'],
            'filter.fromDate' => ['nullable', 'date_format:Y-m-d'],
            'filter.toDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.fromDate'],
        ];
    }

    public function filters(): array
    {
        return array_filter(
            $this->validated('filter', []),
            fn ($filterValue) => $filterValue !== null && $filterValue !== '',
        );
    }
}
