<?php

namespace App\Services\Ticket\Import;

use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Parameter\parameterValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

class LegacyTicketAuditService
{
    public function __construct(private readonly LegacyTicketSourceReader $source) {}

    public function report(): array
    {
        $this->source->assertSchema();
        $legacy = DB::connection(config('legacy_ticket_import.connection'));
        $companyIds = $this->distinct($legacy, 'tickets', 'company_id');
        $branchIds = $this->distinct($legacy, 'tickets', 'branch_id');
        $customerIds = $this->distinct($legacy, 'tickets', 'customer_id');
        $userIds = $this->sourceUserIds($legacy);
        $tagIds = $this->optionalDistinct($legacy, 'tickets', 'tag_id');
        $statuses = $this->distinct($legacy, 'tickets', 'status');
        $importances = $this->distinct($legacy, 'tickets', 'importance');

        return [
            'source' => [
                'ticketCount' => $legacy->table('tickets')->count(),
                'attachmentCount' => $this->tableCount($legacy, 'ticket_attachments'),
                'logCount' => $this->tableCount($legacy, 'ticket_logs'),
                'companyIds' => $companyIds,
                'branchIds' => $branchIds,
                'customerIds' => $customerIds,
                'userIds' => $userIds,
                'tagIds' => $tagIds,
                'statuses' => $statuses,
                'importances' => $importances,
            ],
            'target' => $this->targetInventory(),
            'mappingSkeleton' => [
                'companies' => $this->emptyMap($companyIds),
                'branches' => $this->emptyMap($branchIds),
                'customers' => $this->emptyMap($customerIds),
                'users' => $this->emptyMap($userIds),
                'tags' => $this->emptyMap($tagIds),
                'statuses' => $this->emptyMap($statuses),
                'importances' => $this->emptyMap($importances),
            ],
        ];
    }

    private function targetInventory(): array
    {
        return [
            'companies' => Company::query()->get(['id', 'name', 'uses_branches'])->all(),
            'branches' => Branch::query()->get(['id', 'name', 'company_id'])->all(),
            'customers' => Customer::query()->get(['id', 'firstname', 'lastname', 'company_id', 'branch_id'])->all(),
            'users' => User::query()->get(['id', 'name', 'email', 'account_type', 'company_id', 'branch_id'])->all(),
            'tags' => parameterValue::query()->get(['id', 'parameter_value'])->all(),
        ];
    }

    private function sourceUserIds($legacy): array
    {
        $ids = collect($this->optionalDistinct($legacy, 'tickets', 'opened_by_user_id'))
            ->merge($this->optionalDistinct($legacy, 'tickets', 'assigned_to_user_id'));
        foreach (['tickets', 'ticket_attachments', 'ticket_logs'] as $table) {
            if (! Schema::connection(config('legacy_ticket_import.connection'))->hasTable($table)) {
                continue;
            }
            $ids = $ids->merge($this->distinct($legacy, $table, 'created_by'))
                ->merge($this->distinct($legacy, $table, 'updated_by'));
        }

        return $ids->unique()->sort()->values()->all();
    }

    private function distinct($legacy, string $table, string $column): array
    {
        return $legacy->table($table)->whereNotNull($column)->distinct()->orderBy($column)
            ->pluck($column)->map(fn ($id) => is_numeric($id) ? (int) $id : $id)->all();
    }

    private function optionalDistinct($legacy, string $table, string $column): array
    {
        $schema = Schema::connection(config('legacy_ticket_import.connection'));

        return $schema->hasColumn($table, $column) ? $this->distinct($legacy, $table, $column) : [];
    }

    private function tableCount($legacy, string $table): int
    {
        $schema = Schema::connection(config('legacy_ticket_import.connection'));

        return $schema->hasTable($table) ? $legacy->table($table)->count() : 0;
    }

    private function emptyMap(array $sourceValues): object
    {
        $mapping = new stdClass;
        foreach ($sourceValues as $sourceValue) {
            $mapping->{(string) $sourceValue} = null;
        }

        return $mapping;
    }
}
