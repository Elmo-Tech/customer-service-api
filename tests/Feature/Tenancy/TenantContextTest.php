<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Models\Company\Company;
use App\Models\Tiket\Ticket;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'id' => 1,
            'name' => 'Audit User',
            'username' => 'audit-user',
            'email' => 'audit@example.com',
            'password' => 'Password1!',
            'account_type' => AccountType::INTERNAL,
        ]);
    }

    public function test_tenant_queries_only_return_their_company_rows(): void
    {
        [$tenant, $tenantCompany, $otherCompany] = $this->tenantFixture();
        $tenantTicket = $this->ticketFor($tenantCompany);
        $this->ticketFor($otherCompany);

        $tickets = (new TenantContext($tenant))->scopeTenantRows(Ticket::query())->get();

        $this->assertSame([$tenantTicket->id], $tickets->pluck('id')->all());
    }

    public function test_internal_queries_can_return_all_company_rows(): void
    {
        [, $tenantCompany, $otherCompany] = $this->tenantFixture();
        $this->ticketFor($tenantCompany);
        $this->ticketFor($otherCompany);
        $internalUser = $this->user(['account_type' => AccountType::INTERNAL]);

        $tickets = (new TenantContext($internalUser))->scopeTenantRows(Ticket::query())->get();

        $this->assertCount(2, $tickets);
    }

    public function test_unclassified_accounts_fail_closed(): void
    {
        $unclassifiedUser = $this->user();

        $this->expectException(AuthorizationException::class);

        (new TenantContext($unclassifiedUser))->scopeTenantRows(Ticket::query());
    }

    public function test_tenant_accounts_without_an_active_company_fail_closed(): void
    {
        $inactiveCompany = $this->company(CompanyStatus::INACTIVE);
        $tenant = $this->user([
            'account_type' => AccountType::TENANT,
            'company_id' => $inactiveCompany->id,
        ]);

        $this->expectException(AuthorizationException::class);

        (new TenantContext($tenant))->scopeTenantRows(Ticket::query());
    }

    public function test_branchless_company_rows_remain_tenant_scoped(): void
    {
        $branchlessCompany = $this->company(CompanyStatus::ACTIVE, false);
        $tenant = $this->user([
            'account_type' => AccountType::TENANT,
            'company_id' => $branchlessCompany->id,
        ]);
        $branchlessTicket = $this->ticketFor($branchlessCompany);

        $tickets = (new TenantContext($tenant))->scopeTenantRows(Ticket::query())->get();

        $this->assertNull($branchlessTicket->branch_id);
        $this->assertSame([$branchlessTicket->id], $tickets->pluck('id')->all());
    }

    private function tenantFixture(): array
    {
        $tenantCompany = $this->company();
        $otherCompany = $this->company();
        $tenant = $this->user([
            'account_type' => AccountType::TENANT,
            'company_id' => $tenantCompany->id,
        ]);

        return [$tenant, $tenantCompany, $otherCompany];
    }

    private function company(
        CompanyStatus $status = CompanyStatus::ACTIVE,
        bool $usesBranches = true
    ): Company {
        return Company::create([
            'name' => fake()->unique()->company(),
            'status' => $status->value,
            'uses_branches' => $usesBranches,
        ]);
    }

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Password1!',
        ], $overrides));
    }

    private function ticketFor(Company $company): Ticket
    {
        return Ticket::create([
            'company_id' => $company->id,
            'status' => 0,
            'importance' => 0,
            'description' => 'Isolation fixture',
        ]);
    }
}
