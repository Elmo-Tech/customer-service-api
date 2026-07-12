<?php

namespace App\Services\Ticket;

use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class TicketReviewCookie
{
    public function make(int $ticketId, string $secret): SymfonyCookie
    {
        return Cookie::make(
            $this->name($ticketId),
            $secret,
            config('review_capabilities.ttl_minutes'),
            "/api/v1/public/tickets/{$ticketId}",
            null,
            true,
            true,
            false,
            'none',
        );
    }

    public function name(int $ticketId): string
    {
        return "ticket_review_{$ticketId}";
    }
}
