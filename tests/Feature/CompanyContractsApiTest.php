<?php

namespace Tests\Feature;

use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\CompanyContract;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\User;
use App\Services\Company\CompanyContractService;
use Database\Seeders\Roles\CompanyContractPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyContractsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        Mail::fake();
        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'admin',
            'email' => 'admin@example.com', 'password' => 'password',
        ]);
        foreach ([
            'all_companies', 'create_company', 'edit_company', 'update_company', 'delete_company',
            'create_branch', 'edit_branch', 'update_branch', 'delete_branch',
            'all_company_contracts', 'create_company_contract', 'update_company_contract', 'delete_company_contract',
        ] as $permission) {
            $this->admin->givePermissionTo(Permission::findOrCreate($permission, 'api'));
        }
        $this->actingAs($this->admin, 'api');
    }

    public function test_company_creates_internal_branch_customer_and_optional_contract(): void
    {
        $response = $this->postJson('/api/v1/admin/companies/create', $this->companyPayload())
            ->assertOk()->assertJsonPath('message', 'Company created successfully.')
            ->assertJsonPath('data.customer.email', null)
            ->assertJsonPath('data.customer.status', 1)
            ->assertJsonPath('data.currentContract', null);
        $id = $response->json('data.companyId');
        $this->assertSame(['companyId', 'name', 'status', 'currentContract', 'customer'], array_keys($response->json('data')));
        $this->assertSame(['customerId', 'companyId', 'firstname', 'lastname', 'username', 'email', 'status'], array_keys($response->json('data.customer')));
        $this->assertDatabaseHas('companies', ['id' => $id, 'legacy_ticket_enabled' => 0]);
        $this->assertDatabaseHas('branches', ['company_id' => $id, 'name' => 'Arca Pro', 'status' => 1]);
        $this->assertDatabaseHas('customers', ['company_id' => $id, 'pin' => '0705', 'email' => null]);
        $identity = $this->postJson('/api/v1/public/tickets/identify', ['username' => 'mario.rossi', 'pin' => '0705'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'ticketToken']]);
        $this->postJson('/api/v1/public/tickets/create', [
            'ticketToken' => $identity->json('data.ticketToken'), 'importance' => 0, 'description' => 'No email required',
        ])->assertCreated();
        $this->assertDatabaseHas('tickets', ['company_id' => $id, 'description' => 'No email required']);

        $payload = $this->companyPayload('With Contract', 'second');
        $payload['customer']['email'] = 'mario@example.com';
        $payload['contract'] = ['startDate' => '2026-10-14', 'endDate' => '2026-11-12'];
        $created = $this->postJson('/api/v1/admin/companies/create', $payload)
            ->assertOk()->assertJsonPath('data.currentContract.status', 1)
            ->assertJsonPath('data.currentContract.endDate', '2026-11-12')
            ->assertJsonPath('data.customer.email', 'mario@example.com');
        $this->assertSame($created->json('data.companyId'), $created->json('data.currentContract.companyId'));
        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('company_contracts', 1);
    }

    public function test_company_validates_nested_fields_and_does_not_save_partial_data(): void
    {
        $this->postJson('/api/v1/admin/companies/create', ['name' => 'Invalid', 'status' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer']);
        foreach ([
            ['customer.email' => 'invalid'], ['customer.pin' => 705],
            ['contract' => []], ['contract' => ['startDate' => '2026-10-14']],
            ['contract' => ['startDate' => '2026-10-15', 'endDate' => '2026-11-01']],
            ['contract' => ['startDate' => '2026-10-14', 'endDate' => '2026-10-13']],
            ['customer.companyId' => 99],
        ] as $changes) {
            $payload = $this->companyPayload();
            foreach ($changes as $key => $value) {
                data_set($payload, $key, $value);
            }
            $this->postJson('/api/v1/admin/companies/create', $payload)->assertUnprocessable();
        }
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('branches', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('company_contracts', 0);
        $this->postJson('/api/v1/admin/companies/create', $this->companyPayload())->assertOk();
        $this->postJson('/api/v1/admin/companies/create', $this->companyPayload('Another'))
            ->assertUnprocessable()->assertJsonValidationErrors(['customer.username']);
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_company_accepts_explicit_null_email_and_ignores_internal_top_level_fields(): void
    {
        $payload = $this->companyPayload();
        $payload['customer']['email'] = null;
        $this->postJson('/api/v1/admin/companies/create', [
            ...$payload, 'branches' => [['name' => 'Injected']], 'legacyTicketEnabled' => true,
        ])->assertOk()->assertJsonPath('data.customer.email', null);
        $this->assertDatabaseHas('companies', ['name' => 'Arca Pro', 'legacy_ticket_enabled' => 0]);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseHas('branches', ['name' => 'Arca Pro']);
    }

    public function test_current_contract_prefers_active_over_newer_historical_record(): void
    {
        $company = $this->company();
        $active = $this->contract($company, '2026-10-01', '2026-10-31');
        $this->contract($company, '2026-09-01', '2026-09-30');
        $this->getJson("/api/v1/admin/companies/edit?companyId={$company->id}")
            ->assertOk()->assertJsonPath('currentContract.contractId', $active->id);
    }

    public function test_active_contract_can_be_updated_to_expired_and_then_renewed(): void
    {
        $company = $this->company();
        $active = $this->contract($company, '2026-10-01', '2026-10-31');
        $this->putJson('/api/v1/admin/company-contracts/update', [
            'companyId' => $company->id, 'contractId' => $active->id,
            'startDate' => '2026-10-01', 'endDate' => '2026-10-13',
        ])->assertOk()->assertJsonPath('data.status', 0);
        $this->deleteJson("/api/v1/admin/company-contracts/delete?companyId={$company->id}&contractId={$active->id}")->assertStatus(409);
        $this->postJson('/api/v1/admin/company-contracts/renew', ['companyId' => $company->id])
            ->assertOk()->assertJsonPath('data.endDate', '2026-10-26');
    }

    public function test_company_rolls_back_when_contract_creation_fails_after_other_inserts(): void
    {
        $this->mock(CompanyContractService::class, function ($mock) {
            $mock->shouldReceive('create')->once()->andThrow(new \RuntimeException('Simulated failure'));
        });
        $this->postJson('/api/v1/admin/companies/create', [
            ...$this->companyPayload(), 'contract' => ['startDate' => '2026-10-14', 'endDate' => '2026-11-12'],
        ])->assertStatus(500);
        foreach (['companies', 'branches', 'customers', 'company_contracts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_company_update_syncs_only_default_branch_and_preserves_legacy_data(): void
    {
        $company = $this->company(['legacy_ticket_enabled' => true]);
        $first = $company->branches()->create(['name' => 'Old-main', 'status' => 1]);
        $second = $company->branches()->create(['name' => 'Other', 'status' => 1]);
        $this->putJson('/api/v1/admin/companies/update', [
            'companyId' => $company->id, 'name' => 'Updated', 'status' => 0,
            'legacyTicketEnabled' => false, 'customer' => $this->companyPayload()['customer'],
            'branches' => [['name' => 'Injected']],
        ])->assertOk()->assertExactJson(['message' => 'Company updated successfully.']);
        $this->assertTrue((bool) $company->fresh()->legacy_ticket_enabled);
        $this->assertSame('Updated', $first->fresh()->name);
        $this->assertEquals(0, $first->fresh()->status);
        $this->assertSame('Other', $second->fresh()->name);
        $this->assertDatabaseCount('customers', 0);
        $first->delete();
        $second->delete();
        $this->putJson('/api/v1/admin/companies/update', [
            'companyId' => $company->id, 'name' => 'Recovered', 'status' => 1,
        ])->assertOk();
        $this->assertSame('Recovered', $company->branches()->first()->name);
    }

    public function test_company_index_and_edit_use_exact_keys_filters_and_pagination(): void
    {
        $company = $this->company();
        $old = $this->contract($company, '2026-09-01', '2026-09-30');
        $active = $this->contract($company, '2026-10-01', '2026-10-14');
        $this->company(['name' => 'Other', 'status' => 0]);
        $this->getJson('/api/v1/admin/companies?pageSize=1&filter[search]=Arca&filter[status]=1')
            ->assertOk()->assertExactJson([
                'result' => ['companies' => [[
                    'companyId' => $company->id, 'name' => 'Arca Pro', 'status' => 1,
                    'currentContract' => $this->contractJson($active, 1),
                ]]],
                'pagination' => ['total' => 1, 'count' => 1, 'perPage' => 1, 'currentPage' => 1, 'totalPages' => 1],
            ]);
        $this->getJson("/api/v1/admin/companies/edit?companyId={$company->id}")
            ->assertOk()->assertExactJson([
                'companyId' => $company->id, 'name' => 'Arca Pro', 'status' => 1,
                'currentContract' => $this->contractJson($active, 1),
            ]);
        $active->delete();
        $this->getJson("/api/v1/admin/companies/edit?companyId={$company->id}")
            ->assertOk()->assertJsonPath('currentContract.contractId', $old->id)
            ->assertJsonPath('currentContract.status', 0);
        $old->delete();
        $this->getJson("/api/v1/admin/companies/edit?companyId={$company->id}")
            ->assertOk()->assertJsonPath('currentContract', null);
        $this->getJson('/api/v1/admin/companies?pageSize=101')->assertUnprocessable();
        $this->getJson('/api/v1/admin/companies?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/admin/companies/edit?companyId=999')->assertNotFound();
    }

    public function test_edit_is_the_only_company_details_route_and_requires_its_permission(): void
    {
        $company = $this->company();
        $this->getJson("/api/v1/admin/companies/show?companyId={$company->id}")->assertNotFound();
        $this->getJson('/api/v1/admin/companies/edit')->assertUnprocessable()->assertJsonValidationErrors(['companyId']);
        $this->getJson('/api/v1/admin/companies/edit?companyId=invalid')->assertUnprocessable();

        $this->admin->syncPermissions(['all_companies', 'update_company']);
        $this->getJson("/api/v1/admin/companies/edit?companyId={$company->id}")->assertForbidden();
        $this->admin->syncPermissions(['edit_company']);
        $this->getJson("/api/v1/admin/companies/edit?companyId={$company->id}")
            ->assertOk()->assertExactJson([
                'companyId' => $company->id, 'name' => $company->name, 'status' => 1, 'currentContract' => null,
            ]);
        $this->putJson('/api/v1/admin/companies/update', [
            'companyId' => $company->id, 'name' => 'Not permitted', 'status' => 1,
        ])->assertForbidden();
        $this->assertSame($company->name, $company->fresh()->name);
    }

    public function test_contract_create_conflicts_update_and_delete_keep_history_without_deletion_keys(): void
    {
        $company = $this->company();
        $payload = ['companyId' => $company->id, 'startDate' => '2026-10-14', 'endDate' => '2026-10-14'];
        $created = $this->postJson('/api/v1/admin/company-contracts/create', $payload)
            ->assertOk()->assertJsonPath('data.status', 1);
        $id = $created->json('data.contractId');
        $this->postJson('/api/v1/admin/company-contracts/create', $payload)
            ->assertStatus(409)->assertJsonValidationErrors(['companyId']);
        $this->postJson('/api/v1/admin/company-contracts/renew', ['companyId' => $company->id])->assertStatus(409);
        $this->putJson('/api/v1/admin/company-contracts/update', [...$payload, 'contractId' => $id, 'endDate' => '2026-11-12'])
            ->assertOk()->assertJsonPath('data.contractId', $id)->assertJsonPath('data.endDate', '2026-11-12');
        $this->deleteJson("/api/v1/admin/company-contracts/delete?companyId={$company->id}&contractId=$id")
            ->assertOk()->assertExactJson(['message' => 'Contract deleted successfully.']);
        $this->assertSoftDeleted('company_contracts', ['id' => $id]);
        $expected = $this->contractJson(CompanyContract::withTrashed()->findOrFail($id), 1);
        $this->getJson("/api/v1/admin/company-contracts/show?companyId={$company->id}&contractId=$id")
            ->assertOk()->assertExactJson($expected);
        $this->getJson("/api/v1/admin/company-contracts?companyId={$company->id}")
            ->assertOk()->assertExactJson([
                'result' => ['contracts' => [$expected]],
                'pagination' => ['total' => 1, 'count' => 1, 'perPage' => 10, 'currentPage' => 1, 'totalPages' => 1],
            ]);
        $this->putJson('/api/v1/admin/company-contracts/update', [...$payload, 'contractId' => $id])->assertStatus(409);
        $this->deleteJson("/api/v1/admin/company-contracts/delete?companyId={$company->id}&contractId=$id")->assertStatus(409);
        $this->postJson('/api/v1/admin/company-contracts/renew', ['companyId' => $company->id])->assertStatus(409);
        $this->postJson('/api/v1/admin/company-contracts/create', $payload)->assertOk();
    }

    public function test_renewal_uses_latest_created_expired_contract_and_inclusive_calendar_days(): void
    {
        $company = $this->company();
        $this->contract($company, '2026-09-01', '2026-09-30');
        $previous = $this->contract($company, '2024-02-01', '2024-02-29');
        $this->contract($company, '2026-09-01', '2026-09-01')->delete();
        $this->getJson("/api/v1/admin/companies/edit?companyId={$company->id}")
            ->assertOk()->assertJsonPath('currentContract.contractId', $previous->id);
        $created = $this->postJson('/api/v1/admin/company-contracts/renew', ['companyId' => $company->id])
            ->assertOk()->assertJsonPath('data.startDate', '2026-10-14')
            ->assertJsonPath('data.endDate', '2026-11-11')->assertJsonPath('data.status', 1);
        $this->assertNotSame($previous->id, $created->json('data.contractId'));
        $this->assertSame('2024-02-29', $previous->fresh()->end_date->toDateString());
        $this->assertSame(['contractId', 'companyId', 'startDate', 'endDate', 'status'], array_keys($created->json('data')));
        $this->getJson("/api/v1/admin/company-contracts?companyId={$company->id}&pageSize=1&page=2")
            ->assertOk()->assertJsonCount(1, 'result.contracts')->assertJsonPath('pagination.total', 4)
            ->assertJsonPath('pagination.currentPage', 2)->assertJsonPath('pagination.totalPages', 4);
    }

    public function test_contract_expires_after_end_day_and_one_day_contract_renews_for_one_day(): void
    {
        $company = $this->company();
        $previous = $this->contract($company, '2026-10-14', '2026-10-14');
        $this->travelTo(Carbon::parse('2026-10-14 23:59:59'));
        $this->postJson('/api/v1/admin/company-contracts/renew', ['companyId' => $company->id])->assertStatus(409);
        $this->travelTo(Carbon::parse('2026-10-15 00:00:00'));
        $this->getJson("/api/v1/admin/company-contracts/show?companyId={$company->id}&contractId={$previous->id}")
            ->assertOk()->assertJsonPath('status', 0);
        $this->postJson('/api/v1/admin/company-contracts/renew', ['companyId' => $company->id])
            ->assertOk()->assertJsonPath('data.startDate', '2026-10-15')->assertJsonPath('data.endDate', '2026-10-15');
    }

    public function test_contract_validation_ownership_and_readonly_expired_history(): void
    {
        $company = $this->company();
        $other = $this->company(['name' => 'Other']);
        $expired = $this->contract($company, '2026-09-01', '2026-09-30');
        $payload = ['companyId' => $company->id, 'contractId' => $expired->id, 'startDate' => '2026-10-14', 'endDate' => '2026-11-14'];
        $this->putJson('/api/v1/admin/company-contracts/update', $payload)->assertStatus(409);
        $this->deleteJson("/api/v1/admin/company-contracts/delete?companyId={$company->id}&contractId={$expired->id}")->assertStatus(409);
        $this->getJson("/api/v1/admin/company-contracts/show?companyId={$other->id}&contractId={$expired->id}")->assertNotFound();
        $this->putJson('/api/v1/admin/company-contracts/update', [...$payload, 'companyId' => $other->id])->assertNotFound();
        $this->deleteJson("/api/v1/admin/company-contracts/delete?companyId={$other->id}&contractId={$expired->id}")->assertNotFound();
        $this->postJson('/api/v1/admin/company-contracts/renew', ['companyId' => $other->id])->assertStatus(409);
        foreach ([
            ['startDate' => '2026-10-15'], ['endDate' => '2026-10-13'],
            ['startDate' => '2026-02-30'], ['startDate' => '14/10/2026'],
        ] as $changes) {
            $this->postJson('/api/v1/admin/company-contracts/create', [...$payload, ...$changes])->assertUnprocessable();
        }
        $this->getJson('/api/v1/admin/company-contracts')->assertUnprocessable();
        $this->getJson("/api/v1/admin/company-contracts?companyId={$other->id}&pageSize=0")->assertUnprocessable();
        $this->getJson('/api/v1/admin/company-contracts?companyId=999')->assertNotFound();
        $this->postJson('/api/v1/admin/company-contracts/create', [...$payload, 'companyId' => 999])->assertNotFound();
    }

    public function test_branch_apis_use_branch_id_without_reassigning_company(): void
    {
        $company = $this->company();
        $other = $this->company(['name' => 'Other']);
        $this->postJson('/api/v1/admin/branches/create', ['companyId' => $company->id, 'name' => 'Internal', 'status' => 1])->assertOk();
        $branch = Branch::firstOrFail();
        $this->putJson('/api/v1/admin/branches/update', [
            'branchId' => $branch->id, 'name' => 'Renamed', 'status' => 0, 'companyId' => $other->id,
        ])->assertOk();
        $this->assertSame($company->id, $branch->fresh()->company_id);
        $this->getJson("/api/v1/admin/branches/edit?branchId={$branch->id}")->assertOk()->assertJsonPath('name', 'Renamed');
        $this->deleteJson("/api/v1/admin/branches/delete?branchId={$branch->id}")->assertOk();
        $this->assertSoftDeleted('branches', ['id' => $branch->id]);
        $this->getJson("/api/v1/admin/branches/edit?branchId={$branch->id}")->assertNotFound();
        $this->deleteJson('/api/v1/admin/branches/delete')->assertUnprocessable();
        $this->postJson('/api/v1/admin/branches/create', ['companyId' => 999, 'name' => 'Missing', 'status' => 1])->assertNotFound();
    }

    public function test_company_delete_preserves_related_records_but_blocks_mutations(): void
    {
        $this->postJson('/api/v1/admin/companies/create', $this->companyPayload())->assertOk();
        $company = Company::firstOrFail();
        $contract = $this->contract($company, '2026-10-14', '2026-11-14');
        $branch = $company->branches()->first();
        $ticket = Ticket::create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'customer_id' => Customer::first()->id, 'status' => 0, 'importance' => 0, 'description' => 'History',
        ]);
        $this->deleteJson("/api/v1/admin/companies/delete?companyId={$company->id}")->assertOk();
        $branch->delete();
        $this->assertSame($company->id, $ticket->fresh()->company->id);
        $this->assertSame($branch->id, $ticket->fresh()->branch->id);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('company_contracts', 1);
        $this->postJson('/api/v1/admin/company-contracts/renew', ['companyId' => $company->id])->assertNotFound();
        $this->getJson("/api/v1/admin/company-contracts/show?companyId={$company->id}&contractId={$contract->id}")->assertNotFound();
        $this->postJson('/api/v1/admin/branches/create', ['companyId' => $company->id, 'name' => 'Invalid', 'status' => 1])->assertNotFound();
        $this->postJson('/api/v1/public/tickets/identify', ['username' => 'mario.rossi', 'pin' => '0705'])->assertUnprocessable();
    }

    public function test_permissions_seeder_is_repeatable_and_only_grants_existing_manager(): void
    {
        $manager = Role::findOrCreate('مدير', 'api');
        $other = Role::findOrCreate('Operator', 'api');
        $this->seed(CompanyContractPermissionsSeeder::class);
        $this->seed(CompanyContractPermissionsSeeder::class);
        $this->assertCount(4, $manager->fresh()->permissions);
        $this->assertCount(0, $other->fresh()->permissions);
        $this->assertSame(4, Permission::where('name', 'like', '%company_contract%')->count());
    }

    public function test_routes_require_authentication_and_action_permissions(): void
    {
        $company = $this->company();
        $this->admin->syncPermissions([]);
        $this->getJson('/api/v1/admin/companies')->assertForbidden();
        $this->postJson('/api/v1/admin/companies/create', $this->companyPayload('New'))->assertForbidden();
        foreach ([
            ['GET', '/api/v1/admin/company-contracts'],
            ['GET', '/api/v1/admin/company-contracts/show'],
            ['POST', '/api/v1/admin/company-contracts/create'],
            ['PUT', '/api/v1/admin/company-contracts/update'],
            ['POST', '/api/v1/admin/company-contracts/renew'],
            ['DELETE', '/api/v1/admin/company-contracts/delete'],
        ] as [$method, $url]) {
            $this->json($method, $url, ['companyId' => $company->id])->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/companies')->assertUnauthorized();
        $this->getJson('/api/v1/admin/company-contracts?companyId=1')->assertUnauthorized();
    }

    public function test_company_index_eager_loads_contracts_without_per_company_queries(): void
    {
        $this->contract($this->company(), '2026-09-01', '2026-09-30');
        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/companies')->assertOk();
        $oneCompanyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 1; $i <= 4; $i++) {
            $this->contract($this->company(['name' => "Company $i"]), '2026-10-14', '2026-11-14');
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/companies')->assertOk()->assertJsonCount(5, 'result.companies');
        $this->assertLessThanOrEqual($oneCompanyQueries, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    private function companyPayload(string $name = 'Arca Pro', string $username = 'mario.rossi'): array
    {
        return [
            'name' => $name, 'status' => 1,
            'customer' => ['firstname' => 'Mario', 'lastname' => 'Rossi', 'username' => $username, 'pin' => '0705'],
        ];
    }

    private function company(array $attributes = []): Company
    {
        return Company::create([...['name' => 'Arca Pro', 'status' => 1], ...$attributes]);
    }

    private function contract(Company $company, string $start, string $end): CompanyContract
    {
        return $company->contracts()->create(['start_date' => $start, 'end_date' => $end]);
    }

    private function contractJson(CompanyContract $contract, int $status): array
    {
        return [
            'contractId' => $contract->id, 'companyId' => $contract->company_id,
            'startDate' => $contract->start_date->toDateString(), 'endDate' => $contract->end_date->toDateString(),
            'status' => $status,
        ];
    }
}
