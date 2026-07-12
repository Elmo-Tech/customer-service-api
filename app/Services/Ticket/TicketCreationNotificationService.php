<?php

namespace App\Services\Ticket;

use App\Mail\TicketDetails;
use App\Models\Tiket\Ticket;
use Illuminate\Support\Facades\Mail;

class TicketCreationNotificationService
{
    private const INTERNAL_RECIPIENTS = [
        'it-arca@arcagroup.eu',
        'ms5325749@gmail.com',
        'mr10dev10@gmail.com',
        's.mohamed@elmotech.it',
    ];

    public function send(Ticket $ticket, array $attachmentPaths): void
    {
        $ticket->loadMissing(['customer', 'openedBy', 'company']);
        $mailContent = [
            'subject' => $this->subject($ticket),
            'body' => $ticket->description,
            'attachments' => $attachmentPaths,
        ];

        foreach (self::INTERNAL_RECIPIENTS as $recipient) {
            Mail::to($recipient)->send(new TicketDetails($mailContent));
        }

        if ($ticket->requesterEmail()) {
            Mail::to($ticket->requesterEmail())->send(new TicketDetails($mailContent));
        }
    }

    private function subject(Ticket $ticket): string
    {
        $importance = [0 => 'Verde', 1 => 'Giallo', 2 => 'Rosso'];

        return "ticket: {$ticket->ticket_number} | {$importance[$ticket->importance]} | "
            .$ticket->requesterName()." | {$ticket->company->name}";
    }
}
