<?php

namespace App\Policies;

use App\Models\Tiket\Ticket;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('all_tickets');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->can('edit_ticket')
            && $this->tenantContext($user)->canAccessCompany($ticket->company_id);
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $user->can('update_ticket')
            && $this->tenantContext($user)->canAccessCompany($ticket->company_id);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->can('delete_ticket')
            && $this->tenantContext($user)->canAccessCompany($ticket->company_id);
    }

    private function tenantContext(User $user): TenantContext
    {
        return new TenantContext($user);
    }
}
