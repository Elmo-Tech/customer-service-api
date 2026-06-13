<?php

namespace App\Http\Controllers\Api\Private\Ticket;
use App\Http\Controllers\Controller; // ✅ Add this

use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class TicketPublicController extends Controller
{
    public function show(Request $request)
    {
        $request->validate([
            'ticketId' => 'required|integer',
            'token'    => 'required|string',
        ]);

        $ticketId = $request->ticketId;
        $token    = $request->token;

        /**
         * ❌ لو التوكن مستخدم قبل كده
         */
        $tokenUsed = TicketLog::where('ticket_id', $ticketId)
            ->where('token', $token)
            ->exists();

        if ($tokenUsed) {
            return response()->json([
                'message' => 'This link has already been used.'
            ], 403);
        }

        /**
         * ✅ تحميل كل الداتا المطلوبة
         */
        $ticket = Ticket::with([
            'customer:id,firstname,lastname',
            'company:id,name',
            'attachments:id,ticket_id,path'
        ])->where('id', $ticketId)->first();

        if (!$ticket) {
            return response()->json([
                'message' => 'Ticket not found'
            ], 404);
        }

        return response()->json(['data' =>[
            'ticketId' => $ticket->id,
            'ticketNumber' => $ticket->ticket_number,
            'status'        => $ticket->status,
            'importance'    => $ticket->importance,
            'description'   => $ticket->description,

            'customer' => [
                'id'   => $ticket->customer->id ?? null,
                'name' => trim(
                    ($ticket->customer->firstname ?? '') . ' ' .
                    ($ticket->customer->lastname ?? '')
                ),
            ],

            'company' => [
                'id'   => $ticket->company->id ?? null,
                'name' => $ticket->company->name ?? null,
            ],

            'attachments' => $ticket->attachments->map(fn ($file) => [
                'id'   => $file->id,
                'path' => $file->path,
                'url' => Storage::disk('public')->url($file->path),
            ]),
            'closedAt' => Carbon::parse($ticket->closed_at)->format('d/m/Y H:i')
        ]]);
        
       

    }
}
