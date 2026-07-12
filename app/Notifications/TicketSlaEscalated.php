<?php

namespace App\Notifications;

use App\Models\Tiket\Ticket;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketSlaEscalated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Ticket $ticket)
    {
        $this->afterCommit();
    }

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject("SLA exceeded: {$this->ticket->ticket_number}")
            ->line("Ticket {$this->ticket->ticket_number} has exceeded its SLA deadline.")
            ->line("Company: {$this->ticket->company->name}")
            ->line('Deadline: '.Carbon::parse($this->ticket->due_at)->format('d/m/Y H:i'));
    }
}
