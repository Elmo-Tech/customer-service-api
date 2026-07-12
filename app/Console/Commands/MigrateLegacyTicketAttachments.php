<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsDatabaseConnectivityFailure;
use App\Models\Tiket\TicketAttachment;
use App\Services\Tenancy\DatabaseConnectivityFailure;
use App\Services\Ticket\LegacyAttachmentMigrationService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class MigrateLegacyTicketAttachments extends Command
{
    use ReportsDatabaseConnectivityFailure;

    protected $signature = 'attachments:migrate-private {--execute : Copy verified files and switch metadata}';

    protected $description = 'Audit or copy legacy ticket attachments into private storage';

    public function handle(
        LegacyAttachmentMigrationService $migrationService,
        DatabaseConnectivityFailure $connectivityFailure,
    ): int {
        try {
            return $this->migrate($migrationService);
        } catch (QueryException $exception) {
            return $this->connectivityFailureExitCode($exception, $connectivityFailure);
        }
    }

    private function migrate(LegacyAttachmentMigrationService $migrationService): int
    {
        $counts = $this->emptyCounts();

        TicketAttachment::query()->whereHas('ticket')->orderBy('id')->each(
            function (TicketAttachment $attachment) use (&$counts, $migrationService): void {
                $counts[$migrationService->inspect($attachment, (bool) $this->option('execute'))]++;
            },
        );

        $this->report($counts);

        return ($counts['missing'] + $counts['mismatched'] + $counts['failed']) > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function emptyCounts(): array
    {
        return array_fill_keys([
            'would_migrate', 'migrated', 'already_migrated', 'missing', 'mismatched', 'failed',
        ], 0);
    }

    private function report(array $counts): void
    {
        $this->table(['Outcome', 'Count'], collect($counts)->map(
            fn (int $count, string $outcome) => [$outcome, $count],
        )->values()->all());
        $this->info($this->option('execute')
            ? 'Execution finished. Legacy originals were preserved.'
            : 'Dry-run finished. No files or database rows were changed.');
    }
}
