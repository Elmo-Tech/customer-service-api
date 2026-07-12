<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\Company\CustomerStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Enums\User\UserStatus;
use App\Mail\AccountInvitationMail;
use App\Models\Auth\AccountInvitation;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\User;
use App\Services\Auth\AccountInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResourceEndpointIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenantCompany;

    private Company $otherCompany;

    private Branch $tenantBranch;

    private Branch $otherCompanyBranch;

    private Customer $tenantCustomer;

    private Customer $otherCustomer;

    private User $owner;

    private User $otherTenantUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthorizationCatalog();
        $this->user('audit', AccountType::INTERNAL, ['id' => 1]);
        $this->tenantCompany = $this->company('Tenant A');
        $this->otherCompany = $this->company('Tenant B');
        $this->tenantBranch = $this->branch($this->tenantCompany, 'A1');
        $this->otherCompanyBranch = $this->branch($this->otherCompany, 'B1');
        $this->tenantCustomer = $this->customer($this->tenantCompany, $this->tenantBranch, 'Tenant');
        $this->otherCustomer = $this->customer($this->otherCompany, $this->otherCompanyBranch, 'Other');
        $this->owner = $this->tenantUser('owner', TenantRole::COMPANY_OWNER, $this->tenantCompany, $this->tenantBranch);
        $this->otherTenantUser = $this->tenantUser(
            'other-user',
            TenantRole::EMPLOYEE,
            $this->otherCompany,
            $this->otherCompanyBranch,
        );
        $this->owner->givePermissionTo(Permission::all());
    }

    public function test_company_customer_and_user_lists_ignore_cross_tenant_filters(): void
    {
        $companies = $this->actingAs($this->owner, 'api')->getJson('/api/v1/admin/companies')->assertOk();
        $customers = $this->actingAs($this->owner, 'api')->getJson(
            "/api/v1/admin/customers?filter[company]={$this->otherCompany->id}"
        )->assertOk();
        $users = $this->actingAs($this->owner, 'api')->getJson('/api/v1/admin/users')->assertOk();
        $alteredCompany = $this->actingAs($this->owner, 'api')->getJson(
            "/api/v1/admin/users?filter[company]={$this->otherCompany->id}",
        )->assertOk();
        $alteredBranch = $this->actingAs($this->owner, 'api')->getJson(
            "/api/v1/admin/users?filter[branch]={$this->otherCompanyBranch->id}",
        )->assertOk();

        $this->assertSame([$this->tenantCompany->id], collect($companies->json('result.companies'))->pluck('companyId')->all());
        $this->assertSame([], $customers->json('result.customers'));
        $this->assertNotContains(
            $this->otherTenantUser->id,
            collect($users->json('result.users'))->pluck('userId')->all(),
        );
        $this->assertSame([], $alteredCompany->json('result.users'));
        $this->assertSame([], $alteredBranch->json('result.users'));
        $ownerRow = collect($users->json('result.users'))->firstWhere('userId', $this->owner->id);
        $this->assertSame(TenantRole::COMPANY_OWNER->value, $ownerRow['roleName']);
        $this->assertSame($this->tenantCompany->name, $ownerRow['companyName']);
        $this->assertSame($this->tenantBranch->name, $ownerRow['branchName']);
    }

    public function test_cross_tenant_company_customer_branch_and_user_ids_fail_closed(): void
    {
        $this->actingAs($this->owner, 'api')
            ->getJson("/api/v1/admin/companies/edit?companyId={$this->otherCompany->id}")
            ->assertNotFound();
        $this->actingAs($this->owner, 'api')
            ->getJson("/api/v1/admin/customers/edit?customerId={$this->otherCustomer->id}")
            ->assertNotFound();
        $this->actingAs($this->owner, 'api')
            ->getJson("/api/v1/admin/branches/edit?branchId={$this->otherCompanyBranch->id}")
            ->assertNotFound();
        $this->actingAs($this->owner, 'api')
            ->getJson("/api/v1/admin/users/edit?userId={$this->otherTenantUser->id}")
            ->assertNotFound();
    }

    public function test_cross_tenant_updates_deletes_and_status_changes_fail_closed(): void
    {
        $this->actingAs($this->owner, 'api')
            ->putJson('/api/v1/admin/companies/update', $this->companyPayload($this->otherCompany))
            ->assertNotFound();
        $this->actingAs($this->owner, 'api')
            ->putJson('/api/v1/admin/customers/update', $this->customerPayload($this->otherCustomer))
            ->assertNotFound();
        $this->actingAs($this->owner, 'api')
            ->putJson('/api/v1/admin/branches/update', $this->branchPayload($this->otherCompanyBranch))
            ->assertNotFound();
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/v1/admin/customers/delete?customerId={$this->otherCustomer->id}")
            ->assertNotFound();
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/v1/admin/branches/delete?branchId={$this->otherCompanyBranch->id}")
            ->assertNotFound();
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/v1/admin/users/delete?userId={$this->otherTenantUser->id}")
            ->assertNotFound();
        $this->actingAs($this->owner, 'api')
            ->putJson('/api/v1/admin/users/changestatus', [
                'userId' => $this->otherTenantUser->id,
                'status' => UserStatus::INACTIVE->value,
            ])->assertNotFound();

        $this->assertNotNull($this->otherTenantUser->fresh());
    }

    public function test_branch_and_customer_company_ids_are_derived_or_immutable(): void
    {
        $this->actingAs($this->owner, 'api')->postJson('/api/v1/admin/branches/create', [
            'name' => 'Derived Branch',
            'status' => BranchStatus::ACTIVE->value,
            'companyId' => $this->otherCompany->id,
        ])->assertOk();
        $this->assertDatabaseHas('branches', [
            'name' => 'Derived Branch',
            'company_id' => $this->tenantCompany->id,
        ]);

        $this->actingAs($this->owner, 'api')->putJson('/api/v1/admin/customers/update', [
            ...$this->customerPayload($this->tenantCustomer),
            'companyId' => $this->otherCompany->id,
        ])->assertUnprocessable();
        $this->assertSame($this->tenantCompany->id, $this->tenantCustomer->fresh()->company_id);
    }

    public function test_tenant_role_assignment_cannot_escalate_to_platform_or_owner_roles(): void
    {
        $platformRole = Role::where('name', 'platform_admin')->firstOrFail();
        $ownerRole = Role::where('name', TenantRole::COMPANY_OWNER->value)->firstOrFail();

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/admin/users/create', $this->newUserPayload('platform-attempt', $platformRole))
            ->assertForbidden();
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/admin/users/create', $this->newUserPayload('owner-attempt', $ownerRole))
            ->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'platform-attempt']);
        $this->assertDatabaseMissing('users', ['username' => 'owner-attempt']);

        $roleOptions = $this->actingAs($this->owner, 'api')
            ->getJson('/api/v1/selects?allSelects=roles')
            ->assertOk()
            ->json('0.options');
        $roleNames = collect($roleOptions)->pluck('label')->all();
        $this->assertNotContains('platform_admin', $roleNames);
        $this->assertNotContains(TenantRole::COMPANY_OWNER->value, $roleNames);
    }

    public function test_branch_manager_can_only_create_employee_in_assigned_branch(): void
    {
        $manager = $this->tenantUser(
            'branch-manager',
            TenantRole::BRANCH_MANAGER,
            $this->tenantCompany,
            $this->tenantBranch,
        );
        $manager->givePermissionTo('create_user');
        $employeeRole = Role::where('name', TenantRole::EMPLOYEE->value)->firstOrFail();
        $companyManagerRole = Role::where('name', TenantRole::COMPANY_MANAGER->value)->firstOrFail();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/admin/users/create', [
                ...$this->newUserPayload('branch-employee', $employeeRole),
                'branchId' => $this->otherCompanyBranch->id,
            ])->assertOk();
        $this->assertDatabaseHas('users', [
            'username' => 'branch-employee',
            'company_id' => $this->tenantCompany->id,
            'branch_id' => $this->tenantBranch->id,
        ]);
        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/admin/users/create', $this->newUserPayload('manager-attempt', $companyManagerRole))
            ->assertForbidden();
    }

    public function test_internal_admin_can_add_tenant_team_member_after_onboarding(): void
    {
        $internal = User::findOrFail(1);
        $internal->givePermissionTo('create_user');
        $employeeRole = Role::where('name', TenantRole::EMPLOYEE->value)->firstOrFail();

        $this->actingAs($internal, 'api')->postJson('/api/v1/admin/users/create', [
            ...$this->newUserPayload('later-team-member', $employeeRole),
            'companyId' => $this->otherCompany->id,
            'branchId' => $this->otherCompanyBranch->id,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'username' => 'later-team-member',
            'account_type' => AccountType::TENANT->value,
            'company_id' => $this->otherCompany->id,
            'branch_id' => $this->otherCompanyBranch->id,
        ]);
    }

    public function test_team_creation_queues_one_time_invitation_without_accepting_a_password(): void
    {
        Mail::fake();
        $employeeRole = Role::where('name', TenantRole::EMPLOYEE->value)->firstOrFail();
        $payload = $this->newUserPayload('invited-team-member', $employeeRole);
        unset($payload['password'], $payload['status']);

        $response = $this->actingAs($this->owner, 'api')->postJson('/api/v1/admin/users/create', [
            ...$payload,
            'invite' => true,
        ])->assertOk()->assertJsonPath('invitationQueued', true);

        $user = User::where('username', 'invited-team-member')->firstOrFail();
        $this->assertSame(UserStatus::INACTIVE, $user->status);
        $this->assertSame(1, AccountInvitation::where('user_id', $user->id)->count());
        $this->assertStringNotContainsString('token', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
        $invitation = AccountInvitation::where('user_id', $user->id)->firstOrFail();
        $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/admin/account-invitations/{$invitation->id}/resend")
            ->assertOk()->assertJsonMissingPath('token');
        $this->assertSame(2, AccountInvitation::where('user_id', $user->id)->count());
        $latestInvitation = AccountInvitation::where('user_id', $user->id)->latest('id')->firstOrFail();
        $teamRow = collect($this->actingAs($this->owner, 'api')->getJson('/api/v1/admin/users')
            ->assertOk()->json('result.users'))->firstWhere('userId', $user->id);
        $this->assertSame($latestInvitation->id, $teamRow['pendingInvitationId']);
        Mail::assertQueued(AccountInvitationMail::class, 2);
    }

    public function test_internal_admin_cannot_move_existing_account_to_another_company(): void
    {
        $internal = User::findOrFail(1);
        $internal->givePermissionTo('update_user');
        $employeeRole = Role::where('name', TenantRole::EMPLOYEE->value)->firstOrFail();

        $this->actingAs($internal, 'api')->putJson('/api/v1/admin/users/update', [
            ...$this->newUserPayload($this->otherTenantUser->username, $employeeRole),
            'userId' => $this->otherTenantUser->id,
            'email' => $this->otherTenantUser->email,
            'companyId' => $this->tenantCompany->id,
            'branchId' => $this->tenantBranch->id,
        ])->assertUnprocessable();

        $this->assertSame($this->otherCompany->id, $this->otherTenantUser->fresh()->company_id);
    }

    public function test_tenant_cannot_resend_another_company_invitation(): void
    {
        $otherUser = $this->tenantUser(
            'other-invited-user', TenantRole::EMPLOYEE, $this->otherCompany, $this->otherCompanyBranch,
        );
        $otherUser->update(['status' => UserStatus::INACTIVE->value]);
        $secret = app(AccountInvitationService::class)->issue($otherUser, User::findOrFail(1));

        $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/admin/account-invitations/{$secret->invitationId}/resend")
            ->assertNotFound();
        $this->assertDatabaseHas('account_invitations', [
            'id' => $secret->invitationId, 'revoked_at' => null,
        ]);
    }

    public function test_internal_admin_cannot_assign_branch_from_another_company(): void
    {
        $internal = User::findOrFail(1);
        $internal->givePermissionTo('create_user');
        $employeeRole = Role::where('name', TenantRole::EMPLOYEE->value)->firstOrFail();

        $this->actingAs($internal, 'api')->postJson('/api/v1/admin/users/create', [
            ...$this->newUserPayload('cross-company-branch', $employeeRole),
            'companyId' => $this->otherCompany->id,
            'branchId' => $this->tenantBranch->id,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['username' => 'cross-company-branch']);
    }

    public function test_employee_cannot_administer_resources_even_with_injected_permissions(): void
    {
        $employee = $this->tenantUser(
            'employee-attacker',
            TenantRole::EMPLOYEE,
            $this->tenantCompany,
            $this->tenantBranch,
        );
        $employee->givePermissionTo(['create_user', 'create_branch', 'create_customer', 'update_company']);
        $employeeRole = Role::where('name', TenantRole::EMPLOYEE->value)->firstOrFail();

        $this->actingAs($employee, 'api')
            ->postJson('/api/v1/admin/users/create', $this->newUserPayload('forbidden-user', $employeeRole))
            ->assertForbidden();
        $this->actingAs($employee, 'api')->postJson('/api/v1/admin/branches/create', [
            'name' => 'Forbidden Branch', 'status' => 1, 'companyId' => $this->tenantCompany->id,
        ])->assertForbidden();
        $this->actingAs($employee, 'api')
            ->putJson('/api/v1/admin/companies/update', $this->companyPayload($this->tenantCompany))
            ->assertForbidden();
    }

    public function test_soft_deleted_rows_are_not_listed_or_addressable(): void
    {
        $deletedCustomer = $this->customer($this->tenantCompany, $this->tenantBranch, 'Deleted');
        $deletedUser = $this->tenantUser('deleted-user', TenantRole::EMPLOYEE, $this->tenantCompany, $this->tenantBranch);
        $deletedCustomer->delete();
        $deletedUser->delete();

        $customers = $this->actingAs($this->owner, 'api')->getJson('/api/v1/admin/customers')->assertOk();
        $users = $this->actingAs($this->owner, 'api')->getJson('/api/v1/admin/users')->assertOk();

        $this->assertNotContains($deletedCustomer->id, collect($customers->json('result.customers'))->pluck('customerId')->all());
        $this->assertNotContains($deletedUser->id, collect($users->json('result.users'))->pluck('userId')->all());
        $this->actingAs($this->owner, 'api')
            ->getJson("/api/v1/admin/users/edit?userId={$deletedUser->id}")
            ->assertNotFound();
    }

    public function test_branchless_owner_can_create_tenant_employee_without_branch(): void
    {
        $branchlessCompany = $this->company('Branchless', false);
        $owner = $this->tenantUser('branchless-owner', TenantRole::COMPANY_OWNER, $branchlessCompany, null);
        $owner->givePermissionTo('create_user');
        $employeeRole = Role::where('name', TenantRole::EMPLOYEE->value)->firstOrFail();

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/admin/users/create', [
                ...$this->newUserPayload('branchless-employee', $employeeRole),
                'branchId' => null,
            ])
            ->assertOk();
        $this->assertDatabaseHas('users', [
            'username' => 'branchless-employee',
            'account_type' => AccountType::TENANT->value,
            'company_id' => $branchlessCompany->id,
            'branch_id' => null,
        ]);
    }

    public function test_branchless_company_rejects_branch_assignments_and_branch_creation(): void
    {
        $branchlessCompany = $this->company('Branchless Boundaries', false);
        $owner = $this->tenantUser('branchless-boundary-owner', TenantRole::COMPANY_OWNER, $branchlessCompany, null);
        $owner->givePermissionTo(['create_user', 'create_branch', 'create_customer']);
        $employeeRole = Role::where('name', TenantRole::EMPLOYEE->value)->firstOrFail();

        $this->actingAs($owner, 'api')->postJson('/api/v1/admin/branches/create', [
            'name' => 'Rejected Branch',
            'status' => BranchStatus::ACTIVE->value,
            'companyId' => $branchlessCompany->id,
        ])->assertUnprocessable();
        $this->actingAs($owner, 'api')->postJson('/api/v1/admin/users/create', [
            ...$this->newUserPayload('branchless-assignment', $employeeRole),
            'branchId' => $this->tenantBranch->id,
        ])->assertUnprocessable();
        $this->actingAs($owner, 'api')->postJson('/api/v1/admin/customers/create', [
            'firstname' => 'Rejected',
            'lastname' => 'Customer',
            'pin' => '1234',
            'companyId' => $branchlessCompany->id,
            'branchId' => $this->tenantBranch->id,
            'status' => CustomerStatus::ACTIVE->value,
            'email' => 'rejected@example.com',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('branches', ['name' => 'Rejected Branch']);
        $this->assertDatabaseMissing('users', ['username' => 'branchless-assignment']);
        $this->assertDatabaseMissing('customers', ['email' => 'rejected@example.com']);
    }

    private function createAuthorizationCatalog(): void
    {
        foreach ($this->permissionNames() as $permissionName) {
            Permission::create(['name' => $permissionName, 'guard_name' => 'api']);
        }

        foreach ([...array_column(TenantRole::cases(), 'value'), 'platform_admin'] as $roleName) {
            Role::create(['name' => $roleName, 'guard_name' => 'api']);
        }
    }

    private function permissionNames(): array
    {
        return [
            'all_users', 'create_user', 'edit_user', 'update_user', 'delete_user', 'change_user_status',
            'all_companies', 'create_company', 'edit_company', 'update_company', 'delete_company',
            'create_branch', 'edit_branch', 'update_branch', 'delete_branch',
            'all_customers', 'create_customer', 'edit_customer', 'update_customer', 'delete_customer',
        ];
    }

    private function company(string $name, bool $usesBranches = true): Company
    {
        return Company::create([
            'name' => $name,
            'status' => CompanyStatus::ACTIVE->value,
            'uses_branches' => $usesBranches,
        ]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return $company->branches()->create(['name' => $name, 'status' => BranchStatus::ACTIVE->value]);
    }

    private function customer(Company $company, ?Branch $branch, string $name): Customer
    {
        return Customer::create([
            'firstname' => $name,
            'lastname' => 'Customer',
            'pin' => '1234',
            'company_id' => $company->id,
            'branch_id' => $branch?->id,
            'status' => CustomerStatus::ACTIVE->value,
            'email' => strtolower($name).'@example.com',
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
            'status' => UserStatus::ACTIVE->value,
            'account_type' => $accountType,
        ], $overrides));
    }

    private function customerPayload(Customer $customer): array
    {
        return [
            'customerId' => $customer->id,
            'firstname' => $customer->firstname,
            'lastname' => $customer->lastname,
            'pin' => $customer->pin,
            'companyId' => $customer->company_id,
            'branchId' => $customer->branch_id,
            'status' => $customer->status,
            'email' => $customer->email,
        ];
    }

    private function branchPayload(Branch $branch): array
    {
        return [
            'branchId' => $branch->id,
            'name' => $branch->name,
            'status' => $branch->status,
            'companyId' => $branch->company_id,
        ];
    }

    private function companyPayload(Company $company): array
    {
        return ['companyId' => $company->id, 'name' => $company->name, 'status' => $company->status];
    }

    private function newUserPayload(string $username, Role $role): array
    {
        return [
            'name' => $username,
            'username' => $username,
            'email' => "{$username}@example.com",
            'phone' => '',
            'address' => '',
            'status' => UserStatus::ACTIVE->value,
            'password' => "Codex!9{$username}Tenant#2026",
            'roleId' => $role->id,
            'branchId' => $this->tenantBranch->id,
        ];
    }
}
