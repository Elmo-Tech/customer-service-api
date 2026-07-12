<?php

namespace App\Services\Tenancy;

use RuntimeException;
use SplFileObject;

class UserClassificationMappingReader
{
    public const HEADERS = [
        'user_id',
        'username',
        'email',
        'account_type',
        'company_id',
        'branch_id',
        'intended_role',
        'mapping_authority_notes',
    ];

    public function rows(string $mappingPath): array
    {
        if (! is_file($mappingPath) || ! is_readable($mappingPath)) {
            throw new RuntimeException("Mapping file is not readable: {$mappingPath}");
        }

        $mappingFile = new SplFileObject($mappingPath, 'r');
        $mappingFile->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $this->assertHeaders($mappingFile->fgetcsv());

        return $this->readRows($mappingFile);
    }

    private function assertHeaders(array|false $headers): void
    {
        if ($headers !== self::HEADERS) {
            throw new RuntimeException('Mapping CSV headers do not match the authoritative template.');
        }
    }

    private function readRows(SplFileObject $mappingFile): array
    {
        $mappingRows = [];

        while (! $mappingFile->eof()) {
            $csvRow = $mappingFile->fgetcsv();

            if ($csvRow === false || $csvRow === [null]) {
                continue;
            }

            $mappingRows[] = $this->mappingRow($csvRow, $mappingFile->key() + 1);
        }

        return $mappingRows;
    }

    private function mappingRow(array $csvRow, int $lineNumber): array
    {
        if (count($csvRow) !== count(self::HEADERS)) {
            throw new RuntimeException("Mapping CSV line {$lineNumber} has an invalid column count.");
        }

        $mappingRow = array_combine(self::HEADERS, array_map('trim', $csvRow));
        $mappingRow['line'] = $lineNumber;

        return $mappingRow;
    }
}
