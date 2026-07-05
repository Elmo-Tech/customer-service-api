<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Mail\TicketDetails;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Services\Ticket\TicketService;
use App\Services\Upload\UploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;


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

            $cutomerAuth = Customer::where('id', $ticketData['customerId'])->where('pin', $ticketData['pin'])->first();

            if (!$cutomerAuth) {
                return response()->json([
                    'message' => 'no atuh'
                ], 401);
            }

            $ticket = $this->ticketService->createTicket($createTicketRequest->validated());


            $ticketAttachments = $ticketData['attachments']??[];
            $attachments = [];


            foreach ($ticketAttachments as $key => $attachmentData) {

                $attachment = $this->uploadService->uploadFile($attachmentData, "tickets/$ticket->id");
                $attachments[] = $attachment;

                $ticket->attachments()->create([
                    'path' => $attachment
                ]);
            }

            $importance = [
                '0' => 'Verde',
                '1' => 'Giallo',
                '2' => 'Rosso',
            ];

            $customerName = Customer::find($ticket->customer_id);
            
            

            $companyName = Company::find($ticket->company_id);

            $subject = "ticket: " . $ticket->ticket_number . " | " . $importance[$ticket->importance] . ' | ' . $customerName->getFullName() . ' | ' . $companyName->name;

            $content = [
                'subject' => $subject,
                'body' => $ticket->description,
                'attachments' => $attachments
            ];
            
            $user = Auth::user();
            

            Mail::to('it-arca@arcagroup.eu')->send(new TicketDetails($content)); //it-arca@arcagroup.eu
            Mail::to('ms5325749@gmail.com')->send(new TicketDetails($content)); //it-arca@arcagroup.eu
            Mail::to('mr10dev10@gmail.com')->send(new TicketDetails($content)); //it-arca@arcagroup.eu
                        Mail::to('s.mohamed@elmotech.it')->send(new TicketDetails($content)); //it-arca@arcagroup.eu

            if($cutomerAuth->email){
                Mail::to($cutomerAuth->email)->send(new TicketDetails($content)); //it-arca@arcagroup.eu
            }
    //         Mail::to($user->email)
    // ->bcc('it-arca@arcagroup.eu')
    // ->send(new TicketDetails($content));



            DB::commit();

            return response()->json([
                'message' => 'ticket has been created!'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

}
