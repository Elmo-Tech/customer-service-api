<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ticket\TicketReviewResource;
use App\Services\Ticket\TicketReviewCookie;
use App\Services\Ticket\TicketReviewQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketPublicController extends Controller
{
    public function __construct(
        private readonly TicketReviewQuery $reviewQuery,
        private readonly TicketReviewCookie $reviewCookie,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $review = $request->validate([
            'ticketId' => ['required', 'integer'],
            'token' => ['required', 'string'],
        ]);
        $ticket = $this->reviewQuery->ticket((int) $review['ticketId'], $review['token'], [
            'customer:id,firstname,lastname',
            'openedBy:id,name',
            'company:id,name',
            'attachments:id,ticket_id',
        ]);

        return response()->json(['data' => new TicketReviewResource($ticket)])
            ->withCookie($this->reviewCookie->make($ticket->id, $review['token']));
    }
}
