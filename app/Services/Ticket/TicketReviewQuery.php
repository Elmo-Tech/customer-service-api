<?php

namespace App\Services\Ticket;

use App\Models\Tiket\Ticket;

class TicketReviewQuery
{
    public function __construct(private readonly TicketReviewCapabilityService $capabilities) {}

    public function ticket(int $ticketId, string $token, array $relations = []): Ticket
    {
        return $this->capabilities->ticket($ticketId, $token, $relations);
    }
}
