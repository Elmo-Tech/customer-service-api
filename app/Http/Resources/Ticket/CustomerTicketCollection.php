<?php

namespace App\Http\Resources\Ticket;

use Illuminate\Http\Request;

class CustomerTicketCollection extends AllTicketCollection
{
    public $collects = AllTicketResoruce::class;

    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $pagination = $data['pagination'];
        $data['pagination'] = [
            'total' => $pagination['total'],
            'count' => $pagination['count'],
            'perPage' => $pagination['per_page'],
            'currentPage' => $pagination['current_page'],
            'totalPages' => $pagination['total_pages'],
        ];

        return $data;
    }
}
