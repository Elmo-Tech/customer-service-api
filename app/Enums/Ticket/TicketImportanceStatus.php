<?php

namespace App\Enums\Ticket;

enum TicketImportanceStatus: int{


    case GREEN = 0;
    case RED = 1;
    case YELLOW = 2;

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
