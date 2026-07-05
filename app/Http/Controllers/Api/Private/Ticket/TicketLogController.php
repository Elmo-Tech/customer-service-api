<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Enums\Ticket\TicketStatus;
use App\Http\Controllers\Controller;
use App\Mail\TicketDetails;
use App\Models\Tiket\Ticket;
use App\Models\Tiket\TicketLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Ticket\TicketService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class TicketLogController extends Controller
{
    protected $ticketService;

    /*public function __construct(TicketService $ticketService)
    {
        //$this->ticketService = $ticketService;

        // 🔐 auth:api فقط على index
        $this->middleware('auth:api')->only('index');
    }*/

    /**
     * 📝 GET: جلب كل بيانات التذكرة
     * يحتاج auth:api
     */
    public function index(Request $request)
    {
        $request->validate([
            'ticketId' => 'required|integer|exists:tickets,id',
        ]);
    
        $ticketId = $request->input('ticketId');
    
        // جلب logs فقط
        $logs = TicketLog::where('ticket_id', $ticketId)->get();
    
        // تحويل المفاتيح لـ camelCase و id => ticketLogId
        $formattedLogs = $logs->map(function ($log) {
            return [
                'ticketLogId' => $log->id,
                'ticketId'    => $log->ticket_id,
                'status'      => $log->status,
                'text'        => $log->text,
                'createdAt'   => Carbon::parse($log->created_at)->format('d/m/Y H:i'),
            ];
        });
    
        return response()->json([
            'success' => true,
            'data'    => $formattedLogs,
        ]);
    }

    /**
     * 📝 POST: تسجيل استخدام التوكن
     * public بدون auth
     */
    public function store(Request $request)
    {
        $request->validate([
            'ticketId' => 'required|integer|exists:tickets,id',
            'token'    => 'required|string',
            'text' => 'required|string',
            'status' => 'required|integer|in:1,3'
        ]);

        $ticketId = $request->input('ticketId');
        $token    = $request->input('token');

        // التحقق من وجود التوكن في tickets
        $ticket = Ticket::where('id', $ticketId)
                        ->where('token', $token)
                        ->first();

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token or ticket ID.',
            ], 404);
        }

        // التحقق من عدم وجوده مسبقًا في ticket_logs
        $exists = TicketLog::where('ticket_id', $ticketId)
                           ->where('token', $token)
                           ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Token already used.',
            ], 409);
        }

        try {
            DB::beginTransaction();

            $log = TicketLog::create([
                'ticket_id' => $ticketId,
                'status'    => $request->status,
                'text'      => $request->status ==  1?'Grazie, il ticket è chiuso.' : $request->text,
                'token'     => $token,
            ]);
            
            $ticket->status = $request->status;
            $ticket->token = null;
            if($ticket->status == TicketStatus::REOPENED->value){
                $ticket->closed_at = null;
            }
            $ticket->save();

            if($ticket->status == TicketStatus::REOPENED->value){
                $ticket->load(['customer', 'company', 'attachments']);

                $content = [
                    'subject' => "ticket: " . $ticket->ticket_number . " | Riaperta | " . $ticket->customer->getFullName() . ' | ' . $ticket->company->name,
                    'body' => "stato: Riaperta | data: " . Carbon::parse($ticket->updated_at)->format('d/m/Y H:m') . "<br><br><br>" . $request->text,
                    'attachments' => $ticket->attachments->pluck('path')->all()
                ];

                Mail::to('it-arca@arcagroup.eu')->send(new TicketDetails($content));
                Mail::to('ms5325749@gmail.com')->send(new TicketDetails($content));
                Mail::to('mr10dev10@gmail.com')->send(new TicketDetails($content));
                Mail::to('s.mohamed@elmotech.it')->send(new TicketDetails($content));
            }
            

            DB::commit();

            return response()->json([
                'success' => true,
                'data'    => [],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to save log: ' . $e->getMessage(),
            ], 500);
        }
    }
}
