<?php

namespace App\Console\Commands;

use App\Enums\Ticket\TicketStatus;
use App\Enums\User\TenantRole;
use App\Enums\User\UserStatus;
use App\Models\Tiket\Ticket;
use App\Models\User;
use App\Notifications\TicketSlaEscalated;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class EscalateOverdueTickets extends Command
{
    protected $signature = 'tickets:escalate-overdue';

    protected $description = 'Escalate overdue tickets once and queue tenant-scoped notifications';

    public function handle(): int
    {
        $ticketIds = Ticket::query()->whereNull('escalated_at')->whereNotNull('due_at')
            ->where('due_at', '<', now())->where('status', '!=', TicketStatus::DONE->value)
            ->orderBy('id')->pluck('id');
        $escalated = 0;

        foreach ($ticketIds as $ticketId) {
            [$ticket, $recipients] = DB::transaction(fn () => $this->escalate((int) $ticketId));
            if (! $ticket) {
                continue;
            }

            Notification::send($recipients, new TicketSlaEscalated($ticket));
            $escalated++;
        }

        $this->info("Escalated {$escalated} overdue ticket(s).");

        return self::SUCCESS;
    }

    private function escalate(int $ticketId): array
    {
        $ticket = Ticket::query()->with('company')->lockForUpdate()->findOrFail($ticketId);
        if ($ticket->escalated_at !== null || Carbon::parse($ticket->due_at)->isFuture()
            || (int) $ticket->status === TicketStatus::DONE->value) {
            return [null, collect()];
        }

        $ticket->update(['escalated_at' => now()]);

        return [$ticket, $this->recipients($ticket)];
    }

    private function recipients(Ticket $ticket)
    {
        $tenantRecipients = User::query()->where('company_id', $ticket->company_id)
            ->where('status', UserStatus::ACTIVE->value)
            ->where(function ($users) use ($ticket) {
                $users->whereHas('roles', fn ($roles) => $roles->whereIn('name', [
                    TenantRole::COMPANY_OWNER->value,
                    TenantRole::COMPANY_MANAGER->value,
                ]));
                if ($ticket->branch_id !== null) {
                    $users->orWhere(function ($branchManagers) use ($ticket) {
                        $branchManagers->where('branch_id', $ticket->branch_id)
                            ->whereHas('roles', fn ($roles) => $roles->where(
                                'name',
                                TenantRole::BRANCH_MANAGER->value,
                            ));
                    });
                }
            })->get();
        $assignee = User::query()->whereKey($ticket->assigned_to_user_id)
            ->where('status', UserStatus::ACTIVE->value)->get();

        return $tenantRecipients->merge($assignee)->unique('id')->values();
    }
}
