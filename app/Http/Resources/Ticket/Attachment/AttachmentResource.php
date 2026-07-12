<?php

namespace App\Http\Resources\Ticket\Attachment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'attachmentId' => $this->id,
            'ticketId' => $this->ticket_id,
            'path' => route('tickets.attachments.download', [
                'ticketId' => $this->ticket_id,
                'attachmentId' => $this->id,
            ]),
        ];
    }
}
