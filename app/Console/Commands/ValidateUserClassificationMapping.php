<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsDatabaseConnectivityFailure;
use App\Services\Tenancy\DatabaseConnectivityFailure;
use App\Services\Tenancy\UserClassificationMappingReader;
use App\Services\Tenancy\UserClassificationMappingValidator;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class ValidateUserClassificationMapping extends Command
{
    use ReportsDatabaseConnectivityFailure;

    protected $signature = 'tenancy:validate-mapping {mapping : Authoritative mapping CSV path}';

    protected $description = 'Validate a tenancy mapping without writing to the database';

    public function handle(
        UserClassificationMappingReader $mappingReader,
        UserClassificationMappingValidator $mappingValidator,
        DatabaseConnectivityFailure $connectivityFailure
    ): int {
        try {
            $mappingRows = $mappingReader->rows($this->mappingPath());
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $validationErrors = $mappingValidator->errors($mappingRows);
        } catch (QueryException $exception) {
            return $this->connectivityFailureExitCode($exception, $connectivityFailure);
        }

        return $this->validationExitCode($validationErrors);
    }

    private function validationExitCode(array $validationErrors): int
    {
        if ($validationErrors === []) {
            $this->info('Mapping is valid. Dry-run completed without database writes.');

            return self::SUCCESS;
        }

        $this->components->error('Mapping validation failed.');
        $this->components->bulletList($validationErrors);

        return self::FAILURE;
    }

    private function mappingPath(): string
    {
        $mappingPath = $this->argument('mapping');

        return str_starts_with($mappingPath, DIRECTORY_SEPARATOR)
            ? $mappingPath
            : base_path($mappingPath);
    }
}
