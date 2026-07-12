<?php

namespace App\Services\Ticket;

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Models\Tiket\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketExportService
{
    public function __construct(private readonly AuthorizedTicketQuery $ticketQuery) {}

    public function download(User $user, array $filters): StreamedResponse
    {
        $tickets = $this->ticketQuery->filtered($user, $filters)
            ->with(['customer:id,firstname,lastname', 'openedBy:id,name', 'company:id,name', 'branch:id,name']);

        return response()->streamDownload(
            fn () => $this->writeCsv($tickets),
            'tickets-'.now()->format('Y-m-d').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function writeCsv(Builder $tickets): void
    {
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $this->headers());

        foreach ($tickets->lazyById(500) as $ticket) {
            fputcsv($output, $this->row($ticket));
        }

        fclose($output);
    }

    private function headers(): array
    {
        return ['Ticket Number', 'Customer', 'Company', 'Branch', 'Status', 'Importance', 'Description', 'Created At', 'Closed At'];
    }

    private function row(Ticket $ticket): array
    {
        return array_map($this->safeCell(...), [
            $ticket->ticket_number,
            $ticket->requesterName(),
            $ticket->company?->name ?? '',
            $ticket->branch?->name ?? '',
            TicketStatus::from((int) $ticket->getRawOriginal('status'))->name,
            TicketImportanceStatus::from((int) $ticket->getRawOriginal('importance'))->name,
            strip_tags($ticket->description),
            Carbon::parse($ticket->created_at)->toDateTimeString(),
            $ticket->closed_at ? Carbon::parse($ticket->closed_at)->toDateTimeString() : '',
        ]);
    }

    private function safeCell(string $cell): string
    {
        return preg_match('/^[\s]*[=+\-@]/', $cell) === 1 ? "'{$cell}" : $cell;
    }
}
