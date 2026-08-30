<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Enums\Ticket\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Mail\TicketDetails;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketTimelineLog;
use App\Services\Ticket\TicketService;
use App\Services\Upload\UploadService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class CustomerTicketController extends Controller
{
    protected $ticketService;

    protected $uploadService;

    public function __construct(TicketService $ticketService, UploadService $uploadService)
    {
        $this->ticketService = $ticketService;
        $this->uploadService = $uploadService;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(CreateTicketRequest $createTicketRequest)
    {

        try {
            DB::beginTransaction();

            $ticketData = $createTicketRequest->validated();

            $cutomerAuth = Customer::query()
                ->whereKey($ticketData['customerId'])
                ->where('status', CustomerStatus::ACTIVE->value)
                ->whereHas('company', fn ($query) => $query
                    ->where('status', CompanyStatus::ACTIVE->value)
                    ->where('legacy_ticket_enabled', true))
                ->first();

            $validPin = $cutomerAuth && hash_equals(
                hash('sha256', (string) $cutomerAuth->pin),
                hash('sha256', (string) $ticketData['pin']),
            );
            $branchId = Branch::query()
                ->whereKey($ticketData['branchId'])
                ->where('company_id', $cutomerAuth?->company_id)
                ->where('status', BranchStatus::ACTIVE->value)
                ->value('id');

            if (! $validPin || ! $branchId) {
                throw ValidationException::withMessages([
                    'credentials' => 'Dati cliente non validi.',
                ]);
            }

            $ticket = $this->ticketService->createTicket([
                ...$ticketData,
                'companyId' => $cutomerAuth->company_id,
                'customerId' => $cutomerAuth->id,
                'branchId' => $branchId,
                'status' => TicketStatus::OPENED->value,
            ]);

            $ticketAttachments = $ticketData['attachments'] ?? [];
            $attachments = [];
            $uploadedAttachments = [];

            foreach ($ticketAttachments as $key => $attachmentData) {

                $attachment = $this->uploadService->uploadFile($attachmentData, "tickets/$ticket->id");
                $attachments[] = $attachment;
                $uploadedAttachments[] = [
                    'file' => $attachmentData,
                    'path' => $attachment,
                ];

                $ticket->attachments()->create([
                    'path' => $attachment,
                ]);
            }

            $this->createInitialTimelineMessage($ticket, $cutomerAuth, $uploadedAttachments);

            $importance = [
                '0' => 'Verde',
                '1' => 'Giallo',
                '2' => 'Rosso',
            ];

            $customerName = Customer::find($ticket->customer_id);

            $companyName = Company::find($ticket->company_id);

            $subject = 'ticket: '.$ticket->ticket_number.' | '.$importance[$ticket->importance].' | '.$customerName->getFullName().' | '.$companyName->name;

            $content = [
                'subject' => $subject,
                'body' => $ticket->description."<br><br><a href=\"".$this->timelineUrl($ticket)."\">Apri ticket</a>",
                'attachments' => $attachments,
            ];

            $user = Auth::user();

            Mail::to('it-arca@arcagroup.eu')->send(new TicketDetails($content)); // it-arca@arcagroup.eu
            Mail::to('ms5325749@gmail.com')->send(new TicketDetails($content)); // it-arca@arcagroup.eu
            Mail::to('mr10dev10@gmail.com')->send(new TicketDetails($content)); // it-arca@arcagroup.eu
            Mail::to('s.mohamed@elmotech.it')->send(new TicketDetails($content)); // it-arca@arcagroup.eu

            if ($cutomerAuth->email) {
                Mail::to($cutomerAuth->email)->send(new TicketDetails($content)); // it-arca@arcagroup.eu
            }
            //         Mail::to($user->email)
            // ->bcc('it-arca@arcagroup.eu')
            // ->send(new TicketDetails($content));

            DB::commit();

            return response()->json([
                'message' => 'ticket has been created!',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

    }

    private function createInitialTimelineMessage(Ticket $ticket, Customer $customer, array $uploadedAttachments): void
    {
        $log = $ticket->timelineLogs()->create([
            'type' => TicketTimelineLog::TYPE_MESSAGE,
            'actor_type' => TicketTimelineLog::ACTOR_CUSTOMER,
            'user_id' => $customer->id,
            'user_name' => $customer->getFullName(),
            'message' => $ticket->description,
        ]);

        foreach ($uploadedAttachments as $attachment) {
            $file = $attachment['file'];

            $log->attachments()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $attachment['path'],
                'file_size' => $file->getSize(),
                'mime_type' => $file->getClientMimeType(),
            ]);
        }
    }

    private function timelineUrl($ticket): string
    {
        return 'http://tickets.testingelmo.com/tickets/timeline?ticketId='.$ticket->id.'&timelineToken='.$ticket->timeline_token;
    }
}
