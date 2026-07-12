<?php

namespace App\Services\Ticket\Import;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LegacyTicketImportService
{
    private array $auditedHashes = [];

    public function __construct(
        private readonly LegacyTicketSourceReader $source,
        private readonly LegacyTicketRecordMapper $mapper,
        private readonly LegacyTicketTargetValidator $validator,
        private readonly LegacyTicketImportWriter $writer,
    ) {}

    public function run(LegacyTicketImportMapping $mapping, bool $execute): array
    {
        $this->auditedHashes = [];
        $this->source->assertSchema();
        $this->validator->load($mapping);
        $report = $this->audit($mapping);

        if ($execute && ! $this->hasBlockingIssues($report)) {
            DB::transaction(function () use ($mapping, &$report): void {
                $this->source->eachTicket(function (array $record) use ($mapping, &$report): void {
                    if (! $this->validator->alreadyImported($record)) {
                        $this->assertAuditedRecord($record);
                        $this->writer->write($record, $mapping);
                        $report['imported']++;
                    }
                });
            });
        }

        return $report;
    }

    private function audit(LegacyTicketImportMapping $mapping): array
    {
        $report = $this->emptyReport();
        $this->source->eachTicket(function (array $record) use ($mapping, &$report): void {
            $report['source']++;
            $sourceId = (int) $record['ticket']['id'];

            try {
                if ($this->validator->alreadyImported($record)) {
                    $report['already_imported']++;

                    return;
                }
                $attributes = $this->mapper->ticketAttributes($record['ticket'], $mapping);
                $missingFiles = $this->validator->validate($record, $attributes, $mapping);
                if ($missingFiles > 0) {
                    $report['missing_files'] += $missingFiles;
                    throw new InvalidArgumentException('One or more attachment files are missing.');
                }
                $this->auditedHashes[$sourceId] = $this->mapper->sourceHash($record);
                $report['ready']++;
            } catch (InvalidArgumentException $exception) {
                $report['invalid']++;
                $this->addError($report, $sourceId, $exception->getMessage());
            }
        });

        return $report;
    }

    private function hasBlockingIssues(array $report): bool
    {
        return ($report['invalid'] + $report['missing_files']) > 0;
    }

    private function assertAuditedRecord(array $record): void
    {
        $sourceId = (int) $record['ticket']['id'];
        $auditedHash = $this->auditedHashes[$sourceId] ?? null;
        if (! $auditedHash || ! hash_equals($auditedHash, $this->mapper->sourceHash($record))) {
            throw new InvalidArgumentException("Source ticket {$sourceId} changed after dry-run validation.");
        }
    }

    private function emptyReport(): array
    {
        return [
            'source' => 0, 'ready' => 0, 'already_imported' => 0,
            'imported' => 0, 'invalid' => 0, 'missing_files' => 0, 'errors' => [],
        ];
    }

    private function addError(array &$report, int $sourceId, string $message): void
    {
        if (count($report['errors']) < 20) {
            $report['errors'][] = "Source ticket {$sourceId}: {$message}";
        }
    }
}
