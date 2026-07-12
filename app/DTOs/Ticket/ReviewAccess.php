<?php

namespace App\DTOs\Ticket;

use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketReviewCapability;

readonly class ReviewAccess
{
    public function __construct(
        public Ticket $ticket,
        public ?TicketReviewCapability $capability,
    ) {}
}
