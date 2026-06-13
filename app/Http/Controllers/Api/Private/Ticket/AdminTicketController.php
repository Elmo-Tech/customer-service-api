<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Enums\Ticket\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\UpdateTicketRequest;
use App\Http\Resources\Ticket\AllTicketCollection;
use App\Http\Resources\Ticket\TicketResource;
use App\Mail\TicketDetails;
use App\Mail\ClosedTicketDetails;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Services\Ticket\TicketService;
use App\Utils\PaginateCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;


class AdminTicketController extends Controller
{
    protected $ticketService;
    public function __construct(TicketService $ticketService)
    {
        $this->middleware('auth:api');
        $this->middleware('permission:all_tickets', ['only' => ['allTickets']]);
        $this->middleware('permission:edit_ticket', ['only' => ['edit']]);
        $this->middleware('permission:update_ticket', ['only' => ['update']]);
        $this->middleware('permission:delete_ticket', ['only' => ['delete']]);
        $this->ticketService = $ticketService;
    }

    /**
     * Display a listing of the resource.
     */
    public function allTickets(Request $request)
    {
        $allTickets = $this->ticketService->allTickets();


        return response()->json(
            new AllTicketCollection(PaginateCollection::paginate($allTickets, $request->pageSize?$request->pageSize:10))
        , 200);

    }

    /**
     * Show the form for editing the specified resource.
     */

    public function edit(Request $request)
    {
        $ticket  =  $this->ticketService->editTicket($request->ticketId);

        return response()->json(
            new TicketResource($ticket)//new UserResource($user)
        ,200);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTicketRequest $updateTicketRequest)
    {

        try {
            DB::beginTransaction();

            $ticketData = $updateTicketRequest->validated();

            $ticket = $this->ticketService->updateTicket($ticketData);

            if($ticket->status == TicketStatus::IN_PROGRESS->value || $ticket->status == TicketStatus::DONE->value){

                $status = [
                    '1' => 'Chiusa',
                    '2' => 'In corso',
                ];
                
                $importance = [
                    '0' => 'Verde',
                    '1' => 'Giallo',
                    '2' => 'Rosso',
                ];


                $customer = Customer::find($ticket->customer_id);

                $companyName = Company::find($ticket->company_id);

                //$subject = "ticket: " . $ticket->ticket_number . " | " . $status[$ticket->status] . ' | ' . Carbon::parse($ticket->updated_at)->format('d/m/Y H:i:s') . " | " . $customer->getFullName() . ' | ' . $companyName->name;
                
                $subject = "ticket: " . $ticket->ticket_number . " | " . $importance[$ticket->importance] . ' | ' . $customer->getFullName() . ' | ' . $companyName->name;
                
                

                $content = [
                    'subject' => $subject,
                    'body' => "stato: " . $status[$ticket->status] . " | data: " . ($ticket->status == 1? Carbon::parse($ticket->closed_at)->format('d/m/Y H:m') :  Carbon::parse($ticket->updated_at)->format('d/m/Y H:m')) . "<br><br><br>" . $ticket->description
                ];

                $user= Auth::user();
                //if($customer->email){

                    //Mail::to($customer->email)->send(new TicketDetails($content));
                    
                    Mail::to('it-arca@arcagroup.eu')->send(new TicketDetails($content));
                    Mail::to('mr10dev10@gmail.com')->send(new TicketDetails($content));
                    Mail::to('s.mohamed@elmotech.it')->send(new TicketDetails($content)); //it-arca@arcagroup.eu


                //}
                
                    //         Mail::to($user->email)
    // ->bcc('it-arca@arcagroup.eu')
    // ->send(new TicketDetails($content));
    
                if($ticket->status == 1){
                    $uuid = (string) Str::uuid();
                    
                    $content['token'] = $uuid;
                    $content['ticketId'] = $ticket->id;
                    
                    $ticket->token = $uuid;
                    $ticket->save();

                    Mail::to('mr10dev10@gmail.com')->send(new ClosedTicketDetails($content));
                    if($customer->email){
                        Mail::to($customer->email)->send(new ClosedTicketDetails($content));
                    }
                    
                    //Mail::to('ms5325749@gmail.com')->send(new ClosedTicketDetails($content));
                } else{
                    if($customer->email){
                        Mail::to($customer->email)->send(new TicketDetails($content));
                    }
                }

                
                
                

            }

            DB::commit();
            return response()->json([
                 'message' => 'the ticket has been updated!'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(Request $request)
    {

        try {
            DB::beginTransaction();
            $this->ticketService->deleteTicket($request->ticketId);
            DB::commit();
            return response()->json([
                'message' => 'ticket has been deleted!'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

}
