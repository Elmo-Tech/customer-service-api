<?php

namespace App\Services\Ticket;

use App\Models\Tiket\TicketAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;

class LegacyAttachmentMigrationService
{
    public function inspect(TicketAttachment $attachment, bool $execute): string
    {
        try {
            return $this->inspectFilesystem($attachment, $execute);
        } catch (FilesystemException) {
            return 'failed';
        }
    }

    private function inspectFilesystem(TicketAttachment $attachment, bool $execute): string
    {
        if (! in_array($attachment->storage_disk, ['public', 'private'], true)) {
            return 'failed';
        }

        if ($attachment->storage_disk === 'private') {
            return $this->privateFileMatches($attachment) ? 'already_migrated' : 'mismatched';
        }

        if (! $this->safePath($attachment->path) || ! Storage::disk('public')->exists($attachment->path)) {
            return 'missing';
        }

        if (! $execute) {
            return $this->dryRunOutcome($attachment);
        }

        return $this->copyAndSwitch($attachment);
    }

    private function dryRunOutcome(TicketAttachment $attachment): string
    {
        $source = Storage::disk('public');
        $destination = Storage::disk('ticket_attachments');

        if (! $destination->exists($attachment->path)) {
            return 'would_migrate';
        }

        return $this->matches(
            $destination->size($attachment->path),
            $this->checksum($destination->readStream($attachment->path)),
            $source->size($attachment->path),
            $this->checksum($source->readStream($attachment->path)),
        ) ? 'would_migrate' : 'mismatched';
    }

    private function copyAndSwitch(TicketAttachment $attachment): string
    {
        $source = Storage::disk('public');
        $destination = Storage::disk('ticket_attachments');
        $sourceSize = $source->size($attachment->path);
        $sourceChecksum = $this->checksum($source->readStream($attachment->path));

        if ($destination->exists($attachment->path)) {
            return $this->matches($destination->size($attachment->path), $this->checksum(
                $destination->readStream($attachment->path),
            ), $sourceSize, $sourceChecksum) ? $this->switchMetadata($attachment, $sourceSize, $sourceChecksum) : 'mismatched';
        }

        $this->copy($source->readStream($attachment->path), $attachment->path);

        return $this->verifiedSwitch($attachment, $sourceSize, $sourceChecksum);
    }

    private function copy($sourceStream, string $path): void
    {
        if ($sourceStream === false || ! Storage::disk('ticket_attachments')->writeStream($path, $sourceStream)) {
            throw UnableToWriteFile::atLocation($path, 'Attachment copy failed.');
        }

        fclose($sourceStream);
    }

    private function verifiedSwitch(TicketAttachment $attachment, int $size, string $checksum): string
    {
        $destination = Storage::disk('ticket_attachments');
        $matches = $this->matches(
            $destination->size($attachment->path),
            $this->checksum($destination->readStream($attachment->path)),
            $size,
            $checksum,
        );

        return $matches ? $this->switchMetadata($attachment, $size, $checksum) : 'mismatched';
    }

    private function switchMetadata(TicketAttachment $attachment, int $size, string $checksum): string
    {
        return DB::transaction(function () use ($attachment, $size, $checksum): string {
            $updated = TicketAttachment::query()->whereKey($attachment->id)
                ->where('storage_disk', 'public')->update([
                    'storage_disk' => 'private',
                    'file_size' => $size,
                    'checksum' => $checksum,
                ]);

            return $updated === 1 ? 'migrated' : 'already_migrated';
        });
    }

    private function privateFileMatches(TicketAttachment $attachment): bool
    {
        $disk = Storage::disk('ticket_attachments');

        return $this->safePath($attachment->path) && $disk->exists($attachment->path)
            && ($attachment->file_size === null || $disk->size($attachment->path) === $attachment->file_size)
            && ($attachment->checksum === null || $this->checksum($disk->readStream($attachment->path)) === $attachment->checksum);
    }

    private function matches(int $actualSize, string $actualChecksum, int $size, string $checksum): bool
    {
        return $actualSize === $size && hash_equals($checksum, $actualChecksum);
    }

    private function checksum($stream): string
    {
        if ($stream === false) {
            throw UnableToReadFile::fromLocation('attachment', 'Attachment read failed.');
        }

        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);

        return hash_final($hash);
    }

    private function safePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\')
            && ! in_array('..', explode('/', $path), true);
    }
}
