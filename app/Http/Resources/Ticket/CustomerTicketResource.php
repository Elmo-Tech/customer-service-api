<?php

namespace App\Http\Resources\Ticket;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CustomerTicketResource extends TicketResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'fileName' => basename($attachment->path),
                'url' => Storage::disk('public')->url($attachment->path),
            ])),
            'timelineToken' => $this->timeline_token,
        ];
    }
}
