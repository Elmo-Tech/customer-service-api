<?php

namespace App\Http\Controllers\Api\Public\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\IndexCustomerTicketsRequest;
use App\Http\Requests\Ticket\ShowCustomerTicketRequest;
use App\Http\Resources\Ticket\CustomerTicketCollection;
use App\Http\Resources\Ticket\CustomerTicketResource;
use App\Services\Ticket\CustomerTicketDashboardService;
use App\Utils\PaginateCollection;
use Illuminate\Http\JsonResponse;

class CustomerTicketDashboardController extends Controller
{
    public function __construct(private readonly CustomerTicketDashboardService $dashboard) {}

    public function index(IndexCustomerTicketsRequest $request): JsonResponse
    {
        $tickets = $this->dashboard->allTickets(
            $request->validated('token'),
            $request->validated('filter', []),
        );

        return response()->json(new CustomerTicketCollection(
            PaginateCollection::paginate($tickets, $request->integer('pageSize', 10)),
        ))->header('Cache-Control', 'no-store');
    }

    public function show(ShowCustomerTicketRequest $request): JsonResponse
    {
        $ticket = $this->dashboard->show($request->validated('token'), $request->integer('ticketId'));

        return response()->json(new CustomerTicketResource($ticket))
            ->header('Cache-Control', 'no-store');
    }
}
