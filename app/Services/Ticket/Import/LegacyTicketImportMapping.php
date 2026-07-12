<?php

namespace App\Services\Ticket\Import;

use InvalidArgumentException;
use JsonException;

class LegacyTicketImportMapping
{
    private const SECTIONS = [
        'companies', 'branches', 'customers', 'users', 'tags', 'statuses', 'importances',
    ];

    private function __construct(private readonly array $mapping) {}

    public static function fromFile(string $path): self
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("Mapping file is not readable: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException('Mapping file could not be read.');
        }

        try {
            $mapping = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Mapping file is not valid JSON.');
        }

        if (! is_array($mapping)) {
            throw new InvalidArgumentException('Mapping root must be a JSON object.');
        }

        foreach (self::SECTIONS as $section) {
            if (! isset($mapping[$section]) || ! is_array($mapping[$section])) {
                throw new InvalidArgumentException("Mapping section is missing or invalid: {$section}");
            }
        }

        return new self($mapping);
    }

    public function entityId(string $section, mixed $sourceId): ?int
    {
        if ($sourceId === null) {
            return null;
        }

        $targetId = $this->mappedValue($section, $sourceId);
        if (! is_int($targetId) || $targetId < 1) {
            throw new InvalidArgumentException("Invalid {$section} target for source ID {$sourceId}.");
        }

        return $targetId;
    }

    public function enumValue(string $section, mixed $sourceValue): int
    {
        $targetValue = $this->mappedValue($section, $sourceValue);
        if (! is_int($targetValue) || $targetValue < 0) {
            throw new InvalidArgumentException("Invalid {$section} target for source value {$sourceValue}.");
        }

        return $targetValue;
    }

    public function targetIds(string $section): array
    {
        return array_values(array_unique(array_filter(
            $this->mapping[$section],
            fn (mixed $targetId) => is_int($targetId) && $targetId > 0,
        )));
    }

    private function mappedValue(string $section, mixed $sourceValue): mixed
    {
        $sourceKey = (string) $sourceValue;
        if (! array_key_exists($sourceKey, $this->mapping[$section])) {
            throw new InvalidArgumentException("Missing {$section} mapping for source value {$sourceKey}.");
        }

        return $this->mapping[$section][$sourceKey];
    }
}
