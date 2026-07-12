<?php

namespace App\Services\Ticket\Import;

use App\Models\Tiket\LegacyTicketImport;
use Illuminate\Support\Facades\DB;

class LegacyTicketImportWriter
{
    public function __construct(private readonly LegacyTicketRecordMapper $mapper) {}

    public function write(array $record, LegacyTicketImportMapping $mapping): void
    {
        $ticketId = DB::table('tickets')->insertGetId(
            $this->mapper->ticketAttributes($record['ticket'], $mapping),
        );
        $this->insertAttachments($record['attachments'], $ticketId, $mapping);
        $this->insertLogs($record['logs'], $ticketId, $mapping);
        LegacyTicketImport::create([
            'source_key' => config('legacy_ticket_import.source_key'),
            'source_ticket_id' => $record['ticket']['id'],
            'ticket_id' => $ticketId,
            'source_hash' => $this->mapper->sourceHash($record),
        ]);
    }

    private function insertAttachments(array $attachments, int $ticketId, LegacyTicketImportMapping $mapping): void
    {
        foreach ($attachments as $attachment) {
            DB::table('ticket_attachments')->insert([
                'ticket_id' => $ticketId,
                'path' => $attachment['path'],
                'storage_disk' => 'public',
                'original_name' => basename($attachment['path']),
                'file_size' => null,
                'checksum' => null,
                ...$this->mapper->auditAttributes($attachment, $mapping),
            ]);
        }
    }

    private function insertLogs(array $logs, int $ticketId, LegacyTicketImportMapping $mapping): void
    {
        foreach ($logs as $log) {
            DB::table('ticket_logs')->insert([
                'ticket_id' => $ticketId,
                'status' => $log['status'],
                'text' => $log['text'],
                'token' => $log['token'] ?? null,
                ...$this->mapper->auditAttributes($log, $mapping),
            ]);
        }
    }
}
