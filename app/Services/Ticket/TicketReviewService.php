<?php

namespace App\Services\Ticket;

use App\Enums\Ticket\TicketStatus;
use App\Mail\TicketDetails;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TicketReviewService
{
    public function __construct(
        private readonly TicketAttachmentService $attachmentService,
        private readonly TicketReviewCapabilityService $capabilities,
    ) {}

    private const REOPEN_RECIPIENTS = [
        'it-arca@arcagroup.eu',
        'ms5325749@gmail.com',
        'mr10dev10@gmail.com',
        's.mohamed@elmotech.it',
    ];

    public function record(int $ticketId, string $token, int $status, string $text): void
    {
        DB::transaction(function () use ($ticketId, $token, $status, $text): void {
            $access = $this->capabilities->lockedAccess($ticketId, $token);
            $ticket = $access->ticket;
            TicketLog::create([
                'ticket_id' => $ticketId,
                'status' => $status,
                'text' => $status === TicketStatus::DONE->value ? 'Grazie, il ticket è chiuso.' : $text,
                'token' => null,
            ]);
            $this->applyReviewStatus($ticket, $status);
            $access->capability?->update(['consumed_at' => now()]);

            if ($status === TicketStatus::REOPENED->value) {
                $this->sendReopenedNotification($ticket, $text);
            }
        });
    }

    private function applyReviewStatus(Ticket $ticket, int $status): void
    {
        $ticket->status = $status;
        $ticket->token = null;
        $ticket->closed_at = $status === TicketStatus::REOPENED->value ? null : $ticket->closed_at;
        $ticket->save();
    }

    private function sendReopenedNotification(Ticket $ticket, string $text): void
    {
        $ticket->load(['customer', 'openedBy', 'company', 'attachments']);
        $mailContent = [
            'subject' => 'ticket: '.$ticket->ticket_number.' | Riaperta | '
                .$ticket->requesterName().' | '.$ticket->company->name,
            'body' => 'stato: Riaperta | data: '.Carbon::parse($ticket->updated_at)->format('d/m/Y H:m')
                .'<br><br><br>'.$text,
            'attachments' => $ticket->attachments
                ->map(fn ($attachment) => $this->attachmentService->mailDescriptor($attachment))->all(),
        ];

        foreach (self::REOPEN_RECIPIENTS as $recipient) {
            Mail::to($recipient)->send(new TicketDetails($mailContent));
        }
    }
}
