<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\CreateAuthenticatedTicketRequest;
use App\Http\Requests\Ticket\FilterTicketsRequest;
use App\Http\Requests\Ticket\UpdateTicketRequest;
use App\Http\Resources\Ticket\AllTicketCollection;
use App\Http\Resources\Ticket\TicketResource;
use App\Services\Ticket\TicketAttachmentService;
use App\Services\Ticket\TicketCreationNotificationService;
use App\Services\Ticket\TicketNotificationService;
use App\Services\Ticket\TicketService;
use App\Utils\PaginateCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTicketController extends Controller
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly TicketNotificationService $notificationService,
        private readonly TicketAttachmentService $attachmentService,
        private readonly TicketCreationNotificationService $creationNotificationService,
    ) {
        $this->middleware('auth:api');
        $this->middleware('permission:all_tickets')->only('allTickets');
        $this->middleware('permission:edit_ticket')->only('edit');
        $this->middleware('permission:update_ticket')->only('update');
        $this->middleware('permission:delete_ticket')->only('delete');
        $this->middleware('permission:create_ticket|all_tickets')->only('create');
    }

    public function create(CreateAuthenticatedTicketRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $fields = $request->validated();
            $ticket = $this->ticketService->createAuthenticatedTicket($request->user(), $fields);
            $mailAttachments = [];
            foreach ($fields['attachments'] ?? [] as $uploadedFile) {
                $attachment = $this->attachmentService->store($ticket, $uploadedFile);
                $mailAttachments[] = $this->attachmentService->mailDescriptor($attachment);
            }
            $this->creationNotificationService->send($ticket, $mailAttachments);
        });

        return response()->json(['message' => 'ticket has been created!'], 201);
    }

    public function allTickets(FilterTicketsRequest $request): JsonResponse
    {
        $tickets = $this->ticketService->allTickets($request->user(), $request->filters());
        $pageSize = $request->integer('pageSize', 10);

        return response()->json(
            new AllTicketCollection(PaginateCollection::paginate($tickets, $pageSize)),
        );
    }

    public function edit(Request $request): JsonResponse
    {
        $ticket = $this->ticketService->editTicket($request->user(), $request->integer('ticketId'));

        return response()->json(new TicketResource($ticket));
    }

    public function update(UpdateTicketRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $ticket = $this->ticketService->updateTicket($request->user(), $request->validated());
            $this->notificationService->sendStatusUpdate($ticket);
        });

        return response()->json(['message' => 'the ticket has been updated!']);
    }

    public function delete(Request $request): JsonResponse
    {
        DB::transaction(fn () => $this->ticketService->deleteTicket(
            $request->user(),
            $request->integer('ticketId'),
        ));

        return response()->json(['message' => 'ticket has been deleted!']);
    }
}
