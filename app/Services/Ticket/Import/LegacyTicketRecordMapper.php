<?php

namespace App\Services\Ticket\Import;

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use InvalidArgumentException;
use JsonException;

class LegacyTicketRecordMapper
{
    public function ticketAttributes(array $ticket, LegacyTicketImportMapping $mapping): array
    {
        $status = $mapping->enumValue('statuses', $ticket['status']);
        $importance = $mapping->enumValue('importances', $ticket['importance']);
        $this->assertEnums($status, $importance);

        return [
            'ticket_number' => $this->requiredString($ticket, 'ticket_number'),
            'status' => $status,
            'importance' => $importance,
            'description' => $this->requiredString($ticket, 'description'),
            'customer_id' => $mapping->entityId('customers', $ticket['customer_id']),
            'company_id' => $mapping->entityId('companies', $ticket['company_id']),
            'branch_id' => $mapping->entityId('branches', $ticket['branch_id']),
            'tag_id' => $mapping->entityId('tags', $ticket['tag_id'] ?? null),
            'opened_by_user_id' => $mapping->entityId('users', $ticket['opened_by_user_id'] ?? null),
            'assigned_to_user_id' => $mapping->entityId('users', $ticket['assigned_to_user_id'] ?? null),
            'closed_at' => $ticket['closed_at'] ?? null,
            'real_closed_at' => $ticket['real_closed_at'] ?? null,
            'token' => $ticket['token'] ?? null,
            ...$this->auditAttributes($ticket, $mapping),
        ];
    }

    public function auditAttributes(array $source, LegacyTicketImportMapping $mapping): array
    {
        return [
            'created_by' => $mapping->entityId('users', $source['created_by']),
            'updated_by' => $mapping->entityId('users', $source['updated_by']),
            'deleted_at' => $source['deleted_at'],
            'created_at' => $source['created_at'],
            'updated_at' => $source['updated_at'],
        ];
    }

    public function sourceHash(array $record): string
    {
        try {
            return hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new InvalidArgumentException('Source ticket contains invalid text encoding.');
        }
    }

    private function assertEnums(int $status, int $importance): void
    {
        if (! in_array($status, TicketStatus::values(), true)) {
            throw new InvalidArgumentException('Mapped ticket status is unsupported.');
        }
        if (! in_array($importance, TicketImportanceStatus::values(), true)) {
            throw new InvalidArgumentException('Mapped ticket importance is unsupported.');
        }
    }

    private function requiredString(array $source, string $field): string
    {
        $value = trim((string) ($source[$field] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("Required source field is empty: {$field}");
        }

        return $value;
    }
}
