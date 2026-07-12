<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Models\Company\Company;
use App\Models\Tiket\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'edit_ticket', 'guard_name' => 'api']);
        Permission::create(['name' => 'update_ticket', 'guard_name' => 'api']);
        Permission::create(['name' => 'delete_ticket', 'guard_name' => 'api']);

        $this->user([
            'id' => 1,
            'account_type' => AccountType::INTERNAL,
        ]);
    }

    public function test_direct_id_access_is_limited_to_the_tenant_company(): void
    {
        $tenantCompany = $this->company();
        $otherCompany = $this->company();
        $tenant = $this->tenantUser($tenantCompany);
        $tenant->givePermissionTo(['edit_ticket', 'update_ticket', 'delete_ticket']);

        $this->assertTrue(Gate::forUser($tenant)->allows('view', $this->ticketFor($tenantCompany)));
        $this->assertFalse(Gate::forUser($tenant)->allows('view', $this->ticketFor($otherCompany)));
        $this->assertFalse(Gate::forUser($tenant)->allows('update', $this->ticketFor($otherCompany)));
        $this->assertFalse(Gate::forUser($tenant)->allows('delete', $this->ticketFor($otherCompany)));
    }

    public function test_permissions_still_limit_actions_inside_the_tenant(): void
    {
        $tenantCompany = $this->company();
        $tenant = $this->tenantUser($tenantCompany);
        $ticket = $this->ticketFor($tenantCompany);

        $this->assertFalse(Gate::forUser($tenant)->allows('view', $ticket));
        $this->assertFalse(Gate::forUser($tenant)->allows('update', $ticket));
        $this->assertFalse(Gate::forUser($tenant)->allows('delete', $ticket));
    }

    public function test_permitted_internal_accounts_can_access_cross_company_tickets(): void
    {
        $internal = $this->user(['account_type' => AccountType::INTERNAL]);
        $internal->givePermissionTo('edit_ticket');

        $this->assertTrue(Gate::forUser($internal)->allows('view', $this->ticketFor($this->company())));
    }

    private function company(): Company
    {
        return Company::create([
            'name' => fake()->unique()->company(),
            'status' => CompanyStatus::ACTIVE->value,
        ]);
    }

    private function tenantUser(Company $company): User
    {
        return $this->user([
            'account_type' => AccountType::TENANT,
            'company_id' => $company->id,
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
            'description' => 'Policy fixture',
        ]);
    }
}
