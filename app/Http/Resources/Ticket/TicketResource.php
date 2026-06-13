<?php

namespace App\Http\Resources\Ticket;

use App\Http\Resources\Ticket\Attachment\AttachmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ticketId' => $this->id,
            'ticketNumber' => $this->ticket_number,
            'customerId' => $this->customer_id,
            'companyId' => $this->company_id,
            'branchId' => $this->branch_id,
            'status' => $this->status,
            'importance' => $this->importance,
            'description' => $this->description,
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'tagId' => $this->tag_id??"",
            'closedAt' => $this->closed_at??""
        ];
    }
}
