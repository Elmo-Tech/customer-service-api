<?php

namespace App\Http\Resources\Ticket;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketTimelineResource extends JsonResource
{
    private $logs;

    private array $pagination;

    public function __construct($resource, $logs)
    {
        $this->pagination = [
            'total' => $logs->total(),
            'count' => $logs->count(),
            'perPage' => $logs->perPage(),
            'currentPage' => $logs->currentPage(),
            'totalPages' => $logs->lastPage(),
        ];

        $this->logs = $logs->getCollection();

        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'result' => [
                'ticket' => [
                    'ticketNumber' => $this->ticket_number,
                    'createdAt' => Carbon::parse($this->created_at)->format('Y-m-d H:i:s'),
                    'closedAt' => $this->closed_at ? Carbon::parse($this->closed_at)->format('Y-m-d H:i:s') : null,
                    'customerName' => $this->customer?->getFullName(),
                    'company' => $this->company?->name,
                    'priority' => (int) $this->getRawOriginal('importance'),
                    'status' => (int) $this->getRawOriginal('status'),
                ],
                'ticketMessages' => TicketTimelineLogResource::collection($this->logs),
            ],
            'pagination' => $this->pagination,
        ];
    }
}
