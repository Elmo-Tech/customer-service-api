<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\Tiket\Ticket;
use App\Models\User;
use App\Notifications\TicketSlaEscalated;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketReportingIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $internal;

    private Company $company;

    private Company $otherCompany;

    private Branch $branch;

    private Branch $otherBranch;

    private Customer $customer;

    private Customer $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorizationCatalog();
        $this->internal = $this->user('internal', AccountType::INTERNAL, ['id' => 1]);
        $this->company = $this->company('Company A');
        $this->otherCompany = $this->company('Company B');
        $this->branch = $this->branch($this->company, 'A1');
        $this->otherBranch = $this->branch($this->otherCompany, 'B1');
        $this->customer = $this->customer($this->company, 'Customer A');
        $this->otherCustomer = $this->customer($this->otherCompany, 'Customer B');
    }

    public function test_dashboard_matches_authorized_lists_while_employee_reporting_stays_blocked(): void
    {
        $owner = $this->tenantUser('owner', TenantRole::COMPANY_OWNER, $this->company, $this->branch);
        $branchManager = $this->tenantUser('branch-manager', TenantRole::BRANCH_MANAGER, $this->company, $this->branch);
        $employee = $this->tenantUser('employee', TenantRole::EMPLOYEE, $this->company, $this->branch);
        $otherEmployee = $this->tenantUser('other-employee', TenantRole::EMPLOYEE, $this->company, $this->branch);
        $otherTenantEmployee = $this->tenantUser(
            'tenant-b-employee',
            TenantRole::EMPLOYEE,
            $this->otherCompany,
            $this->otherBranch,
        );
        $this->ticket($this->company, $this->branch, $this->customer, [
            'opened_by_user_id' => $employee->id,
        ]);
        $this->ticket($this->company, $this->branch, $this->customer, [
            'opened_by_user_id' => $otherEmployee->id,
        ]);
        $this->ticket($this->otherCompany, $this->otherBranch, $this->otherCustomer, [
            'opened_by_user_id' => $otherTenantEmployee->id,
        ]);

        foreach ([$this->internal, $owner, $branchManager] as $user) {
            $user->givePermissionTo(['all_tickets', 'export_tickets', 'view_ticket_dashboard']);
            $listCount = $this->listCount($user);
            $dashboardTotal = $this->dashboard($user)->json('data.kpis.total');
            $this->assertSame($listCount, $dashboardTotal);
        }

        $employee->givePermissionTo('all_tickets');
        $this->assertSame(1, $this->listCount($employee));
        $this->actingAs($employee, 'api')->getJson('/api/v1/admin/tickets/dashboard')->assertForbidden();
        $this->actingAs($employee, 'api')->get('/api/v1/admin/tickets/export')->assertForbidden();
    }

    public function test_altered_company_and_branch_filters_never_widen_dashboard_or_export(): void
    {
        $owner = $this->tenantUser('filter-owner', TenantRole::COMPANY_OWNER, $this->company, $this->branch);
        $manager = $this->tenantUser('filter-manager', TenantRole::BRANCH_MANAGER, $this->company, $this->branch);
        $owner->givePermissionTo(['all_tickets', 'export_tickets', 'view_ticket_dashboard']);
        $manager->givePermissionTo(['all_tickets', 'export_tickets', 'view_ticket_dashboard']);
        $tenantTicket = $this->ticket($this->company, $this->branch, $this->customer);
        $otherTicket = $this->ticket($this->otherCompany, $this->otherBranch, $this->otherCustomer);

        $this->dashboard($owner, ['company' => $this->otherCompany->id])
            ->assertJsonPath('data.kpis.total', 0);
        $this->dashboard($manager, ['branch' => $this->otherBranch->id])
            ->assertJsonPath('data.kpis.total', 0);
        $csv = $this->export($owner)->streamedContent();
        $this->assertStringContainsString($tenantTicket->ticket_number, $csv);
        $this->assertStringNotContainsString($otherTicket->ticket_number, $csv);
        $alteredCsv = $this->export($owner, ['company' => $this->otherCompany->id])->streamedContent();
        $this->assertStringNotContainsString($otherTicket->ticket_number, $alteredCsv);
        $alteredBranchCsv = $this->export($manager, ['branch' => $this->otherBranch->id])->streamedContent();
        $this->assertStringNotContainsString($otherTicket->ticket_number, $alteredBranchCsv);
    }

    public function test_export_respects_filters_and_neutralizes_formula_cells(): void
    {
        $this->internal->givePermissionTo('export_tickets');
        $included = $this->ticket($this->company, $this->branch, $this->customer, [
            'description' => '=HYPERLINK("https://example.test")',
            'importance' => TicketImportanceStatus::RED->value,
        ]);
        $excluded = $this->ticket($this->otherCompany, $this->otherBranch, $this->otherCustomer);

        $csv = $this->export($this->internal, ['company' => $this->company->id, 'importance' => 1])
            ->streamedContent();

        $this->assertStringContainsString($included->ticket_number, $csv);
        $this->assertStringNotContainsString($excluded->ticket_number, $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('customer_id', $csv);
    }

    public function test_dashboard_handles_empty_one_row_boundaries_reopened_and_null_closure(): void
    {
        $this->internal->givePermissionTo('view_ticket_dashboard');
        $empty = $this->dashboard($this->internal)->assertOk();
        $empty->assertJsonPath('data.kpis.total', 0)->assertJsonPath('data.kpis.averageResolutionHours', null);

        $createdAt = Carbon::parse('2026-07-01 08:00:00');
        $closedAt = Carbon::parse('2026-07-01 13:00:00');
        $closed = $this->ticket($this->company, $this->branch, $this->customer, [
            'status' => TicketStatus::DONE->value,
            'created_at' => $createdAt,
            'updated_at' => $closedAt,
            'closed_at' => $closedAt,
            'real_closed_at' => $closedAt,
        ]);
        $closed->timestamps = false;
        $closed->forceFill(['created_at' => $createdAt, 'updated_at' => $closedAt])->saveQuietly();
        $this->ticket($this->company, $this->branch, $this->customer, [
            'status' => TicketStatus::REOPENED->value,
            'real_closed_at' => null,
        ]);
        $deleted = $this->ticket($this->company, $this->branch, $this->customer);
        $deleted->delete();

        $dashboard = $this->dashboard($this->internal, ['fromDate' => '2026-07-01', 'toDate' => '2026-07-01']);
        $dashboard->assertJsonPath('data.kpis.total', 1)
            ->assertJsonPath('data.kpis.closed', 1)
            ->assertJsonPath('data.kpis.averageResolutionHours', 5);
        $this->assertSame($closed->id, $dashboard->json('data.recentActivity.0.ticketId'));
        $dashboard->assertJsonPath('data.series.createdVsClosed.0.created', 1)
            ->assertJsonPath('data.series.createdVsClosed.0.closed', 1);

        $openDashboard = $this->dashboard($this->internal, ['status' => TicketStatus::OPENED->value]);
        $openDashboard->assertJsonPath('data.kpis.total', 1)->assertJsonPath('data.kpis.reopened', 1);
    }

    public function test_branchless_dashboard_has_no_fake_branch_series(): void
    {
        $company = $this->company('Branchless', false);
        $customer = $this->customer($company, 'Branchless Customer');
        $owner = $this->tenantUser('branchless-owner', TenantRole::COMPANY_OWNER, $company, null);
        $owner->givePermissionTo(['all_tickets', 'view_ticket_dashboard']);
        $this->ticket($company, null, $customer, ['opened_by_user_id' => $owner->id]);

        $this->dashboard($owner)->assertJsonPath('data.kpis.total', 1)
            ->assertJsonPath('data.series.branchVolume', []);
    }

    public function test_sla_metrics_and_escalation_are_scoped_and_idempotent(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-07-12 12:00:00');
        $owner = $this->tenantUser('sla-owner', TenantRole::COMPANY_OWNER, $this->company, $this->branch);
        $otherOwner = $this->tenantUser('sla-other-owner', TenantRole::COMPANY_OWNER, $this->otherCompany, $this->otherBranch);
        $owner->givePermissionTo('view_ticket_dashboard');
        $overdue = $this->ticket($this->company, $this->branch, $this->customer, ['due_at' => now()->subHour()]);
        $this->ticket($this->company, $this->branch, $this->customer, ['due_at' => now()->addHours(12)]);
        $otherOverdue = $this->ticket(
            $this->otherCompany,
            $this->otherBranch,
            $this->otherCustomer,
            ['due_at' => now()->subHour()],
        );

        $this->dashboard($owner)->assertJsonPath('data.kpis.overdue', 1)
            ->assertJsonPath('data.kpis.dueSoon', 1)
            ->assertJsonCount(2, 'data.slaAlerts');
        $this->artisan('tickets:escalate-overdue')->assertSuccessful();
        $this->artisan('tickets:escalate-overdue')->assertSuccessful();

        $this->assertNotNull($overdue->fresh()->escalated_at);
        Notification::assertSentTo($owner, TicketSlaEscalated::class, fn ($notification) => str_contains($notification->toMail()->subject, $overdue->ticket_number)
        );
        Notification::assertSentTo($otherOwner, TicketSlaEscalated::class, fn ($notification) => str_contains($notification->toMail()->subject, $otherOverdue->ticket_number)
        );
        Notification::assertSentToTimes($owner, TicketSlaEscalated::class, 1);
        Notification::assertSentToTimes($otherOwner, TicketSlaEscalated::class, 1);
        Carbon::setTestNow();
    }

    public function test_reporting_requires_authentication_and_compatible_permissions(): void
    {
        $owner = $this->tenantUser('denied-owner', TenantRole::COMPANY_OWNER, $this->company, $this->branch);

        $this->getJson('/api/v1/admin/tickets/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/admin/tickets/export')->assertUnauthorized();
        $this->actingAs($owner, 'api')->getJson('/api/v1/admin/tickets/dashboard')->assertForbidden();
        $this->actingAs($owner, 'api')->getJson('/api/v1/admin/tickets/export')->assertForbidden();
    }

    public function test_streamed_export_query_count_is_bounded(): void
    {
        $this->internal->givePermissionTo('export_tickets');
        foreach (range(1, 5) as $index) {
            $this->ticket($this->company, $this->branch, $this->customer, ['description' => "Ticket {$index}"]);
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->export($this->internal)->streamedContent();

        $this->assertLessThanOrEqual(15, $queries);
    }

    private function dashboard(User $user, array $filters = [])
    {
        return $this->actingAs($user, 'api')->getJson('/api/v1/admin/tickets/dashboard?'.http_build_query([
            'filter' => $filters,
        ]))->assertOk();
    }

    private function export(User $user, array $filters = [])
    {
        return $this->actingAs($user, 'api')->get('/api/v1/admin/tickets/export?'.http_build_query([
            'filter' => $filters,
        ]))->assertOk();
    }

    private function listCount(User $user): int
    {
        return count($this->actingAs($user, 'api')->getJson('/api/v1/admin/tickets')->assertOk()
            ->json('result.tickets'));
    }

    private function authorizationCatalog(): void
    {
        foreach (['all_tickets', 'export_tickets', 'view_ticket_dashboard'] as $permission) {
            Permission::create(['name' => $permission, 'guard_name' => 'api']);
        }
        foreach (TenantRole::cases() as $role) {
            Role::create(['name' => $role->value, 'guard_name' => 'api']);
        }
    }

    private function company(string $name, bool $usesBranches = true): Company
    {
        return Company::create(['name' => $name, 'status' => CompanyStatus::ACTIVE->value, 'uses_branches' => $usesBranches]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return $company->branches()->create(['name' => $name, 'status' => BranchStatus::ACTIVE->value]);
    }

    private function customer(Company $company, string $name): Customer
    {
        return Customer::create([
            'firstname' => $name,
            'lastname' => 'Customer',
            'pin' => '1234',
            'company_id' => $company->id,
            'status' => 1,
        ]);
    }

    private function tenantUser(string $username, TenantRole $role, Company $company, ?Branch $branch): User
    {
        $user = $this->user($username, AccountType::TENANT, [
            'company_id' => $company->id,
            'branch_id' => $branch?->id,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    private function user(string $username, AccountType $accountType, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => $username,
            'username' => $username,
            'email' => "{$username}@example.com",
            'password' => 'Password1!',
            'status' => 1,
            'account_type' => $accountType,
        ], $overrides));
    }

    private function ticket(
        Company $company,
        ?Branch $branch,
        Customer $customer,
        array $overrides = [],
    ): Ticket {
        return Ticket::create(array_merge([
            'company_id' => $company->id,
            'branch_id' => $branch?->id,
            'customer_id' => $customer->id,
            'opened_by_user_id' => null,
            'status' => TicketStatus::OPENED->value,
            'importance' => TicketImportanceStatus::GREEN->value,
            'description' => 'Reporting ticket',
        ], $overrides));
    }
}
