<?php

namespace App\Http\Resources\Ticket;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AllTicketResoruce extends JsonResource
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
            'customerName' => $this->customer->firstname . " " . $this->customer->lastname,
            'companyName' => $this->company->name,
            'branchName' => $this->branch->name,
            'status' => $this->status,
            'importance' => $this->importance,
            'description' => $this->description,
            'createdAt' => Carbon::parse($this->created_at)->format("d/m/Y"),
            'closedAt' => $this->closed_at ? Carbon::parse($this->closed_at)->format('d/m/Y') : ''
        ];
    }
}
