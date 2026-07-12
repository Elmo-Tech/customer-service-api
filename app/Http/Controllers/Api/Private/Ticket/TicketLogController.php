<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Http\Controllers\Controller;
use App\Models\Tiket\TicketLog;
use App\Services\Ticket\TicketReviewService;
use App\Services\Ticket\TicketService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketLogController extends Controller
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly TicketReviewService $reviewService,
    ) {
        $this->middleware(['auth:api', 'permission:all_tickets'])->only('index');
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['ticketId' => 'required|integer']);
        $ticketId = (int) $validated['ticketId'];
        $this->ticketService->findTicket($request->user(), $ticketId);
        $logs = TicketLog::query()->where('ticket_id', $ticketId)->get()->map(fn (TicketLog $log) => [
            'ticketLogId' => $log->id,
            'ticketId' => $log->ticket_id,
            'status' => $log->status,
            'text' => $log->text,
            'createdAt' => Carbon::parse($log->created_at)->format('d/m/Y H:i'),
        ]);

        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function store(Request $request): JsonResponse
    {
        $review = $request->validate([
            'ticketId' => 'required|integer',
            'token' => 'required|string',
            'text' => 'required|string',
            'status' => 'required|integer|in:1,3',
        ]);
        $this->reviewService->record(
            (int) $review['ticketId'],
            $review['token'],
            (int) $review['status'],
            $review['text'],
        );

        return response()->json(['success' => true, 'data' => []]);
    }
}
