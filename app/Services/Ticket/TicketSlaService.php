<?php

namespace App\Services\Ticket;

use Carbon\CarbonInterface;

class TicketSlaService
{
    private const HOURS_BY_IMPORTANCE = [
        0 => 72,
        1 => 24,
        2 => 8,
    ];

    public function dueAt(int $importance, CarbonInterface $startedAt): CarbonInterface
    {
        return $startedAt->copy()->addHours(self::HOURS_BY_IMPORTANCE[$importance]);
    }

    public function targets(): array
    {
        return collect(self::HOURS_BY_IMPORTANCE)->map(
            fn (int $hours, int $importance) => ['importance' => $importance, 'hours' => $hours],
        )->values()->all();
    }
}
