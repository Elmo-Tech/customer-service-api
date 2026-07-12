<?php

namespace App\Services\Tenancy;

use Illuminate\Database\QueryException;

class DatabaseConnectivityFailure
{
    public const MESSAGE = 'Database connection unavailable. Run this command on staging, the deployed backend, or another environment securely connected to the existing database.';

    public function matches(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 2002;
    }
}
