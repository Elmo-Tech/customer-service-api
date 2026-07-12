<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsDatabaseConnectivityFailure;
use App\Services\Tenancy\DatabaseConnectivityFailure;
use App\Services\Ticket\Import\LegacyTicketAuditService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class AuditLegacyTickets extends Command
{
    use ReportsDatabaseConnectivityFailure;

    protected $signature = 'tickets:audit-legacy
        {--output=tenancy/legacy-ticket-audit.json : Path relative to storage/app}';

    protected $description = 'Write a read-only legacy ticket inventory and mapping skeleton';

    public function handle(
        LegacyTicketAuditService $audit,
        DatabaseConnectivityFailure $connectivityFailure,
    ): int {
        try {
            $report = $audit->report();
            Storage::disk('local')->put(
                $this->option('output'),
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            return $this->connectivityFailureExitCode($exception, $connectivityFailure);
        }

        $this->info('Legacy ticket audit written. No source or target rows were modified.');

        return self::SUCCESS;
    }
}
