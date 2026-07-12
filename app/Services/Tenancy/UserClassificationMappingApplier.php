<?php

namespace App\Services\Tenancy;

use App\Enums\User\AccountType;
use App\Models\Tenancy\TenantAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;

class UserClassificationMappingApplier
{
    public function apply(array $mappingRows): int
    {
        return DB::transaction(function () use ($mappingRows): int {
            $users = User::withTrashed()
                ->whereIn('id', array_column($mappingRows, 'user_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($mappingRows as $mappingRow) {
                $user = $users->get((int) $mappingRow['user_id']);
                $this->assertIdentityUnchanged($user, $mappingRow);
                $this->applyRow($user, $mappingRow);
            }

            return count($mappingRows);
        });
    }

    private function assertIdentityUnchanged(?User $user, array $mappingRow): void
    {
        if (! $user || $user->username !== $mappingRow['username'] || $user->email !== $mappingRow['email']) {
            throw new RuntimeException('User identities changed after mapping validation. No rows were applied.');
        }
    }

    private function applyRow(User $user, array $mappingRow): void
    {
        $user->update([
            'account_type' => AccountType::from($mappingRow['account_type']),
            'company_id' => $this->nullableId($mappingRow['company_id']),
            'branch_id' => $this->nullableId($mappingRow['branch_id']),
        ]);

        $role = Role::query()
            ->where('guard_name', 'api')
            ->where('name', $mappingRow['intended_role'])
            ->firstOrFail();
        $user->syncRoles([$role]);

        TenantAuditEvent::create([
            'event' => 'user.classified',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'metadata' => [
                'account_type' => $mappingRow['account_type'],
                'company_id' => $this->nullableId($mappingRow['company_id']),
                'branch_id' => $this->nullableId($mappingRow['branch_id']),
                'role' => $mappingRow['intended_role'],
                'authority' => $mappingRow['mapping_authority_notes'],
            ],
        ]);
    }

    private function nullableId(string $identifier): ?int
    {
        return $identifier === '' ? null : (int) $identifier;
    }
}
