<?php

namespace App\Services\Ticket\Import;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class LegacyTicketSourceReader
{
    private const REQUIRED_TICKET_COLUMNS = [
        'id', 'ticket_number', 'status', 'importance', 'description', 'customer_id',
        'company_id', 'branch_id', 'created_by', 'updated_by', 'deleted_at', 'created_at', 'updated_at',
    ];

    private const REQUIRED_ATTACHMENT_COLUMNS = [
        'id', 'path', 'ticket_id', 'created_by', 'updated_by', 'deleted_at', 'created_at', 'updated_at',
    ];

    private const REQUIRED_LOG_COLUMNS = [
        'id', 'ticket_id', 'status', 'text', 'created_by', 'updated_by', 'deleted_at', 'created_at', 'updated_at',
    ];

    private bool $hasAttachments = false;

    private bool $hasLogs = false;

    public function assertSchema(): void
    {
        $schema = Schema::connection($this->connection());
        $this->assertTable($schema, 'tickets', self::REQUIRED_TICKET_COLUMNS);
        $this->hasAttachments = $schema->hasTable('ticket_attachments');
        $this->hasLogs = $schema->hasTable('ticket_logs');

        if ($this->hasAttachments) {
            $this->assertTable($schema, 'ticket_attachments', self::REQUIRED_ATTACHMENT_COLUMNS);
        }
        if ($this->hasLogs) {
            $this->assertTable($schema, 'ticket_logs', self::REQUIRED_LOG_COLUMNS);
        }
    }

    public function eachTicket(callable $callback): void
    {
        $database = DB::connection($this->connection());
        $database->table('tickets')->orderBy('id')->chunkById(
            (int) config('legacy_ticket_import.chunk_size'),
            function (Collection $tickets) use ($database, $callback): void {
                $ticketIds = $tickets->pluck('id');
                $attachments = $this->relatedRows($database, 'ticket_attachments', $ticketIds, $this->hasAttachments);
                $logs = $this->relatedRows($database, 'ticket_logs', $ticketIds, $this->hasLogs);

                foreach ($tickets as $ticket) {
                    $callback([
                        'ticket' => (array) $ticket,
                        'attachments' => $attachments->get($ticket->id, collect())->map(fn ($row) => (array) $row)->all(),
                        'logs' => $logs->get($ticket->id, collect())->map(fn ($row) => (array) $row)->all(),
                    ]);
                }
            },
        );
    }

    private function relatedRows($database, string $table, Collection $ticketIds, bool $exists): Collection
    {
        if (! $exists) {
            return collect();
        }

        return $database->table($table)->whereIn('ticket_id', $ticketIds)
            ->orderBy('id')->get()->groupBy('ticket_id');
    }

    private function assertTable($schema, string $table, array $columns): void
    {
        if (! $schema->hasTable($table)) {
            throw new InvalidArgumentException("Legacy source table is missing: {$table}");
        }
        if (! $schema->hasColumns($table, $columns)) {
            throw new InvalidArgumentException("Legacy source table has an unsupported schema: {$table}");
        }
    }

    private function connection(): string
    {
        return (string) config('legacy_ticket_import.connection');
    }
}
