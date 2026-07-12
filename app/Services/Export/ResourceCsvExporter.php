<?php

namespace App\Services\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ResourceCsvExporter
{
    public function response(string $resourceName, array $headers, iterable $rows): StreamedResponse
    {
        $filename = preg_replace('/[^a-z0-9_-]/i', '-', $resourceName).'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, array_map($this->safeCell(...), $row));
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCell(mixed $cell): string
    {
        $text = strip_tags((string) ($cell ?? ''));

        return preg_match('/^\s*[=+\-@]/u', $text) ? "'{$text}" : $text;
    }
}
