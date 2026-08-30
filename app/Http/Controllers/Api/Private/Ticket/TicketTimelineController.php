<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\ShowCustomerTicketTimelineRequest;
use App\Http\Requests\Ticket\StoreCustomerTicketTimelineMessageRequest;
use App\Http\Requests\Ticket\StoreTicketTimelineMessageRequest;
use App\Http\Resources\Ticket\TicketTimelineLogResource;
use App\Http\Resources\Ticket\TicketTimelineResource;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketTimelineLog;
use App\Services\Upload\UploadService;
use App\Utils\PaginateCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketTimelineController extends Controller
{
    public function __construct(private readonly UploadService $uploads)
    {
        $this->middleware('auth:api')->only(['timeline', 'storeAdminMessage']);
        $this->middleware('permission:edit_ticket')->only(['timeline']);
        $this->middleware('permission:update_ticket')->only(['storeAdminMessage']);
    }

    public function timeline(Request $request): JsonResponse
    {
        $request->validate([
            'ticketId' => ['required', 'integer', 'exists:tickets,id'],
        ]);

        $ticket = $this->findTimelineTicket($request->integer('ticketId'));
        $logs = $this->timelineLogs($ticket, $request);

        return response()->json(new TicketTimelineResource($ticket, $logs), 200);
    }

    public function customerTimeline(ShowCustomerTicketTimelineRequest $request): JsonResponse
    {
        $ticket = $this->findCustomerTimelineTicket($request->integer('ticketId'), $request->input('token'));
        $logs = $this->timelineLogs($ticket, $request);

        return response()->json(new TicketTimelineResource($ticket, $logs), 200);
    }

    public function storeAdminMessage(StoreTicketTimelineMessageRequest $request): JsonResponse
    {
        $ticket = Ticket::findOrFail($request->integer('ticketId'));
        $user = Auth::user();

        $log = DB::transaction(function () use ($request, $ticket, $user): TicketTimelineLog {
            return $this->createMessageLog(
                ticket: $ticket,
                actorType: TicketTimelineLog::ACTOR_ADMIN,
                userId: $user?->id,
                userName: $user?->name ?? 'Admin',
                message: $request->input('message'),
                files: $request->file('attachments', []),
            );
        });

        return response()->json([
            'data' => new TicketTimelineLogResource($log),
        ], 201);
    }

    public function storeCustomerMessage(StoreCustomerTicketTimelineMessageRequest $request): JsonResponse
    {
        $ticket = $this->findCustomerTimelineTicket($request->integer('ticketId'), $request->input('token'));
        $customer = $ticket->customer;

        $log = DB::transaction(function () use ($request, $ticket, $customer): TicketTimelineLog {
            return $this->createMessageLog(
                ticket: $ticket,
                actorType: TicketTimelineLog::ACTOR_CUSTOMER,
                userId: $customer?->id,
                userName: $customer?->getFullName() ?? 'Customer',
                message: $request->input('message'),
                files: $request->file('attachments', []),
            );
        });

        return response()->json([
            'data' => new TicketTimelineLogResource($log),
        ], 201);
    }

    private function createMessageLog(Ticket $ticket, int $actorType, ?int $userId, string $userName, string $message, array $files): TicketTimelineLog
    {
        $log = $ticket->timelineLogs()->create([
            'type' => TicketTimelineLog::TYPE_MESSAGE,
            'actor_type' => $actorType,
            'user_id' => $userId,
            'user_name' => $userName,
            'message' => $message,
        ]);

        foreach ($files as $file) {
            $path = $this->uploads->uploadFile($file, "tickets/{$ticket->id}/messages");

            $log->attachments()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getClientMimeType(),
            ]);
        }

        return $log->load('attachments');
    }

    private function findTimelineTicket(int $ticketId): Ticket
    {
        return Ticket::with([
            'customer',
            'company',
        ])->findOrFail($ticketId);
    }

    private function timelineLogs(Ticket $ticket, Request $request)
    {
        $logs = $ticket->timelineLogs()
            ->with('attachments')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return PaginateCollection::paginate($logs, $request->pageSize ? $request->pageSize : 10);
    }

    private function findCustomerTimelineTicket(int $ticketId, string $token): Ticket
    {
        $ticket = $this->findTimelineTicket($ticketId);

        if (! $ticket->timeline_token || ! hash_equals((string) $ticket->timeline_token, $token)) {
            throw ValidationException::withMessages([
                'ticketId' => 'Non sei autorizzato ad accedere a questo ticket.',
            ]);
        }

        return $ticket;
    }
}
