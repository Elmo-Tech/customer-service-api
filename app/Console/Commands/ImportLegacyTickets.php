<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsDatabaseConnectivityFailure;
use App\Services\Tenancy\DatabaseConnectivityFailure;
use App\Services\Ticket\Import\LegacyTicketImportMapping;
use App\Services\Ticket\Import\LegacyTicketImportService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class ImportLegacyTickets extends Command
{
    use ReportsDatabaseConnectivityFailure;

    protected $signature = 'tickets:import-legacy
        {mapping : Path to the authoritative JSON mapping}
        {--execute : Import validated tickets, attachments, and logs}
        {--confirm : Confirm backup, restore rehearsal, and source freeze are complete}';

    protected $description = 'Audit or import historical tickets from the configured read-only legacy database';

    public function handle(
        LegacyTicketImportService $importer,
        DatabaseConnectivityFailure $connectivityFailure,
    ): int {
        if ($this->option('execute') && ! $this->option('confirm')) {
            $this->error('Execution requires --confirm after backup, restore rehearsal, and source freeze.');

            return self::FAILURE;
        }

        try {
            $mapping = LegacyTicketImportMapping::fromFile($this->argument('mapping'));
            $report = $importer->run($mapping, (bool) $this->option('execute'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            return $this->connectivityFailureExitCode($exception, $connectivityFailure);
        }

        $this->table(['Outcome', 'Count'], collect($report)->except('errors')->map(
            fn (int $count, string $outcome) => [$outcome, $count],
        )->values()->all());
        foreach ($report['errors'] as $error) {
            $this->warn($error);
        }

        if (($report['invalid'] + $report['missing_files']) > 0) {
            $this->error('Import blocked. Correct every reported mapping, relationship, and file issue.');

            return self::FAILURE;
        }

        $this->info($this->option('execute')
            ? 'Import completed. Source data and legacy files were not modified.'
            : 'Dry-run completed. No target rows were modified.');

        return self::SUCCESS;
    }
}
