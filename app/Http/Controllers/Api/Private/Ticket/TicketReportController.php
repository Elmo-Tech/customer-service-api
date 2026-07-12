<?php

namespace App\Http\Controllers\Api\Private\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\FilterTicketsRequest;
use App\Services\Ticket\TicketDashboardService;
use App\Services\Ticket\TicketExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketReportController extends Controller
{
    public function __construct(
        private readonly TicketExportService $exportService,
        private readonly TicketDashboardService $dashboardService,
    ) {
        $this->middleware('auth:api');
        $this->middleware('permission:export_tickets')->only('export');
        $this->middleware('permission:view_ticket_dashboard')->only('dashboard');
    }

    public function export(FilterTicketsRequest $request): StreamedResponse
    {
        return $this->exportService->download($request->user(), $request->filters());
    }

    public function dashboard(FilterTicketsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboardService->metrics(
            $request->user(),
            $request->filters(),
        )]);
    }
}
