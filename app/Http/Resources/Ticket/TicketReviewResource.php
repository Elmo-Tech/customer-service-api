<?php

namespace App\Http\Resources\Ticket;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ticketId' => $this->id,
            'ticketNumber' => $this->ticket_number,
            'status' => $this->status,
            'importance' => $this->importance,
            'description' => $this->description,
            'customer' => [
                'id' => $this->customer?->id,
                'name' => trim(($this->customer?->firstname ?? '').' '.($this->customer?->lastname ?? '')),
            ],
            'company' => ['id' => $this->company?->id, 'name' => $this->company?->name],
            'attachments' => $this->attachments->map(
                fn ($attachment) => $this->attachment($attachment->id),
            ),
            'closedAt' => $this->closed_at ? Carbon::parse($this->closed_at)->format('d/m/Y H:i') : null,
        ];
    }

    private function attachment(int $attachmentId): array
    {
        $downloadUrl = route('tickets.attachments.review-download', [
            'ticketId' => $this->id,
            'attachmentId' => $attachmentId,
        ]);

        return ['id' => $attachmentId, 'path' => $downloadUrl, 'url' => $downloadUrl];
    }
}
