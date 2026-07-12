<?php

namespace App\Services\Ticket;

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Models\Tiket\Ticket;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class TicketService
{
    public function __construct(
        private readonly TicketReferenceValidator $referenceValidator,
        private readonly AuthorizedTicketQuery $ticketQuery,
        private readonly TicketSlaService $sla,
    ) {}

    public function allTickets(User $user, array $filters)
    {
        return $this->ticketQuery->filtered($user, $filters)
            ->with(['customer:id,firstname,lastname', 'openedBy:id,name', 'company:id,name', 'branch:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function createTicket(array $ticketData): Ticket
    {
        $this->referenceValidator->validate(
            $ticketData['companyId'],
            $ticketData['customerId'] ?? null,
            $ticketData['branchId'] ?? null,
        );

        $importance = TicketImportanceStatus::from($ticketData['importance']);

        return Ticket::create([
            'company_id' => $ticketData['companyId'],
            'status' => TicketStatus::from($ticketData['status'])->value,
            'importance' => $importance->value,
            'description' => $ticketData['description'],
            'customer_id' => $ticketData['customerId'] ?? null,
            'branch_id' => $ticketData['branchId'] ?? null,
            'closed_at' => null,
            'tag_id' => $ticketData['tagId'] ?? null,
            'opened_by_user_id' => $ticketData['openedByUserId'] ?? null,
            'real_closed_at' => TicketStatus::from($ticketData['status']) === TicketStatus::DONE ? now() : null,
            'due_at' => $this->sla->dueAt($importance->value, now()),
        ]);
    }

    public function createAuthenticatedTicket(User $user, array $ticketData): Ticket
    {
        $context = new TenantContext($user);
        $companyId = $context->tenantCompanyId();
        $branchId = $user->branch_id
            ? $context->tenantBranchId()
            : ($ticketData['branchId'] ?? null);

        $ticket = $this->createTicket([
            ...$ticketData,
            'companyId' => $companyId,
            'customerId' => null,
            'branchId' => $branchId,
            'status' => TicketStatus::OPENED->value,
            'openedByUserId' => $user->id,
        ]);

        return $ticket;
    }

    public function editTicket(User $user, int $ticketId): Ticket
    {
        return $this->ticketQuery->filtered($user, [])
            ->with(['attachments', 'customer', 'openedBy'])
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
            $ticketData['customerId'] ?? null,
            $ticketData['branchId'] ?? null,
        );
        $ticket->update($this->updatedAttributes($ticket, $ticketData));

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

    private function updatedAttributes(Ticket $ticket, array $ticketData): array
    {
        $status = TicketStatus::from($ticketData['status']);
        $closedAt = $ticketData['closedAt'] ?? ($status === TicketStatus::DONE ? now() : null);

        $importance = TicketImportanceStatus::from($ticketData['importance']);

        return [
            'status' => $status->value,
            'importance' => $importance->value,
            'description' => $ticketData['description'],
            'customer_id' => $ticketData['customerId'] ?? null,
            'branch_id' => $ticketData['branchId'] ?? null,
            'closed_at' => $closedAt,
            'tag_id' => $ticketData['tagId'] ?? null,
            'real_closed_at' => $status === TicketStatus::DONE ? now() : null,
            ...$this->updatedSla($ticket, $status, $importance),
        ];
    }

    private function updatedSla(
        Ticket $ticket,
        TicketStatus $status,
        TicketImportanceStatus $importance,
    ): array {
        $importanceChanged = (int) $ticket->importance !== $importance->value;
        $reopened = (int) $ticket->status === TicketStatus::DONE->value && $status === TicketStatus::REOPENED;
        if (! $importanceChanged && ! $reopened) {
            return ['due_at' => $ticket->due_at, 'escalated_at' => $ticket->escalated_at];
        }

        $startedAt = $reopened || $ticket->due_at === null ? now() : $ticket->created_at;

        return [
            'due_at' => $this->sla->dueAt($importance->value, $startedAt),
            'escalated_at' => null,
        ];
    }
}
