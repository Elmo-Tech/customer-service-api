<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Enums\Ticket\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Services\Ticket\TicketAttachmentService;
use App\Services\Ticket\TicketCreationNotificationService;
use App\Services\Ticket\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CustomerTicketController extends Controller
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly TicketAttachmentService $attachmentService,
        private readonly TicketCreationNotificationService $notificationService,
    ) {}

    public function create(CreateTicketRequest $request): JsonResponse
    {
        $ticketFields = $request->validated();
        $customer = Customer::query()
            ->whereKey($ticketFields['customerId'])
            ->where('pin', $ticketFields['pin'])
            ->first();

        if (! $customer) {
            return response()->json(['message' => 'Invalid customer credentials.'], 401);
        }

        DB::transaction(function () use ($ticketFields, $customer): void {
            $ticket = $this->ticketService->createTicket([
                ...$ticketFields,
                'companyId' => $customer->company_id,
                'status' => TicketStatus::OPENED->value,
            ]);
            $attachmentPaths = $this->createAttachments($ticket, $ticketFields['attachments'] ?? []);
            $this->notificationService->send($ticket, $attachmentPaths);
        });

        return response()->json(['message' => 'ticket has been created!']);
    }

    private function createAttachments(Ticket $ticket, array $uploadedFiles): array
    {
        $attachmentPaths = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $attachment = $this->attachmentService->store($ticket, $uploadedFile);
            $attachmentPaths[] = $this->attachmentService->mailDescriptor($attachment);
        }

        return $attachmentPaths;
    }
}
