<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsDatabaseConnectivityFailure;
use App\Services\Tenancy\DatabaseConnectivityFailure;
use App\Services\Tenancy\TenantAuditReport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;

class ExportTenantAudit extends Command
{
    use ReportsDatabaseConnectivityFailure;

    protected $signature = 'tenancy:audit {--output= : JSON report destination}';

    protected $description = 'Export a sanitized, read-only tenancy classification audit';

    public function handle(
        TenantAuditReport $auditReport,
        DatabaseConnectivityFailure $connectivityFailure
    ): int {
        $outputPath = $this->outputPath();

        if (! $outputPath) {
            $this->error('The --output option is required.');

            return self::FAILURE;
        }

        try {
            $encodedReport = $this->encodedReport($auditReport);
        } catch (QueryException $exception) {
            return $this->connectivityFailureExitCode($exception, $connectivityFailure);
        }

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $encodedReport);
        $this->info("Sanitized tenancy audit written to {$outputPath}");

        return self::SUCCESS;
    }

    private function outputPath(): ?string
    {
        $configuredPath = $this->option('output');

        if (! is_string($configuredPath) || $configuredPath === '') {
            return null;
        }

        return str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            ? $configuredPath
            : base_path($configuredPath);
    }

    private function encodedReport(TenantAuditReport $auditReport): string
    {
        return json_encode(
            $auditReport->contents(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    }
}
