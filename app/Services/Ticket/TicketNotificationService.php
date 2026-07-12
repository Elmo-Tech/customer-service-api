<?php

namespace App\Services\Ticket;

use App\Enums\Ticket\TicketStatus;
use App\Mail\ClosedTicketDetails;
use App\Mail\TicketDetails;
use App\Models\Tiket\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class TicketNotificationService
{
    public function __construct(
        private readonly TicketAttachmentService $attachmentService,
        private readonly TicketReviewCapabilityService $reviewCapabilities,
    ) {}

    private const INTERNAL_RECIPIENTS = [
        'it-arca@arcagroup.eu',
        'mr10dev10@gmail.com',
        's.mohamed@elmotech.it',
    ];

    public function sendStatusUpdate(Ticket $ticket): void
    {
        if (! in_array((int) $ticket->status, [TicketStatus::IN_PROGRESS->value, TicketStatus::DONE->value], true)) {
            return;
        }

        $ticket->loadMissing(['customer', 'company', 'attachments']);
        $mailContent = $this->mailContent($ticket);
        $this->sendInternalUpdate($mailContent);

        if ((int) $ticket->status === TicketStatus::DONE->value) {
            $this->sendClosureRequest($ticket, $mailContent);

            return;
        }

        $this->sendCustomerUpdate($ticket, $mailContent);
    }

    private function mailContent(Ticket $ticket): array
    {
        return [
            'subject' => $this->subject($ticket),
            'body' => $this->body($ticket),
            'attachments' => $ticket->attachments
                ->map(fn ($attachment) => $this->attachmentService->mailDescriptor($attachment))->all(),
        ];
    }

    private function subject(Ticket $ticket): string
    {
        $importance = [0 => 'Verde', 1 => 'Giallo', 2 => 'Rosso'];

        return "ticket: {$ticket->ticket_number} | {$importance[$ticket->importance]} | "
            .$ticket->customer->getFullName()." | {$ticket->company->name}";
    }

    private function body(Ticket $ticket): string
    {
        $status = [TicketStatus::DONE->value => 'Chiusa', TicketStatus::IN_PROGRESS->value => 'In corso'];
        $eventDate = (int) $ticket->status === TicketStatus::DONE->value ? $ticket->closed_at : $ticket->updated_at;

        return "stato: {$status[$ticket->status]} | data: "
            .Carbon::parse($eventDate)->format('d/m/Y H:m')."<br><br><br>{$ticket->description}";
    }

    private function sendInternalUpdate(array $mailContent): void
    {
        foreach (self::INTERNAL_RECIPIENTS as $recipient) {
            Mail::to($recipient)->send(new TicketDetails($mailContent));
        }
    }

    private function sendClosureRequest(Ticket $ticket, array $mailContent): void
    {
        $mailContent['token'] = $this->reviewCapabilities->issue($ticket);
        $mailContent['ticketId'] = $ticket->id;
        Mail::to('mr10dev10@gmail.com')->send(new ClosedTicketDetails($mailContent));

        if ($ticket->customer->email) {
            Mail::to($ticket->customer->email)->send(new ClosedTicketDetails($mailContent));
        }
    }

    private function sendCustomerUpdate(Ticket $ticket, array $mailContent): void
    {
        if ($ticket->customer->email) {
            Mail::to($ticket->customer->email)->send(new TicketDetails($mailContent));
        }
    }
}
