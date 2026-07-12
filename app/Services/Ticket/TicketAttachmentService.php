<?php

namespace App\Services\Ticket;

use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentService
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly TicketReviewQuery $reviewQuery,
    ) {}

    public function store(Ticket $ticket, UploadedFile $uploadedFile): TicketAttachment
    {
        $path = Storage::disk('ticket_attachments')->putFile("tickets/{$ticket->id}", $uploadedFile);

        return $ticket->attachments()->create([
            'path' => $path,
            'storage_disk' => 'private',
            'original_name' => $this->safeFilename($uploadedFile->getClientOriginalName()),
            'file_size' => Storage::disk('ticket_attachments')->size($path),
            'checksum' => hash('sha256', Storage::disk('ticket_attachments')->get($path)),
        ]);
    }

    public function authenticatedAttachment(User $user, int $ticketId, int $attachmentId): TicketAttachment
    {
        $ticket = $this->ticketService->findTicket($user, $ticketId);
        Gate::forUser($user)->authorize('view', $ticket);

        return $ticket->attachments()->findOrFail($attachmentId);
    }

    public function reviewAttachment(int $ticketId, int $attachmentId, string $token): TicketAttachment
    {
        $ticket = $this->reviewQuery->ticket($ticketId, $token);

        return $ticket->attachments()->findOrFail($attachmentId);
    }

    public function download(TicketAttachment $attachment): StreamedResponse
    {
        abort_unless($this->safePath($attachment->path), 404);
        abort_unless(in_array($attachment->storage_disk, ['public', 'private'], true), 404);
        $disk = Storage::disk($attachment->filesystemDisk());
        abort_unless($disk->exists($attachment->path), 404);
        $contentType = $disk->mimeType($attachment->path) ?: 'application/octet-stream';

        return $disk->download($attachment->path, $this->downloadName($attachment), [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function mailDescriptor(TicketAttachment $attachment): array
    {
        return ['disk' => $attachment->filesystemDisk(), 'path' => $attachment->path];
    }

    private function downloadName(TicketAttachment $attachment): string
    {
        return $this->safeFilename($attachment->original_name ?: basename($attachment->path));
    }

    private function safeFilename(string $filename): string
    {
        $cleanFilename = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '_', basename($filename));

        return $cleanFilename !== '' ? $cleanFilename : 'attachment';
    }

    private function safePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\')
            && ! in_array('..', explode('/', $path), true);
    }
}
