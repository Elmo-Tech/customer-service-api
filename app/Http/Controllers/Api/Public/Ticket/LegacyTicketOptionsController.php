<?php

namespace App\Http\Controllers\Api\Public\Ticket;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Parameter\parameterValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyTicketOptionsController extends Controller
{
    public function index(): JsonResponse
    {
        $companies = Company::query()
            ->where('status', CompanyStatus::ACTIVE->value)
            ->where('legacy_ticket_enabled', true)
            ->get(['id as value', 'name as label']);
        $customers = Customer::query()
            ->where('status', CustomerStatus::ACTIVE->value)
            ->whereHas('company', fn ($query) => $query
                ->where('status', CompanyStatus::ACTIVE->value)
                ->where('legacy_ticket_enabled', true))
            ->selectRaw('id as value, CONCAT(firstname, " ", lastname) as label')
            ->get();
        $tags = parameterValue::query()
            ->where('parameter_id', 1)
            ->get(['id as value', 'parameter_value as label']);

        return response()->json([
            ['label' => 'companies', 'options' => $companies],
            ['label' => 'customers', 'options' => $customers],
            ['label' => 'parameters', 'options' => $tags],
        ])->header('Cache-Control', 'no-store');
    }

    public function branches(Request $request): JsonResponse
    {
        $fields = $request->validate(['companyId' => ['required', 'integer']]);
        $companyId = Company::query()
            ->whereKey($fields['companyId'])
            ->where('status', CompanyStatus::ACTIVE->value)
            ->where('legacy_ticket_enabled', true)
            ->value('id');
        $branches = $companyId ? Branch::query()
            ->where('company_id', $companyId)
            ->where('status', BranchStatus::ACTIVE->value)
            ->get(['id as value', 'name as label']) : [];

        return response()->json([
            ['label' => 'branches', 'options' => $branches],
        ])->header('Cache-Control', 'no-store');
    }
}
