<?php

namespace App\Http\Resources\Ticket;

use App\Models\Tiket\TicketTimelineLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketTimelineLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'type' => (int) $this->type,
            'actorType' => (int) $this->actor_type,
            'userId' => $this->user_id,
            'userName' => $this->user_name,
            'createdAt' => Carbon::parse($this->created_at)->format('Y-m-d H:i:s'),
        ];

        if ((int) $this->type === TicketTimelineLog::TYPE_MESSAGE) {
            $data['message'] = $this->message;
            $data['attachments'] = TicketTimelineAttachmentResource::collection($this->whenLoaded('attachments'));
        }

        if ((int) $this->type === TicketTimelineLog::TYPE_STATUS_CHANGE) {
            $data['oldStatus'] = $this->old_status;
            $data['newStatus'] = $this->new_status;
        }

        return $data;
    }
}
