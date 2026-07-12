<?php

namespace Tests\Feature\Tenancy;

use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Enums\User\UserStatus;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResourceExportIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_resource_exports_are_scoped_filtered_and_formula_safe(): void
    {
        User::create([
            'id' => 1, 'name' => 'Audit', 'username' => 'audit', 'email' => 'audit@example.com',
            'password' => 'Password9!', 'status' => 1, 'account_type' => AccountType::INTERNAL,
        ]);
        foreach (['all_users', 'all_customers', 'all_companies', 'export_users', 'export_customers', 'export_companies'] as $permission) {
            Permission::create(['name' => $permission, 'guard_name' => 'api']);
        }
        Role::create(['name' => TenantRole::COMPANY_OWNER->value, 'guard_name' => 'api']);
        $company = Company::create(['name' => '=Tenant Formula', 'status' => 1, 'uses_branches' => false]);
        $other = Company::create(['name' => 'Other Hidden', 'status' => 1, 'uses_branches' => false]);
        $owner = $this->tenantUser($company, 'owner');
        $owner->givePermissionTo(['all_users', 'all_customers', 'all_companies']);
        $this->tenantUser($company, '+Visible User');
        $this->tenantUser($other, 'Hidden User');
        Customer::create(['firstname' => '@Visible', 'lastname' => 'Customer', 'company_id' => $company->id, 'status' => 1, 'pin' => '1234']);
        Customer::create(['firstname' => 'Hidden', 'lastname' => 'Customer', 'company_id' => $other->id, 'status' => 1, 'pin' => '1234']);

        $users = $this->actingAs($owner, 'api')->get('/api/v1/admin/users/export')->assertOk()->streamedContent();
        $customers = $this->actingAs($owner, 'api')->get('/api/v1/admin/customers/export')->assertOk()->streamedContent();
        $companies = $this->actingAs($owner, 'api')->get('/api/v1/admin/companies/export')->assertOk()->streamedContent();

        $this->assertStringContainsString("'+Visible User", $users);
        $this->assertStringNotContainsString('Hidden User', $users);
        $this->assertStringContainsString("'@Visible Customer", $customers);
        $this->assertStringNotContainsString('Hidden Customer', $customers);
        $this->assertStringContainsString("'=Tenant Formula", $companies);
        $this->assertStringNotContainsString('Other Hidden', $companies);
    }

    public function test_resource_exports_require_authentication_and_permission(): void
    {
        Permission::create(['name' => 'all_users', 'guard_name' => 'api']);
        Permission::create(['name' => 'export_users', 'guard_name' => 'api']);
        $this->getJson('/api/v1/admin/users/export')->assertUnauthorized();
        $internal = User::create([
            'name' => 'Internal', 'username' => 'internal', 'email' => 'internal@example.com',
            'password' => 'Password9!', 'status' => 1, 'account_type' => AccountType::INTERNAL,
        ]);
        $this->actingAs($internal, 'api')->getJson('/api/v1/admin/users/export')->assertForbidden();
    }

    private function tenantUser(Company $company, string $name): User
    {
        $username = strtolower(str_replace([' ', '+'], '-', $name));
        $user = User::create([
            'name' => $name, 'username' => $username, 'email' => "{$username}@example.com",
            'password' => 'Password9!', 'status' => UserStatus::ACTIVE->value,
            'account_type' => AccountType::TENANT, 'company_id' => $company->id,
        ]);
        $user->assignRole(TenantRole::COMPANY_OWNER->value);

        return $user;
    }
}
