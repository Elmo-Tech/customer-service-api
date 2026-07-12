<?php

namespace App\Services\Tenancy;

use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

class TenantAuditReport
{
    public function contents(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'users' => $this->users(),
            'roles' => $this->roles(),
            'companies' => $this->companies(),
            'branches' => $this->branches(),
            'customer_clues' => $this->customerClues(),
            'ticket_relationship_counts' => $this->ticketRelationshipCounts(),
        ];
    }

    private function users(): array
    {
        return User::withTrashed()->with('roles:id,name')->orderBy('id')->get(
            $this->existingColumns(
                'users',
                ['id', 'username', 'email', 'name', 'status', 'deleted_at'],
                ['account_type', 'company_id', 'branch_id'],
            ),
        )
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'name' => $user->name,
                'status' => $user->status,
                'current_account_type' => $user->account_type,
                'current_company_id' => $user->company_id,
                'current_branch_id' => $user->branch_id,
                'roles' => $user->roles->pluck('name')->values()->all(),
                'deleted_at' => $user->deleted_at?->toIso8601String(),
            ])->all();
    }

    private function roles(): array
    {
        return Role::orderBy('id')->get(['id', 'name', 'guard_name'])
            ->map(fn (Role $role) => $role->only(['id', 'name', 'guard_name']))
            ->all();
    }

    private function companies(): array
    {
        return Company::withTrashed()->orderBy('id')->get(
            $this->existingColumns(
                'companies',
                ['id', 'name', 'status', 'deleted_at'],
                ['uses_branches'],
            ),
        )
            ->map(fn (Company $company) => [
                'company_id' => $company->id,
                'name' => $company->name,
                'status' => $company->status,
                'uses_branches' => $company->uses_branches,
                'deleted_at' => $company->deleted_at?->toIso8601String(),
            ])->all();
    }

    private function branches(): array
    {
        return Branch::withTrashed()->orderBy('id')->get([
            'id', 'company_id', 'name', 'status', 'deleted_at',
        ])->map(fn (Branch $branch) => [
            'branch_id' => $branch->id,
            'company_id' => $branch->company_id,
            'name' => $branch->name,
            'status' => $branch->status,
            'deleted_at' => $branch->deleted_at?->toIso8601String(),
        ])->all();
    }

    private function customerClues(): array
    {
        return Customer::withTrashed()->orderBy('id')->get(
            $this->existingColumns(
                'customers',
                ['id', 'company_id', 'firstname', 'lastname', 'email', 'status', 'deleted_at'],
                ['branch_id', 'user_id'],
            ),
        )
            ->map(fn (Customer $customer) => [
                'customer_id' => $customer->id,
                'company_id' => $customer->company_id,
                'branch_id' => $customer->branch_id,
                'linked_user_id' => $customer->user_id,
                'name' => $customer->getFullName(),
                'email' => $customer->email,
                'status' => $customer->status,
                'deleted_at' => $customer->deleted_at?->toIso8601String(),
            ])->all();
    }

    private function ticketRelationshipCounts(): array
    {
        return Ticket::withTrashed()
            ->selectRaw('company_id, branch_id, customer_id, count(*) as ticket_count')
            ->groupBy('company_id', 'branch_id', 'customer_id')
            ->orderBy('company_id')->orderBy('branch_id')->orderBy('customer_id')
            ->get()->map(fn (Ticket $ticket) => [
                'company_id' => $ticket->company_id,
                'branch_id' => $ticket->branch_id,
                'customer_id' => $ticket->customer_id,
                'ticket_count' => (int) $ticket->ticket_count,
            ])->all();
    }

    private function existingColumns(string $table, array $baseColumns, array $optionalColumns): array
    {
        $existingOptionalColumns = array_filter(
            $optionalColumns,
            fn (string $column) => Schema::hasColumn($table, $column),
        );

        return array_merge($baseColumns, $existingOptionalColumns);
    }
}
