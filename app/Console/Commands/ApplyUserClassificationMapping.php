<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsDatabaseConnectivityFailure;
use App\Services\Tenancy\DatabaseConnectivityFailure;
use App\Services\Tenancy\UserClassificationMappingApplier;
use App\Services\Tenancy\UserClassificationMappingReader;
use App\Services\Tenancy\UserClassificationMappingValidator;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class ApplyUserClassificationMapping extends Command
{
    use ReportsDatabaseConnectivityFailure;

    protected $signature = 'tenancy:apply-mapping
        {mapping : Authoritative mapping CSV path}
        {--execute : Apply the validated mapping inside one transaction}';

    protected $description = 'Validate and atomically apply an authoritative user tenancy mapping';

    public function handle(
        UserClassificationMappingReader $mappingReader,
        UserClassificationMappingValidator $mappingValidator,
        UserClassificationMappingApplier $mappingApplier,
        DatabaseConnectivityFailure $connectivityFailure,
    ): int {
        try {
            $mappingRows = $mappingReader->rows($this->mappingPath());
            $validationErrors = $mappingValidator->errors($mappingRows);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            return $this->connectivityFailureExitCode($exception, $connectivityFailure);
        }

        if ($validationErrors !== []) {
            $this->components->error('Mapping validation failed. No rows were applied.');
            $this->components->bulletList($validationErrors);

            return self::FAILURE;
        }

        if (! $this->option('execute')) {
            $this->info('Mapping is valid. Dry-run completed without database writes.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply this mapping to every existing user?', false)) {
            $this->warn('Mapping application cancelled. No rows were applied.');

            return self::FAILURE;
        }

        try {
            $appliedCount = $mappingApplier->apply($mappingRows);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            return $this->connectivityFailureExitCode($exception, $connectivityFailure);
        }

        $this->info("Applied authoritative tenancy mapping to {$appliedCount} users.");

        return self::SUCCESS;
    }

    private function mappingPath(): string
    {
        $mappingPath = $this->argument('mapping');

        return str_starts_with($mappingPath, DIRECTORY_SEPARATOR)
            ? $mappingPath
            : base_path($mappingPath);
    }
}
