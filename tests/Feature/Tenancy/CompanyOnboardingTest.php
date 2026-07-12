<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Enums\User\UserStatus;
use App\Mail\AccountInvitationMail;
use App\Models\Auth\AccountInvitation;
use App\Models\Company\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $internal;

    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Permission::create(['name' => 'onboard_company', 'guard_name' => 'api']);
        $this->roles = collect(TenantRole::cases())->mapWithKeys(fn (TenantRole $role) => [
            $role->value => Role::create(['name' => $role->value, 'guard_name' => 'api']),
        ])->all();
        $this->roles['platform'] = Role::create(['name' => 'platform_admin', 'guard_name' => 'api']);
        $this->internal = $this->user('internal', AccountType::INTERNAL);
        $this->internal->givePermissionTo('onboard_company');
    }

    public function test_branch_enabled_onboarding_is_atomic_and_queues_invitations(): void
    {
        $response = $this->actingAs($this->internal, 'api')->postJson(
            '/api/v1/admin/companies/onboard',
            $this->payload(true),
        )->assertCreated()->assertJsonMissingPath('token')->assertJsonPath('invitationCount', 2);

        $company = Company::where('name', 'Acme')->firstOrFail();
        $this->assertSame(2, $company->branches()->count());
        $this->assertSame(2, $company->users()->count());
        $this->assertDatabaseCount('account_invitations', 2);
        $this->assertDatabaseCount('tenant_audit_events', 7);
        $this->assertStringNotContainsString('secret_hash', $response->getContent());
        $auditOutput = json_encode(\App\Models\Tenancy\TenantAuditEvent::all()->toArray());
        $this->assertStringNotContainsString('secret', $auditOutput);
        $this->assertStringNotContainsString('password', $auditOutput);
        Mail::assertQueued(AccountInvitationMail::class, 2);
    }

    public function test_branchless_onboarding_succeeds_and_rejects_branch_assignments_atomically(): void
    {
        $payload = $this->payload(false);
        $payload['branches'] = [];
        $payload['accounts'] = [];
        $this->actingAs($this->internal, 'api')->postJson('/api/v1/admin/companies/onboard', $payload)
            ->assertCreated();
        $this->assertDatabaseHas('users', ['username' => 'owner', 'branch_id' => null]);

        $invalid = $this->payload(false);
        $this->actingAs($this->internal, 'api')->postJson('/api/v1/admin/companies/onboard', $invalid)
            ->assertUnprocessable();
        $this->assertDatabaseMissing('companies', ['name' => 'Acme Invalid']);
    }

    public function test_invalid_role_and_duplicate_identity_roll_back_every_onboarding_row(): void
    {
        $platform = $this->payload(true, 'Platform Attempt');
        $platform['owner']['roleId'] = $this->roles['platform']->id;
        $this->actingAs($this->internal, 'api')->postJson('/api/v1/admin/companies/onboard', $platform)
            ->assertForbidden();

        $duplicate = $this->payload(true, 'Duplicate Attempt');
        $duplicate['accounts'][0]['email'] = $duplicate['owner']['email'];
        $this->actingAs($this->internal, 'api')->postJson('/api/v1/admin/companies/onboard', $duplicate)
            ->assertUnprocessable();
        $this->assertDatabaseMissing('companies', ['name' => 'Platform Attempt']);
        $this->assertDatabaseMissing('companies', ['name' => 'Duplicate Attempt']);
    }

    public function test_tenant_and_internal_without_permission_cannot_onboard(): void
    {
        $tenantCompany = Company::create(['name' => 'Tenant', 'status' => 1, 'uses_branches' => false]);
        $tenant = $this->user('tenant', AccountType::TENANT, $tenantCompany);
        $tenant->assignRole(TenantRole::COMPANY_OWNER->value);
        $this->actingAs($tenant, 'api')->postJson('/api/v1/admin/companies/onboard', $this->payload(true))
            ->assertForbidden();

        $internal = $this->user('no-permission', AccountType::INTERNAL);
        $this->actingAs($internal, 'api')->postJson('/api/v1/admin/companies/onboard', $this->payload(true))
            ->assertForbidden();
    }

    public function test_invitation_setup_is_one_time_and_fails_for_expiry_revocation_and_disabled_company(): void
    {
        $token = $this->onboardAndInvitationToken();
        $employeeToken = $this->queuedToken(1);
        $this->postJson('/api/v1/account-invitations/setup', $this->passwordPayload($token))->assertOk();
        $this->postJson('/api/v1/account-invitations/setup', $this->passwordPayload($token))->assertForbidden();

        $expired = AccountInvitation::whereNull('consumed_at')->firstOrFail();
        $expired->update(['purpose' => 'other']);
        $this->postJson('/api/v1/account-invitations/setup', $this->passwordPayload($employeeToken))
            ->assertForbidden();
        $expired->update(['purpose' => 'password_setup']);
        $expired->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/account-invitations/setup', $this->passwordPayload($employeeToken))
            ->assertForbidden();

        $expired->update(['expires_at' => now()->addHour(), 'revoked_at' => now()]);
        $this->postJson('/api/v1/account-invitations/setup', $this->passwordPayload($employeeToken))
            ->assertForbidden();

        $expired->update(['revoked_at' => null]);
        $expired->user->branch->update(['status' => 0]);
        $this->postJson('/api/v1/account-invitations/setup', $this->passwordPayload($employeeToken))
            ->assertForbidden();
        $expired->user->branch->update(['status' => 1]);
        $expired->user->company->update(['status' => CompanyStatus::INACTIVE->value]);
        $this->postJson('/api/v1/account-invitations/setup', $this->passwordPayload($employeeToken))
            ->assertForbidden();
    }

    public function test_resend_revokes_predecessor_and_revoke_blocks_latest_invitation(): void
    {
        $oldToken = $this->onboardAndInvitationToken();
        $oldInvitation = AccountInvitation::query()->firstOrFail();
        $this->actingAs($this->internal, 'api')
            ->postJson("/api/v1/admin/account-invitations/{$oldInvitation->id}/resend")
            ->assertOk()->assertJsonMissingPath('token');
        $this->postJson('/api/v1/account-invitations/setup', $this->passwordPayload($oldToken))->assertForbidden();

        $latest = AccountInvitation::query()->where('user_id', $oldInvitation->user_id)->latest('id')->firstOrFail();
        $latestToken = $this->queuedToken(-1);
        $this->actingAs($this->internal, 'api')
            ->postJson("/api/v1/admin/account-invitations/{$latest->id}/revoke")
            ->assertOk();
        $this->postJson('/api/v1/account-invitations/setup', $this->passwordPayload($latestToken))->assertForbidden();
    }

    private function onboardAndInvitationToken(): string
    {
        $this->actingAs($this->internal, 'api')->postJson('/api/v1/admin/companies/onboard', $this->payload(true))
            ->assertCreated();

        return $this->queuedToken(0);
    }

    private function queuedToken(int $index): string
    {
        $queued = Mail::queued(AccountInvitationMail::class)->values();
        $mail = $index < 0 ? $queued->get($queued->count() + $index) : $queued->get($index);
        parse_str(parse_url($mail->setupUrl, PHP_URL_QUERY), $query);

        return $query['token'];
    }

    private function passwordPayload(string $token): array
    {
        return ['token' => $token, 'password' => 'NewPassword9!', 'password_confirmation' => 'NewPassword9!'];
    }

    private function payload(bool $usesBranches, string $name = 'Acme'): array
    {
        return [
            'company' => ['name' => $name, 'status' => 1, 'usesBranches' => $usesBranches],
            'branches' => [['key' => 'hq', 'name' => 'HQ'], ['key' => 'east', 'name' => 'East']],
            'owner' => ['name' => 'Owner', 'username' => 'owner', 'email' => 'owner@example.com', 'roleId' => $this->roles[TenantRole::COMPANY_OWNER->value]->id],
            'accounts' => [['name' => 'Employee', 'username' => 'employee', 'email' => 'employee@example.com', 'roleId' => $this->roles[TenantRole::EMPLOYEE->value]->id, 'branchKey' => 'hq']],
        ];
    }

    private function user(string $username, AccountType $type, ?Company $company = null): User
    {
        return User::create([
            'name' => $username, 'username' => $username, 'email' => "{$username}@example.com",
            'password' => 'Password9!', 'status' => UserStatus::ACTIVE->value,
            'account_type' => $type, 'company_id' => $company?->id,
        ]);
    }
}
