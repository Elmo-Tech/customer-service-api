<?php

namespace App\Enums\Ticket;

enum TicketStatus: int{

    case DONE = 1;
    case IN_PROGRESS = 2;
    case OPENED = 0;

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
