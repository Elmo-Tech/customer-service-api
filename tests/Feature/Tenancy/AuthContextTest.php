<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Enums\User\UserStatus;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_requires_authentication_and_returns_server_tenant_context(): void
    {
        $internal = $this->user('internal', AccountType::INTERNAL, ['id' => 1]);
        $company = Company::create([
            'name' => 'Tenant Company',
            'status' => CompanyStatus::ACTIVE->value,
            'uses_branches' => false,
        ]);
        $tenant = $this->user('tenant', AccountType::TENANT, ['company_id' => $company->id]);

        $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->actingAs($internal, 'api')
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('accountType', AccountType::INTERNAL->value)
            ->assertJsonPath('tenant', null);
        $this->actingAs($tenant, 'api')
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('tenant.companyId', $company->id)
            ->assertJsonPath('tenant.usesBranches', false);
    }

    public function test_login_rejects_unclassified_disabled_and_inactive_tenant_accounts(): void
    {
        $this->user('audit', AccountType::INTERNAL, ['id' => 1]);
        $inactiveCompany = Company::create([
            'name' => 'Inactive Company',
            'status' => CompanyStatus::INACTIVE->value,
        ]);
        $unclassified = $this->user('unclassified', null);
        $disabled = $this->user('disabled', AccountType::INTERNAL, ['status' => UserStatus::INACTIVE->value]);
        $inactiveTenant = $this->user('inactive-tenant', AccountType::TENANT, [
            'company_id' => $inactiveCompany->id,
        ]);

        foreach ([$unclassified, $disabled, $inactiveTenant] as $invalidUser) {
            $this->postJson('/api/v1/admin/auth/login', [
                'username' => $invalidUser->username,
                'password' => 'Password1!',
            ])->assertUnauthorized()->assertJsonMissingPath('token');
        }
    }

    public function test_me_rejects_inactive_assigned_branch(): void
    {
        $this->user('audit', AccountType::INTERNAL, ['id' => 1]);
        $company = Company::create([
            'name' => 'Branch Company',
            'status' => CompanyStatus::ACTIVE->value,
            'uses_branches' => true,
        ]);
        $branch = Branch::create([
            'name' => 'Inactive Branch',
            'status' => BranchStatus::INACTIVE->value,
            'company_id' => $company->id,
        ]);
        $tenant = $this->user('inactive-branch-user', AccountType::TENANT, [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($tenant, 'api')->getJson('/api/v1/admin/auth/me')->assertForbidden();
    }

    public function test_every_authenticated_endpoint_rejects_disabled_account_context(): void
    {
        $disabled = $this->user('disabled-access-token', AccountType::INTERNAL, [
            'id' => 1,
            'status' => UserStatus::INACTIVE->value,
        ]);

        $this->actingAs($disabled, 'api')->getJson('/api/v1/selects?allSelects=companies')->assertForbidden();
    }

    private function user(string $username, ?AccountType $accountType, array $overrides = []): User
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
}
