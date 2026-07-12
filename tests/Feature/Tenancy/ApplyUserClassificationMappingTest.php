<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Models\Company\Company;
use App\Models\User;
use App\Services\Tenancy\UserClassificationMappingReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplyUserClassificationMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_change_users(): void
    {
        [$mappingPath, $internalUser] = $this->mappingFixture();

        $this->artisan('tenancy:apply-mapping', ['mapping' => $mappingPath])
            ->expectsOutputToContain('Dry-run completed without database writes')
            ->assertSuccessful();

        $this->assertNull($internalUser->fresh()->account_type);
        $this->assertDatabaseCount('tenant_audit_events', 0);
        unlink($mappingPath);
    }

    public function test_confirmed_execution_applies_all_rows_and_roles_atomically(): void
    {
        [$mappingPath, $internalUser, $tenantUser, $company] = $this->mappingFixture();

        $this->artisan('tenancy:apply-mapping', [
            'mapping' => $mappingPath,
            '--execute' => true,
        ])->expectsConfirmation('Apply this mapping to every existing user?', 'yes')
            ->expectsOutputToContain('Applied authoritative tenancy mapping to 2 users')
            ->assertSuccessful();

        $this->assertSame(AccountType::INTERNAL, $internalUser->fresh()->account_type);
        $this->assertTrue($internalUser->fresh()->hasRole('Internal Admin'));
        $this->assertSame(AccountType::TENANT, $tenantUser->fresh()->account_type);
        $this->assertSame($company->id, $tenantUser->fresh()->company_id);
        $this->assertTrue($tenantUser->fresh()->hasRole(TenantRole::COMPANY_OWNER->value));
        $this->assertDatabaseCount('tenant_audit_events', 2);
        unlink($mappingPath);
    }

    public function test_validation_failure_applies_no_rows(): void
    {
        [$mappingPath, $internalUser, $tenantUser] = $this->mappingFixture(false);

        $this->artisan('tenancy:apply-mapping', [
            'mapping' => $mappingPath,
            '--execute' => true,
        ])->expectsOutputToContain('No rows were applied')
            ->assertFailed();

        $this->assertNull($internalUser->fresh()->account_type);
        $this->assertNull($tenantUser->fresh()->account_type);
        $this->assertDatabaseCount('tenant_audit_events', 0);
        unlink($mappingPath);
    }

    private function mappingFixture(bool $valid = true): array
    {
        Role::create(['name' => 'Internal Admin', 'guard_name' => 'api']);
        Role::create(['name' => TenantRole::COMPANY_OWNER->value, 'guard_name' => 'api']);
        $internalUser = $this->user(1, 'internal');
        $company = Company::create([
            'name' => 'Tenant',
            'status' => CompanyStatus::ACTIVE->value,
            'uses_branches' => false,
        ]);
        $tenantUser = $this->user(2, 'tenant');
        $mappingRows = [
            $this->row($internalUser, AccountType::INTERNAL, '', 'Internal Admin'),
            $this->row(
                $tenantUser,
                AccountType::TENANT,
                (string) $company->id,
                $valid ? TenantRole::COMPANY_OWNER->value : 'Internal Admin',
            ),
        ];

        return [$this->writeMapping($mappingRows), $internalUser, $tenantUser, $company];
    }

    private function row(User $user, AccountType $accountType, string $companyId, string $role): array
    {
        return [
            (string) $user->id,
            $user->username,
            $user->email,
            $accountType->value,
            $companyId,
            '',
            $role,
            'Approved by system owner',
        ];
    }

    private function user(int $id, string $username): User
    {
        return User::create([
            'id' => $id,
            'name' => $username,
            'username' => $username,
            'email' => "{$username}@example.com",
            'password' => 'Password1!',
        ]);
    }

    private function writeMapping(array $mappingRows): string
    {
        $mappingPath = tempnam(sys_get_temp_dir(), 'tenant-apply-');
        $mappingFile = fopen($mappingPath, 'w');
        fputcsv($mappingFile, UserClassificationMappingReader::HEADERS);

        foreach ($mappingRows as $mappingRow) {
            fputcsv($mappingFile, $mappingRow);
        }

        fclose($mappingFile);

        return $mappingPath;
    }
}
