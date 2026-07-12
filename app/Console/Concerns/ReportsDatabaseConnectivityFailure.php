<?php

namespace App\Console\Concerns;

use App\Services\Tenancy\DatabaseConnectivityFailure;
use Illuminate\Database\QueryException;

trait ReportsDatabaseConnectivityFailure
{
    private function connectivityFailureExitCode(
        QueryException $exception,
        DatabaseConnectivityFailure $connectivityFailure
    ): int {
        if (! $connectivityFailure->matches($exception)) {
            throw $exception;
        }

        $this->error(DatabaseConnectivityFailure::MESSAGE);

        return self::FAILURE;
    }
}
