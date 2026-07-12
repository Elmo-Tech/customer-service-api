<?php

namespace App\Services\Ticket;

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class TicketService
{
    public function __construct(
        private readonly TicketReferenceValidator $referenceValidator,
        private readonly AuthorizedTicketQuery $ticketQuery,
    ) {}

    public function allTickets(User $user, array $filters)
    {
        return $this->ticketQuery->filtered($user, $filters)->orderBy('created_at', 'desc')->get();
    }

    public function createTicket(array $ticketData): Ticket
    {
        $this->referenceValidator->validate(
            $ticketData['companyId'],
            $ticketData['customerId'],
            $ticketData['branchId'] ?? null,
        );

        return Ticket::create([
            'company_id' => $ticketData['companyId'],
            'status' => TicketStatus::from($ticketData['status'])->value,
            'importance' => TicketImportanceStatus::from($ticketData['importance'])->value,
            'description' => $ticketData['description'],
            'customer_id' => $ticketData['customerId'],
            'branch_id' => $ticketData['branchId'] ?? null,
            'closed_at' => null,
            'tag_id' => $ticketData['tagId'] ?? null,
            'opened_by_user_id' => $ticketData['openedByUserId'] ?? null,
            'real_closed_at' => TicketStatus::from($ticketData['status']) === TicketStatus::DONE ? now() : null,
        ]);
    }

    public function createAuthenticatedTicket(User $user, array $ticketData): Ticket
    {
        $context = new TenantContext($user);
        $companyId = $context->tenantCompanyId();
        $context->scopeCustomers(Customer::query())->findOrFail($ticketData['customerId']);
        $branchId = $user->branch_id
            ? $context->tenantBranchId()
            : ($ticketData['branchId'] ?? null);

        $ticket = $this->createTicket([
            ...$ticketData,
            'companyId' => $companyId,
            'branchId' => $branchId,
            'status' => TicketStatus::OPENED->value,
            'openedByUserId' => $user->id,
        ]);

        return $ticket;
    }

    public function editTicket(User $user, int $ticketId): Ticket
    {
        return $this->ticketQuery->filtered($user, [])
            ->with(['attachments', 'customer'])
            ->findOrFail($ticketId);
    }

    public function findTicket(User $user, int $ticketId): Ticket
    {
        return $this->ticketQuery->filtered($user, [])->findOrFail($ticketId);
    }

    public function updateTicket(User $user, array $ticketData): Ticket
    {
        $ticket = $this->ticketQuery->filtered($user, [])->findOrFail($ticketData['ticketId']);
        $this->validateImmutableCompany($ticket, $ticketData);
        $this->referenceValidator->validate(
            $ticket->company_id,
            $ticketData['customerId'],
            $ticketData['branchId'] ?? null,
        );
        $ticket->update($this->updatedAttributes($ticketData));

        return $ticket;
    }

    public function deleteTicket(User $user, int $ticketId): bool
    {
        return (bool) $this->ticketQuery->filtered($user, [])->findOrFail($ticketId)->delete();
    }

    private function validateImmutableCompany(Ticket $ticket, array $ticketData): void
    {
        if ((int) $ticketData['companyId'] !== (int) $ticket->company_id) {
            throw ValidationException::withMessages(['companyId' => 'Ticket company cannot be changed.']);
        }
    }

    private function updatedAttributes(array $ticketData): array
    {
        $status = TicketStatus::from($ticketData['status']);
        $closedAt = $ticketData['closedAt'] ?? ($status === TicketStatus::DONE ? now() : null);

        return [
            'status' => $status->value,
            'importance' => TicketImportanceStatus::from($ticketData['importance'])->value,
            'description' => $ticketData['description'],
            'customer_id' => $ticketData['customerId'],
            'branch_id' => $ticketData['branchId'] ?? null,
            'closed_at' => $closedAt,
            'tag_id' => $ticketData['tagId'] ?? null,
            'real_closed_at' => $status === TicketStatus::DONE ? now() : null,
        ];
    }
}
