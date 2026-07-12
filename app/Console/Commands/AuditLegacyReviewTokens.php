<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsDatabaseConnectivityFailure;
use App\Models\Tiket\Ticket;
use App\Services\Tenancy\DatabaseConnectivityFailure;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class AuditLegacyReviewTokens extends Command
{
    use ReportsDatabaseConnectivityFailure;

    protected $signature = 'review-capabilities:audit-legacy';

    protected $description = 'Count legacy plaintext review tokens without displaying or changing them';

    public function handle(DatabaseConnectivityFailure $connectivityFailure): int
    {
        try {
            $legacyCount = Ticket::query()->whereNotNull('token')->count();
        } catch (QueryException $exception) {
            return $this->connectivityFailureExitCode($exception, $connectivityFailure);
        }

        $this->table(['Outcome', 'Count'], [
            ['legacy_tokens', $legacyCount],
            ['authoritatively_migratable', 0],
        ]);
        $this->warn('Dry-run only: legacy rows have no authoritative purpose or expiry and are not migrated.');

        return self::SUCCESS;
    }
}
