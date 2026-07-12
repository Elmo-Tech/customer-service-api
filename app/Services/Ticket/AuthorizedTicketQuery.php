<?php

namespace App\Services\Ticket;

use App\Enums\Ticket\TicketStatus;
use App\Models\Tiket\Ticket;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

class AuthorizedTicketQuery
{
    public function filtered(User $user, array $filters): Builder
    {
        $query = (new TenantContext($user))->scopeTickets(Ticket::query());

        foreach ($filters as $filterName => $filterValue) {
            $this->applyFilter($query, $filterName, $filterValue);
        }

        return $query;
    }

    private function applyFilter(Builder $query, string $filterName, mixed $filterValue): void
    {
        match ($filterName) {
            'search' => $query->where('ticket_number', 'like', "%{$filterValue}%"),
            'status' => $this->status($query, (int) $filterValue),
            'importance' => $query->where('importance', $filterValue),
            'company' => $query->where('company_id', $filterValue),
            'branch' => $query->where('branch_id', $filterValue),
            'customer' => $query->where('customer_id', $filterValue),
            'tag' => $query->where('tag_id', $filterValue),
            'assignee' => $query->where('assigned_to_user_id', $filterValue),
            'fromDate' => $query->whereDate('real_closed_at', '>=', $filterValue),
            'toDate' => $query->whereDate('real_closed_at', '<=', $filterValue),
        };
    }

    private function status(Builder $query, int $status): void
    {
        if ($status === TicketStatus::OPENED->value) {
            $query->whereIn('status', [TicketStatus::OPENED->value, TicketStatus::REOPENED->value]);

            return;
        }

        $query->where('status', $status);
    }
}
