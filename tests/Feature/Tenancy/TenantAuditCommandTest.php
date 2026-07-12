<?php

namespace Tests\Feature\Tenancy;

use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\User;
use App\Services\Tenancy\TenantAuditReport;
use App\Services\Tenancy\UserClassificationMappingReader;
use App\Services\Tenancy\UserClassificationMappingValidator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PDOException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_exports_mapping_clues_without_credentials_or_tokens(): void
    {
        $user = $this->user();
        $role = Role::create(['name' => 'Audit Role', 'guard_name' => 'api']);
        $user->assignRole($role);
        $company = Company::create(['name' => 'Audit Company']);
        $branch = $company->branches()->create(['name' => 'Audit Branch']);
        $customer = Customer::create([
            'firstname' => 'Secret',
            'lastname' => 'Customer',
            'pin' => 'never-export-this-pin',
            'company_id' => $company->id,
            'email' => 'customer@example.com',
        ]);
        $ticket = Ticket::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'status' => 0,
            'importance' => 0,
            'description' => 'Audit fixture',
        ]);
        $ticket->token = 'never-export-this-review-token';
        $ticket->save();
        $reportPath = tempnam(sys_get_temp_dir(), 'tenant-audit-');

        $this->artisan('tenancy:audit', ['--output' => $reportPath])->assertSuccessful();

        $reportJson = file_get_contents($reportPath);
        $report = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);
        unlink($reportPath);

        $this->assertSame($user->id, $report['users'][0]['user_id']);
        $this->assertSame(['Audit Role'], $report['users'][0]['roles']);
        $this->assertSame($customer->id, $report['customer_clues'][0]['customer_id']);
        $this->assertStringNotContainsString($user->password, $reportJson);
        $this->assertStringNotContainsString('never-export-this-pin', $reportJson);
        $this->assertStringNotContainsString('never-export-this-review-token', $reportJson);
        $this->assertStringNotContainsString('password', $reportJson);
        $this->assertStringNotContainsString('pin', $reportJson);
        $this->assertStringNotContainsString('token', $reportJson);
    }

    public function test_audit_reports_connection_failures_without_sensitive_details(): void
    {
        $auditReport = Mockery::mock(TenantAuditReport::class);
        $auditReport->shouldReceive('contents')->andThrow($this->queryException(2002));
        $this->app->instance(TenantAuditReport::class, $auditReport);
        $reportPath = tempnam(sys_get_temp_dir(), 'tenant-audit-failure-');

        $auditCommand = $this->artisan('tenancy:audit', ['--output' => $reportPath]);
        $this->assertSanitizedConnectionFailure($auditCommand);
        unlink($reportPath);
    }

    public function test_mapping_validation_reports_connection_failures_without_sensitive_details(): void
    {
        $this->bindFailingMappingServices();
        $mappingCommand = $this->artisan('tenancy:validate-mapping', ['mapping' => __FILE__]);
        $this->assertSanitizedConnectionFailure($mappingCommand);
    }

    public function test_non_connectivity_query_failures_are_not_misreported(): void
    {
        $auditReport = Mockery::mock(TenantAuditReport::class);
        $auditReport->shouldReceive('contents')->andThrow($this->queryException(1064));
        $this->app->instance(TenantAuditReport::class, $auditReport);
        $reportPath = tempnam(sys_get_temp_dir(), 'tenant-audit-query-failure-');

        $this->expectException(QueryException::class);

        try {
            $this->artisan('tenancy:audit', ['--output' => $reportPath])->run();
        } finally {
            unlink($reportPath);
        }
    }

    public function test_runbook_contains_exact_connected_environment_setup_paths(): void
    {
        $runbook = file_get_contents(base_path('docs/multi-tenancy/user-classification-runbook.md'));

        $this->assertStringContainsString('mkdir -p storage/app/tenancy', $runbook);
        $this->assertStringContainsString(
            'cp docs/multi-tenancy/user-classification-mapping.csv storage/app/tenancy/user-classification-mapping.csv',
            $runbook,
        );
        $this->assertStringContainsString(
            'php artisan tenancy:validate-mapping storage/app/tenancy/user-classification-mapping.csv',
            $runbook,
        );
        $this->assertStringContainsString('No new database', $runbook);
        $this->assertStringContainsString('Both commands require connectivity', $runbook);
    }

    private function user(): User
    {
        return User::create([
            'id' => 1,
            'name' => 'Audit User',
            'username' => 'audit-user',
            'email' => 'audit@example.com',
            'password' => 'NeverExportThisPassword1!',
        ]);
    }

    private function queryException(int $driverCode): QueryException
    {
        $connectionException = new PDOException('private-host private-schema secret SQL', $driverCode);
        $connectionException->errorInfo = ['HY000', $driverCode, 'private-host private-schema'];

        return new QueryException(
            'private-connection',
            'select secret_column from private_table',
            [],
            $connectionException,
        );
    }

    private function bindFailingMappingServices(): void
    {
        $mappingReader = Mockery::mock(UserClassificationMappingReader::class);
        $mappingReader->shouldReceive('rows')->andReturn([]);
        $mappingValidator = Mockery::mock(UserClassificationMappingValidator::class);
        $mappingValidator->shouldReceive('errors')->andThrow($this->queryException(2002));
        $this->app->instance(UserClassificationMappingReader::class, $mappingReader);
        $this->app->instance(UserClassificationMappingValidator::class, $mappingValidator);
    }

    private function assertSanitizedConnectionFailure($pendingCommand): void
    {
        $pendingCommand
            ->expectsOutputToContain('Database connection unavailable')
            ->doesntExpectOutputToContain('private-host')
            ->doesntExpectOutputToContain('private-schema')
            ->doesntExpectOutputToContain('select secret_column')
            ->assertFailed();
    }
}
