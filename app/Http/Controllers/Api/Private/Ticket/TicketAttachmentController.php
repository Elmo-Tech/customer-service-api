<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Http\Controllers\Controller;
use App\Services\Ticket\TicketAttachmentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentController extends Controller
{
    public function __construct(
        private readonly TicketAttachmentService $attachmentService,
        private readonly \App\Services\Ticket\TicketReviewCookie $reviewCookie,
    ) {
        $this->middleware('auth:api')->only('download');
    }

    public function download(Request $request, int $ticketId, int $attachmentId): StreamedResponse
    {
        $attachment = $this->attachmentService->authenticatedAttachment(
            $request->user(),
            $ticketId,
            $attachmentId,
        );

        return $this->attachmentService->download($attachment);
    }

    public function reviewDownload(Request $request, int $ticketId, int $attachmentId): StreamedResponse
    {
        $secret = $request->string('token')->toString()
            ?: (string) $request->cookie($this->reviewCookie->name($ticketId), '');
        abort_if($secret === '', 404);
        $attachment = $this->attachmentService->reviewAttachment($ticketId, $attachmentId, $secret);

        return $this->attachmentService->download($attachment);
    }
}
