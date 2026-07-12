<?php

namespace App\Services\Tenancy;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class UserClassificationMappingValidator
{
    public function errors(array $mappingRows): array
    {
        $references = $this->references();

        return array_merge(
            $this->duplicateErrors($mappingRows),
            $this->coverageErrors($mappingRows, $references->users->keys()->all()),
            $this->rowErrors($mappingRows, $references),
        );
    }

    private function references(): TenantMappingReferenceData
    {
        return new TenantMappingReferenceData(
            User::withTrashed()->get(['id', 'username', 'email'])->keyBy('id'),
            Company::withTrashed()->get(['id', 'status', 'uses_branches', 'deleted_at'])->keyBy('id'),
            Branch::withTrashed()->get(['id', 'company_id', 'status', 'deleted_at'])->keyBy('id'),
            Role::where('guard_name', 'api')->pluck('name')->all(),
        );
    }

    private function duplicateErrors(array $mappingRows): array
    {
        $userIds = array_column($mappingRows, 'user_id');
        $duplicateIds = array_keys(array_filter(array_count_values($userIds), fn (int $count) => $count > 1));

        return array_map(
            fn (string|int $userId) => "User {$userId} has duplicate or conflicting mapping rows.",
            $duplicateIds,
        );
    }

    private function coverageErrors(array $mappingRows, array $databaseUserIds): array
    {
        $mappedUserIds = array_map('intval', array_column($mappingRows, 'user_id'));
        $missingUserIds = array_diff($databaseUserIds, $mappedUserIds);
        $unknownUserIds = array_diff($mappedUserIds, $databaseUserIds);

        return array_merge(
            array_map(fn (int $userId) => "User {$userId} is missing from the mapping.", $missingUserIds),
            array_map(fn (int $userId) => "Mapping references unknown user {$userId}.", $unknownUserIds),
        );
    }

    private function rowErrors(array $mappingRows, TenantMappingReferenceData $references): array
    {
        return collect($mappingRows)->flatMap(fn (array $mappingRow) => array_merge(
            $this->identityErrors($mappingRow, $references->users->get((int) $mappingRow['user_id'])),
            $this->accountErrors($mappingRow),
            $this->companyErrors($mappingRow, $references->companies),
            $this->branchErrors($mappingRow, $references->branches, $references->companies),
            $this->roleErrors($mappingRow, $references->roleNames),
        ))->all();
    }

    private function identityErrors(array $mappingRow, ?User $user): array
    {
        if (! $user) {
            return [];
        }

        $identityErrors = [];

        foreach (['username', 'email'] as $identityField) {
            if ($mappingRow[$identityField] !== $user->{$identityField}) {
                $identityErrors[] = "Line {$mappingRow['line']} {$identityField} does not match user {$mappingRow['user_id']}.";
            }
        }

        return $identityErrors;
    }

    private function accountErrors(array $mappingRow): array
    {
        $accountTypes = array_column(AccountType::cases(), 'value');

        if (! in_array($mappingRow['account_type'], $accountTypes, true)) {
            return ["Line {$mappingRow['line']} has invalid account_type."];
        }

        if ($mappingRow['account_type'] === AccountType::INTERNAL->value) {
            return $this->internalScopeErrors($mappingRow);
        }

        return $mappingRow['company_id'] === ''
            ? ["Line {$mappingRow['line']} tenant account requires company_id."]
            : [];
    }

    private function internalScopeErrors(array $mappingRow): array
    {
        if ($mappingRow['company_id'] !== '' || $mappingRow['branch_id'] !== '') {
            return ["Line {$mappingRow['line']} internal account must not have company_id or branch_id."];
        }

        return [];
    }

    private function companyErrors(array $mappingRow, Collection $companies): array
    {
        if ($mappingRow['company_id'] === '') {
            return [];
        }

        $company = $companies->get((int) $mappingRow['company_id']);

        if (! $company) {
            return ["Line {$mappingRow['line']} references unknown company_id."];
        }

        if ($company->deleted_at || (int) $company->status !== CompanyStatus::ACTIVE->value) {
            return ["Line {$mappingRow['line']} references an inactive company."];
        }

        return [];
    }

    private function branchErrors(array $mappingRow, Collection $branches, Collection $companies): array
    {
        $company = $companies->get((int) $mappingRow['company_id']);

        if ($company && ! $company->uses_branches && $mappingRow['branch_id'] !== '') {
            return ["Line {$mappingRow['line']} branchless company cannot assign branch_id."];
        }

        $branchRequiredRoles = [TenantRole::BRANCH_MANAGER->value, TenantRole::EMPLOYEE->value];
        if ($company?->uses_branches
            && in_array($mappingRow['intended_role'], $branchRequiredRoles, true)
            && $mappingRow['branch_id'] === '') {
            return ["Line {$mappingRow['line']} role requires branch_id for this company."];
        }

        if ($mappingRow['branch_id'] === '') {
            return [];
        }

        $branch = $branches->get((int) $mappingRow['branch_id']);

        if (! $branch) {
            return ["Line {$mappingRow['line']} references unknown branch_id."];
        }

        return $this->assignedBranchErrors($mappingRow, $branch);
    }

    private function assignedBranchErrors(array $mappingRow, Branch $branch): array
    {
        if ((int) $branch->company_id !== (int) $mappingRow['company_id']) {
            return ["Line {$mappingRow['line']} branch does not belong to company."];
        }

        if ($branch->deleted_at || (int) $branch->status !== BranchStatus::ACTIVE->value) {
            return ["Line {$mappingRow['line']} references an inactive branch."];
        }

        return [];
    }

    private function roleErrors(array $mappingRow, array $roleNames): array
    {
        $roleErrors = [];
        $tenantRoleNames = array_column(TenantRole::cases(), 'value');
        $isTenantAccount = $mappingRow['account_type'] === AccountType::TENANT->value;
        $isTenantRole = in_array($mappingRow['intended_role'], $tenantRoleNames, true);

        if ($mappingRow['intended_role'] === '' || ! in_array($mappingRow['intended_role'], $roleNames, true)) {
            $roleErrors[] = "Line {$mappingRow['line']} references an invalid intended_role.";
        }

        if ($isTenantAccount && ! $isTenantRole) {
            $roleErrors[] = "Line {$mappingRow['line']} tenant account requires a tenant role.";
        }

        if ($mappingRow['account_type'] === AccountType::INTERNAL->value && $isTenantRole) {
            $roleErrors[] = "Line {$mappingRow['line']} internal account cannot use a tenant role.";
        }

        if ($mappingRow['mapping_authority_notes'] === '') {
            $roleErrors[] = "Line {$mappingRow['line']} requires mapping authority or notes.";
        }

        return $roleErrors;
    }
}
