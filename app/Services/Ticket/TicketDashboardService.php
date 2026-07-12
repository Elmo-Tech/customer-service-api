<?php

namespace App\Services\Ticket;

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Models\Company\Branch;
use App\Models\Tiket\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class TicketDashboardService
{
    public function __construct(private readonly AuthorizedTicketQuery $ticketQuery) {}

    public function metrics(User $user, array $filters): array
    {
        $tickets = $this->ticketQuery->filtered($user, $filters);
        $statusCounts = $this->counts($tickets, 'status');

        return [
            'kpis' => $this->kpis($tickets, $statusCounts),
            'series' => $this->series($tickets, $statusCounts),
            'oldestOpen' => $this->ticketRows($this->oldestOpen($tickets)),
            'recentActivity' => $this->ticketRows($this->recentActivity($tickets)),
        ];
    }

    private function series(Builder $tickets, array $statusCounts): array
    {
        return [
            'createdVsClosed' => $this->createdVsClosed($tickets),
            'statusDistribution' => $this->distribution($statusCounts, TicketStatus::class),
            'importanceDistribution' => $this->distribution(
                $this->counts($tickets, 'importance'),
                TicketImportanceStatus::class,
            ),
            'branchVolume' => $this->branchVolume($tickets),
        ];
    }

    private function kpis(Builder $tickets, array $statusCounts): array
    {
        return [
            'total' => array_sum($statusCounts),
            'open' => $statusCounts[TicketStatus::OPENED->value] ?? 0,
            'inProgress' => $statusCounts[TicketStatus::IN_PROGRESS->value] ?? 0,
            'closed' => $statusCounts[TicketStatus::DONE->value] ?? 0,
            'reopened' => $statusCounts[TicketStatus::REOPENED->value] ?? 0,
            'averageResolutionHours' => $this->averageResolutionHours($tickets),
        ];
    }

    private function counts(Builder $tickets, string $column): array
    {
        return (clone $tickets)->selectRaw("{$column}, COUNT(*) as aggregate")
            ->groupBy($column)->pluck('aggregate', $column)->map(fn ($count) => (int) $count)->all();
    }

    private function distribution(array $counts, string $enumClass): array
    {
        return collect($enumClass::cases())->map(fn ($case) => [
            'key' => strtolower($case->name),
            'value' => $case->value,
            'count' => $counts[$case->value] ?? 0,
        ])->values()->all();
    }

    private function averageResolutionHours(Builder $tickets): ?float
    {
        $totalMinutes = 0;
        $resolvedCount = 0;

        foreach ((clone $tickets)->whereNotNull('real_closed_at')
            ->select(['id', 'created_at', 'real_closed_at'])->lazyById(500) as $ticket) {
            $totalMinutes += Carbon::parse($ticket->created_at)->diffInMinutes($ticket->real_closed_at);
            $resolvedCount++;
        }

        return $resolvedCount === 0 ? null : round($totalMinutes / $resolvedCount / 60, 2);
    }

    private function createdVsClosed(Builder $tickets): array
    {
        $created = $this->dateCounts($tickets, 'created_at');
        $closed = $this->dateCounts($tickets, 'real_closed_at');

        return collect(array_unique([...array_keys($created), ...array_keys($closed)]))->sort()->map(
            fn ($date) => ['date' => $date, 'created' => $created[$date] ?? 0, 'closed' => $closed[$date] ?? 0],
        )->values()->all();
    }

    private function dateCounts(Builder $tickets, string $column): array
    {
        return (clone $tickets)->whereNotNull($column)->selectRaw("DATE({$column}) as period, COUNT(*) as aggregate")
            ->groupByRaw("DATE({$column})")->pluck('aggregate', 'period')->map(fn ($count) => (int) $count)->all();
    }

    private function branchVolume(Builder $tickets): array
    {
        $counts = (clone $tickets)->whereNotNull('branch_id')->selectRaw('branch_id, COUNT(*) as aggregate')
            ->groupBy('branch_id')->orderByDesc('aggregate')->pluck('aggregate', 'branch_id');
        $names = Branch::withTrashed()->whereIn('id', $counts->keys())->pluck('name', 'id');

        return $counts->map(fn ($count, $branchId) => [
            'branchId' => (int) $branchId,
            'branchName' => $names[$branchId] ?? '',
            'count' => (int) $count,
        ])->values()->all();
    }

    private function oldestOpen(Builder $tickets)
    {
        return (clone $tickets)->whereIn('status', [TicketStatus::OPENED->value, TicketStatus::REOPENED->value])
            ->with(['customer:id,firstname,lastname', 'company:id,name', 'branch:id,name'])
            ->orderBy('created_at')->limit(10)->get();
    }

    private function recentActivity(Builder $tickets)
    {
        return (clone $tickets)->with(['customer:id,firstname,lastname', 'company:id,name', 'branch:id,name'])
            ->orderByDesc('updated_at')->limit(10)->get();
    }

    private function ticketRows($tickets): array
    {
        return $tickets->map(fn (Ticket $ticket) => [
            'ticketId' => $ticket->id,
            'ticketNumber' => $ticket->ticket_number,
            'customerName' => $ticket->customer?->getFullName() ?? '',
            'companyName' => $ticket->company?->name ?? '',
            'branchName' => $ticket->branch?->name ?? '',
            'status' => $ticket->getRawOriginal('status'),
            'importance' => $ticket->getRawOriginal('importance'),
            'createdAt' => Carbon::parse($ticket->created_at)->toISOString(),
            'closedAt' => $ticket->closed_at ? Carbon::parse($ticket->closed_at)->toISOString() : null,
        ])->all();
    }
}
