<?php

namespace App\Http\Controllers\Api\Public\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\CreatePublicTicketRequest;
use App\Http\Requests\Ticket\IdentifyPublicTicketRequesterRequest;
use App\Mail\TicketDetails;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketTimelineLog;
use App\Services\Ticket\PublicTicketSubmissionService;
use App\Services\Upload\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PublicTicketSubmissionController extends Controller
{
    private const INTERNAL_RECIPIENTS = [
        'it-arca@arcagroup.eu',
        'ms5325749@gmail.com',
        'mr10dev10@gmail.com',
        's.mohamed@elmotech.it',
    ];

    public function __construct(
        private readonly PublicTicketSubmissionService $submission,
        private readonly UploadService $uploads,
    ) {}

    public function identify(IdentifyPublicTicketRequesterRequest $request): JsonResponse
    {
        $fields = $request->validated();

        return response()->json([
            'data' => $this->submission->identify($fields['username'], $fields['pin']),
        ])->header('Cache-Control', 'no-store');
    }

    public function create(CreatePublicTicketRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $fields = $request->validated();
            $ticket = $this->submission->create($fields['ticketToken'], $fields);
            $attachments = [];
            $uploadedAttachments = [];

            foreach ($fields['attachments'] ?? [] as $uploadedFile) {
                $path = $this->uploads->uploadFile($uploadedFile, "tickets/{$ticket->id}");
                $attachments[] = $path;
                $uploadedAttachments[] = [
                    'file' => $uploadedFile,
                    'path' => $path,
                ];
                $ticket->attachments()->create(['path' => $path]);
            }

            $this->createInitialTimelineMessage($ticket, $uploadedAttachments);
            $this->sendNotifications($ticket, $attachments);
        });

        return response()->json(['message' => 'ticket has been created!'], 201)
            ->header('Cache-Control', 'no-store');
    }

    private function sendNotifications(Ticket $ticket, array $attachments): void
    {
        $ticket->loadMissing(['customer', 'company']);
        $importance = [0 => 'Verde', 1 => 'Giallo', 2 => 'Rosso'];
        $content = [
            'subject' => "ticket: {$ticket->ticket_number} | {$importance[$ticket->importance]} | "
                ."{$ticket->customer->getFullName()} | {$ticket->company->name}",
            'body' => $ticket->description.'<br><br><a href="'.$this->timelineUrl($ticket).'">Apri ticket</a>',
            'attachments' => $attachments,
        ];

        foreach (self::INTERNAL_RECIPIENTS as $recipient) {
            Mail::to($recipient)->send(new TicketDetails($content));
        }
        if ($ticket->customer->email) {
            Mail::to($ticket->customer->email)->send(new TicketDetails($content));
        }
    }

    private function timelineUrl(Ticket $ticket): string
    {
        return 'http://tickets.testingelmo.com/tickets/timeline?ticketId='.$ticket->id.'&token='.$ticket->timeline_token;
    }

    private function createInitialTimelineMessage(Ticket $ticket, array $uploadedAttachments): void
    {
        $ticket->loadMissing('customer');

        $log = $ticket->timelineLogs()->create([
            'type' => TicketTimelineLog::TYPE_MESSAGE,
            'actor_type' => TicketTimelineLog::ACTOR_CUSTOMER,
            'user_id' => $ticket->customer_id,
            'user_name' => $ticket->customer?->getFullName() ?? 'Customer',
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
}
