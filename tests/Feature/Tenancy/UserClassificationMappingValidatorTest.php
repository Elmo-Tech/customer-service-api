<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\User;
use App\Services\Tenancy\UserClassificationMappingReader;
use App\Services\Tenancy\UserClassificationMappingValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserClassificationMappingValidatorTest extends TestCase
{
    use RefreshDatabase;

    private User $internalUser;

    private User $tenantUser;

    private Company $tenantCompany;

    private Branch $tenantBranch;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Internal Admin', 'guard_name' => 'api']);
        Role::create(['name' => TenantRole::EMPLOYEE->value, 'guard_name' => 'api']);
        $this->internalUser = $this->user(1, 'internal-user');
        $this->tenantCompany = Company::create([
            'name' => 'Tenant Company',
            'status' => CompanyStatus::ACTIVE->value,
            'uses_branches' => true,
        ]);
        $this->tenantBranch = $this->tenantCompany->branches()->create([
            'name' => 'Tenant Branch',
            'status' => BranchStatus::ACTIVE->value,
        ]);
        $this->tenantUser = $this->user(2, 'tenant-user');
    }

    public function test_valid_mapping_passes_without_writing_accounts(): void
    {
        $mappingPath = $this->writeMapping($this->validRows());

        $this->artisan('tenancy:validate-mapping', ['mapping' => $mappingPath])
            ->expectsOutputToContain('Dry-run completed without database writes')
            ->assertSuccessful();

        unlink($mappingPath);
        $this->assertNull($this->internalUser->fresh()->account_type);
        $this->assertNull($this->tenantUser->fresh()->company_id);
    }

    public function test_missing_users_are_rejected(): void
    {
        $errors = $this->errors([$this->validRows()[0]]);

        $this->assertContains('User 2 is missing from the mapping.', $errors);
    }

    public function test_unknown_and_duplicate_users_are_rejected(): void
    {
        $rows = $this->validRows();
        $rows[] = [...$rows[1], 'line' => 4];
        $rows[] = [...$rows[1], 'user_id' => '999', 'line' => 5];
        $errors = $this->errors($rows);

        $this->assertContains('User 2 has duplicate or conflicting mapping rows.', $errors);
        $this->assertContains('Mapping references unknown user 999.', $errors);
    }

    public function test_invalid_account_type_and_tenant_without_company_are_rejected(): void
    {
        $invalidTypeRows = $this->replaceTenant(['account_type' => 'partner']);
        $missingCompanyRows = $this->replaceTenant(['company_id' => '']);

        $this->assertContains('Line 3 has invalid account_type.', $this->errors($invalidTypeRows));
        $this->assertContains('Line 3 tenant account requires company_id.', $this->errors($missingCompanyRows));
    }

    public function test_internal_company_or_branch_assignments_are_rejected(): void
    {
        $rows = $this->validRows();
        $rows[0]['company_id'] = (string) $this->tenantCompany->id;
        $rows[0]['branch_id'] = (string) $this->tenantBranch->id;

        $this->assertContains(
            'Line 2 internal account must not have company_id or branch_id.',
            $this->errors($rows),
        );
    }

    public function test_unknown_and_inactive_companies_are_rejected(): void
    {
        $unknownCompanyRows = $this->replaceTenant(['company_id' => '999']);
        $this->tenantCompany->update(['status' => CompanyStatus::INACTIVE->value]);

        $this->assertContains('Line 3 references unknown company_id.', $this->errors($unknownCompanyRows));
        $this->assertContains('Line 3 references an inactive company.', $this->errors($this->validRows()));
    }

    public function test_cross_company_and_inactive_branches_are_rejected(): void
    {
        $otherCompany = Company::create(['name' => 'Other Company', 'status' => 1]);
        $otherBranch = $otherCompany->branches()->create(['name' => 'Other Branch', 'status' => 1]);
        $crossCompanyRows = $this->replaceTenant(['branch_id' => (string) $otherBranch->id]);
        $this->tenantBranch->update(['status' => BranchStatus::INACTIVE->value]);

        $this->assertContains('Line 3 branch does not belong to company.', $this->errors($crossCompanyRows));
        $this->assertContains('Line 3 references an inactive branch.', $this->errors($this->validRows()));
    }

    public function test_unknown_branches_are_rejected(): void
    {
        $rows = $this->replaceTenant(['branch_id' => '999']);

        $this->assertContains('Line 3 references unknown branch_id.', $this->errors($rows));
    }

    public function test_branch_assignment_matches_company_branch_mode_and_role(): void
    {
        $missingRequiredBranch = $this->replaceTenant(['branch_id' => '']);
        $missingRequiredBranchErrors = $this->errors($missingRequiredBranch);
        $this->tenantCompany->update(['uses_branches' => false]);
        $branchOnBranchlessCompany = $this->validRows();

        $this->assertContains(
            'Line 3 role requires branch_id for this company.',
            $missingRequiredBranchErrors,
        );
        $this->assertContains(
            'Line 3 branchless company cannot assign branch_id.',
            $this->errors($branchOnBranchlessCompany),
        );
    }

    public function test_invalid_roles_and_missing_mapping_authority_are_rejected(): void
    {
        $rows = $this->replaceTenant([
            'intended_role' => 'Unknown Role',
            'mapping_authority_notes' => '',
        ]);
        $errors = $this->errors($rows);

        $this->assertContains('Line 3 references an invalid intended_role.', $errors);
        $this->assertContains('Line 3 requires mapping authority or notes.', $errors);
    }

    public function test_roles_must_match_the_account_scope(): void
    {
        $tenantWithInternalRole = $this->replaceTenant(['intended_role' => 'Internal Admin']);
        $internalWithTenantRole = $this->validRows();
        $internalWithTenantRole[0]['intended_role'] = TenantRole::EMPLOYEE->value;

        $this->assertContains(
            'Line 3 tenant account requires a tenant role.',
            $this->errors($tenantWithInternalRole),
        );
        $this->assertContains(
            'Line 2 internal account cannot use a tenant role.',
            $this->errors($internalWithTenantRole),
        );
    }

    public function test_identity_mismatches_are_rejected(): void
    {
        $rows = $this->replaceTenant([
            'username' => 'wrong-user',
            'email' => 'wrong@example.com',
        ]);
        $errors = $this->errors($rows);

        $this->assertContains('Line 3 username does not match user 2.', $errors);
        $this->assertContains('Line 3 email does not match user 2.', $errors);
    }

    public function test_mapping_reader_rejects_invalid_headers_and_column_counts(): void
    {
        $invalidHeaderPath = $this->writeCsv([['wrong_header'], ['wrong_value']]);
        $invalidColumnsPath = $this->writeCsv([
            UserClassificationMappingReader::HEADERS,
            ['1', 'too-few-columns'],
        ]);
        $mappingReader = app(UserClassificationMappingReader::class);

        $this->assertReaderFailure($mappingReader, $invalidHeaderPath, 'headers');
        $this->assertReaderFailure($mappingReader, $invalidColumnsPath, 'column count');
    }

    private function errors(array $mappingRows): array
    {
        return app(UserClassificationMappingValidator::class)->errors($mappingRows);
    }

    private function validRows(): array
    {
        return [
            $this->mappingRow($this->internalUser, AccountType::INTERNAL),
            $this->mappingRow($this->tenantUser, AccountType::TENANT),
        ];
    }

    private function mappingRow(User $user, AccountType $accountType): array
    {
        $isTenant = $accountType === AccountType::TENANT;

        return [
            'user_id' => (string) $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'account_type' => $accountType->value,
            'company_id' => $isTenant ? (string) $this->tenantCompany->id : '',
            'branch_id' => $isTenant ? (string) $this->tenantBranch->id : '',
            'intended_role' => $isTenant ? TenantRole::EMPLOYEE->value : 'Internal Admin',
            'mapping_authority_notes' => 'Approved by system owner',
            'line' => $user->id + 1,
        ];
    }

    private function replaceTenant(array $replacements): array
    {
        $mappingRows = $this->validRows();
        $mappingRows[1] = array_merge($mappingRows[1], $replacements);

        return $mappingRows;
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
        $mappingPath = tempnam(sys_get_temp_dir(), 'tenant-mapping-');
        $mappingFile = fopen($mappingPath, 'w');
        fputcsv($mappingFile, UserClassificationMappingReader::HEADERS);

        foreach ($mappingRows as $mappingRow) {
            unset($mappingRow['line']);
            fputcsv($mappingFile, $mappingRow);
        }

        fclose($mappingFile);

        return $mappingPath;
    }

    private function writeCsv(array $csvRows): string
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'tenant-mapping-invalid-');
        $csvFile = fopen($csvPath, 'w');

        foreach ($csvRows as $csvRow) {
            fputcsv($csvFile, $csvRow);
        }

        fclose($csvFile);

        return $csvPath;
    }

    private function assertReaderFailure(
        UserClassificationMappingReader $mappingReader,
        string $mappingPath,
        string $expectedMessage
    ): void {
        try {
            $mappingReader->rows($mappingPath);
            $this->fail('Expected the mapping reader to reject malformed CSV.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        } finally {
            unlink($mappingPath);
        }
    }
}
