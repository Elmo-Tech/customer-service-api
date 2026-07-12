<?php

namespace App\Services\Ticket;

use App\DTOs\Ticket\ReviewAccess;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketLog;
use App\Models\Tiket\TicketReviewCapability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketReviewCapabilityService
{
    public function issue(Ticket $ticket): string
    {
        return DB::transaction(function () use ($ticket): string {
            $this->revokeActive($ticket);
            $secret = Str::random(64);
            TicketReviewCapability::create($this->attributes($ticket, $secret));

            return $secret;
        });
    }

    private function revokeActive(Ticket $ticket): void
    {
        TicketReviewCapability::query()->where('ticket_id', $ticket->id)
            ->where('purpose', config('review_capabilities.purpose'))
            ->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function attributes(Ticket $ticket, string $secret): array
    {
        return [
            'ticket_id' => $ticket->id,
            'secret_hash' => $this->hash($secret),
            'purpose' => config('review_capabilities.purpose'),
            'expires_at' => now()->addMinutes(config('review_capabilities.ttl_minutes')),
        ];
    }

    public function ticket(int $ticketId, string $secret, array $relations = []): Ticket
    {
        $capability = TicketReviewCapability::query()->where('secret_hash', $this->hash($secret))->first();

        if ($capability) {
            $this->assertActive($capability, $ticketId);

            return Ticket::query()->with($relations)->findOrFail($ticketId);
        }

        return $this->legacyTicket($ticketId, $secret, $relations);
    }

    public function lockedAccess(int $ticketId, string $secret): ReviewAccess
    {
        $capability = TicketReviewCapability::query()->where('secret_hash', $this->hash($secret))
            ->lockForUpdate()->first();

        if ($capability) {
            $this->assertActive($capability, $ticketId);

            return new ReviewAccess(Ticket::query()->lockForUpdate()->findOrFail($ticketId), $capability);
        }

        return new ReviewAccess($this->lockedLegacyTicket($ticketId, $secret), null);
    }

    private function assertActive(TicketReviewCapability $capability, int $ticketId): void
    {
        abort_unless(
            $capability->ticket_id === $ticketId
            && $capability->purpose === config('review_capabilities.purpose')
            && ! $capability->expires_at->isPast()
            && $capability->consumed_at === null
            && $capability->revoked_at === null,
            404,
        );
    }

    private function legacyTicket(int $ticketId, string $secret, array $relations): Ticket
    {
        $this->assertUnusedLegacy($ticketId, $secret);

        return Ticket::query()->with($relations)->whereKey($ticketId)->where('token', $secret)->firstOrFail();
    }

    private function lockedLegacyTicket(int $ticketId, string $secret): Ticket
    {
        $this->assertUnusedLegacy($ticketId, $secret);

        return Ticket::query()->whereKey($ticketId)->where('token', $secret)->lockForUpdate()->firstOrFail();
    }

    private function assertUnusedLegacy(int $ticketId, string $secret): void
    {
        $used = TicketLog::query()
            ->where('ticket_id', $ticketId)->where('token', $secret)->exists();
        abort_if($used, 404);
    }

    private function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
