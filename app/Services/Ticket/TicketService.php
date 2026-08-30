<?php

namespace App\Services\Ticket;

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Filters\Ticket\FilterTicket;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketTimelineLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

class TicketService{

    private $ticket;
    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }

    public function allTickets()
    {
        $tickets = QueryBuilder::for(Ticket::class)
            ->allowedFilters([
                AllowedFilter::custom('search', new FilterTicket()),
                AllowedFilter::callback('status', function (Builder $query, $value) {
                    $status = is_array($value) ? reset($value) : $value;

                    if ((string) $status === (string) TicketStatus::OPENED->value) {
                        $query->whereIn('status', [
                            TicketStatus::OPENED->value,
                            TicketStatus::REOPENED->value,
                        ]);
                        return;
                    }

                    $query->where('status', $status);
                }),
                AllowedFilter::exact('importance'),
                AllowedFilter::exact('company', 'company_id'),
                AllowedFilter::exact('tag', 'tag_id'),
                AllowedFilter::exact('customer', 'customer_id'),
    
AllowedFilter::callback('fromDate', function (Builder $query, $value) {
    $query->whereDate('real_closed_at', '>=', $value);
}),

AllowedFilter::callback('toDate', function (Builder $query, $value) {
    $query->whereDate('real_closed_at', '<=', $value);
}),            ])
            ->orderBy('created_at', 'desc')
            ->get();
    
        return $tickets;
    }

    public function createTicket(array $ticketData): Ticket
    {

        $ticket = Ticket::create([
            'company_id' => $ticketData['companyId'],
            'status' => TicketStatus::from($ticketData['status'])->value,
            'importance' => TicketImportanceStatus::from($ticketData['importance'])->value,
            'description' => $ticketData['description'],
            'customer_id' => $ticketData['customerId'],
            'branch_id' => $ticketData['branchId'],
            'closed_at' => null,
            'tag_id' => $ticketData['tagId']??null,
            'timeline_token' => (string) Str::uuid(),
            'real_closed_at' => TicketStatus::from($ticketData['status'])->value == 1 ? now():null
        ]);

        return $ticket;

    }

    public function editTicket(int $ticketId)
    {
        return Ticket::with(['attachments', 'customer'])->find($ticketId);
    }

    public function updateTicket(array $ticketData): Ticket
    {

        $ticket = Ticket::find($ticketData['ticketId']);
        $oldStatus = (int) $ticket->getRawOriginal('status');
        $newStatus = TicketStatus::from($ticketData['status'])->value;
    
        $closedAt = $ticketData['closedAt']??null;
        
        if($newStatus == 1 && !isset($ticketData['closedAt'])){
            $closedAt = now();
        }
        $ticket->update([
            'company_id' => $ticketData['companyId'],
            'status' => $newStatus,
            'importance' => TicketImportanceStatus::from($ticketData['importance'])->value,
            'description' => $ticketData['description'],
            'customer_id' => $ticketData['customerId'],
            'branch_id' => $ticketData['branchId'],
            //'closed_at' => TicketStatus::from($ticketData['status'])->value == 1? now():null,
            'closed_at' => $closedAt,// $ticketData['closedAt']??null,
            'tag_id' => $ticketData['tagId']??null,
            'real_closed_at' => TicketStatus::from($ticketData['status'])->value == 1 ? now():null
        ]);

        if ($oldStatus !== $newStatus) {
            $user = Auth::user();

            $ticket->timelineLogs()->create([
                'type' => TicketTimelineLog::TYPE_STATUS_CHANGE,
                'actor_type' => TicketTimelineLog::ACTOR_ADMIN,
                'user_id' => $user?->id,
                'user_name' => $user?->name ?? 'Admin',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        }

        return $ticket;


    }


    public function deleteTicket(int $ticketId)
    {

        return Ticket::find($ticketId)->delete();

    }


}
