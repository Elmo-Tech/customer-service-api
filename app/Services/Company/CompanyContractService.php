<?php

namespace App\Services\Company;

use App\Models\Company\Company;
use App\Models\Company\CompanyContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class CompanyContractService
{
    public function allContracts(int $companyId, int $pageSize = 10)
    {
        Company::findOrFail($companyId);

        return CompanyContract::withTrashed()->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($pageSize);
    }

    public function show(int $companyId, int $contractId): CompanyContract
    {
        Company::findOrFail($companyId);

        return CompanyContract::withTrashed()->where('company_id', $companyId)->findOrFail($contractId);
    }

    public function create(array $data): CompanyContract
    {
        return DB::transaction(function () use ($data) {
            $this->lockCompany($data['companyId']);
            $this->ensureNoActiveContract($data['companyId']);

            return CompanyContract::create([
                'company_id' => $data['companyId'],
                'start_date' => $data['startDate'],
                'end_date' => $data['endDate'],
            ]);
        });
    }

    public function update(array $data): CompanyContract
    {
        return DB::transaction(function () use ($data) {
            $this->lockCompany($data['companyId']);
            $contract = $this->activeContractForMutation($data['companyId'], $data['contractId']);
            $contract->update(['start_date' => $data['startDate'], 'end_date' => $data['endDate']]);

            return $contract;
        });
    }

    public function renew(int $companyId): CompanyContract
    {
        return DB::transaction(function () use ($companyId) {
            $this->lockCompany($companyId);
            $this->ensureNoActiveContract($companyId);
            $previous = CompanyContract::where('company_id', $companyId)
                ->whereDate('end_date', '<', today()->toDateString())
                ->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
            if (! $previous) {
                $this->conflict('companyId', 'No expired contract is available for renewal.');
            }

            // Dates are inclusive: a same-day contract renews for one day.
            $daysBetween = (int) $previous->start_date->diffInDays($previous->end_date);

            return CompanyContract::create([
                'company_id' => $companyId,
                'start_date' => today()->toDateString(),
                'end_date' => today()->addDays($daysBetween)->toDateString(),
            ]);
        });
    }

    public function delete(int $companyId, int $contractId): void
    {
        DB::transaction(function () use ($companyId, $contractId) {
            $this->lockCompany($companyId);
            $this->activeContractForMutation($companyId, $contractId)->delete();
        });
    }

    private function lockCompany(int $companyId): void
    {
        // All contract writers lock the parent first, including when no contract exists yet.
        Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
    }

    private function ensureNoActiveContract(int $companyId): void
    {
        if (CompanyContract::where('company_id', $companyId)->active()->lockForUpdate()->first()) {
            $this->conflict('companyId', 'The company already has an active contract.',
                'Update or delete the active contract before creating or renewing a contract.');
        }
    }

    private function activeContractForMutation(int $companyId, int $contractId): CompanyContract
    {
        $contract = CompanyContract::withTrashed()->where('company_id', $companyId)
            ->lockForUpdate()->findOrFail($contractId);
        if ($contract->trashed() || ! $contract->isActive()) {
            $this->conflict('contractId', 'Only an active, non-deleted contract can be updated or deleted.');
        }

        return $contract;
    }

    private function conflict(string $field, string $message, ?string $detail = null): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => [$field => [$detail ?? $message]],
        ], 409));
    }
}
