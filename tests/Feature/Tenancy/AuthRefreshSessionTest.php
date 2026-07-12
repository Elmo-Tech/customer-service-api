<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\User\AccountType;
use App\Enums\User\UserStatus;
use App\Models\Auth\RefreshSession;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRefreshSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_short_access_token_and_protected_distinct_refresh_cookie(): void
    {
        $user = $this->user('login-user', AccountType::INTERNAL);
        $response = $this->login($user)->assertOk()->assertJsonMissingPath('refreshToken');
        $accessToken = $response->json('token');
        $cookie = $response->headers->getCookies()[0];

        $this->assertNotSame($accessToken, $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('none', $cookie->getSameSite());
        $this->assertSame('/api/v1/admin/auth', $cookie->getPath());
        $this->assertStringNotContainsString($cookie->getValue(), RefreshSession::firstOrFail()->secret_hash);
        $response->assertJsonMissingPath('secret_hash');
    }

    public function test_refresh_rotates_once_and_replay_is_rejected(): void
    {
        $this->withCredentials();
        $user = $this->user('rotate-user', AccountType::INTERNAL);
        $login = $this->login($user);
        $firstSecret = $login->headers->getCookies()[0]->getValue();
        [$selector, $secretPart] = explode('.', $firstSecret, 2);
        $storedSession = RefreshSession::firstOrFail();
        $this->assertSame($storedSession->selector, $selector);
        $this->assertSame($storedSession->secret_hash, hash('sha256', $secretPart));

        $refresh = $this->withUnencryptedCookie(config('auth_session.cookie_name'), $firstSecret)
            ->postJson('/api/v1/admin/auth/refresh')->assertOk();
        $nextSecret = $refresh->headers->getCookies()[0]->getValue();
        $this->assertNotSame($firstSecret, $nextSecret);
        $this->assertNotSame($login->json('token'), $refresh->json('token'));
        $this->assertNotNull(RefreshSession::query()->oldest('id')->firstOrFail()->revoked_at);

        $this->withUnencryptedCookie(config('auth_session.cookie_name'), $firstSecret)
            ->postJson('/api/v1/admin/auth/refresh')->assertUnauthorized();
        $this->withUnencryptedCookie(config('auth_session.cookie_name'), $nextSecret)
            ->postJson('/api/v1/admin/auth/refresh')->assertOk();
    }

    public function test_expired_revoked_malformed_and_logged_out_sessions_fail(): void
    {
        $this->withCredentials();
        $user = $this->user('invalid-session-user', AccountType::INTERNAL);
        $login = $this->login($user);
        $secret = $login->headers->getCookies()[0]->getValue();
        RefreshSession::firstOrFail()->update(['expires_at' => now()->subMinute()]);
        $this->withUnencryptedCookie(config('auth_session.cookie_name'), $secret)
            ->postJson('/api/v1/admin/auth/refresh')->assertUnauthorized();
        $this->withUnencryptedCookie(config('auth_session.cookie_name'), 'malformed')
            ->postJson('/api/v1/admin/auth/refresh')->assertUnauthorized();

        $login = $this->login($user);
        $secret = $login->headers->getCookies()[0]->getValue();
        [$selector] = explode('.', $secret, 2);
        $logout = $this->withUnencryptedCookie(config('auth_session.cookie_name'), $secret)
            ->postJson('/api/v1/admin/auth/logout')->assertOk();
        $clearedCookie = $logout->headers->getCookies()[0];
        $this->assertTrue($clearedCookie->isSecure());
        $this->assertTrue($clearedCookie->isHttpOnly());
        $this->assertLessThan(time(), $clearedCookie->getExpiresTime());
        $this->assertNotNull(RefreshSession::query()->where('selector', $selector)->firstOrFail()->revoked_at);
        $this->withUnencryptedCookie(config('auth_session.cookie_name'), $secret)
            ->postJson('/api/v1/admin/auth/refresh')->assertUnauthorized();
    }

    public function test_browser_refresh_rejects_untrusted_origin(): void
    {
        $this->withCredentials();
        $user = $this->user('origin-user', AccountType::INTERNAL);
        $login = $this->login($user);
        $secret = $login->headers->getCookies()[0]->getValue();

        $this->withHeader('Origin', 'https://attacker.example')
            ->withUnencryptedCookie(config('auth_session.cookie_name'), $secret)
            ->postJson('/api/v1/admin/auth/refresh')->assertForbidden();
        $this->withHeader('Origin', config('cors.allowed_origins.0'))
            ->withUnencryptedCookie(config('auth_session.cookie_name'), $secret)
            ->postJson('/api/v1/admin/auth/refresh')->assertOk();
    }

    public function test_logout_invalidates_presented_access_token(): void
    {
        $this->withCredentials();
        $user = $this->user('access-logout-user', AccountType::INTERNAL);
        $login = $this->login($user);
        $secret = $login->headers->getCookies()[0]->getValue();
        $accessToken = $login->json('token');

        $this->withToken($accessToken)->withUnencryptedCookie(config('auth_session.cookie_name'), $secret)
            ->postJson('/api/v1/admin/auth/logout')->assertOk();
        $this->withToken($accessToken)->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    }

    public function test_refresh_rechecks_user_company_and_branch_state(): void
    {
        $this->withCredentials();
        $this->user('audit', AccountType::INTERNAL, ['id' => 1]);
        $company = Company::create(['name' => 'Tenant', 'status' => CompanyStatus::ACTIVE->value, 'uses_branches' => true]);
        $branch = Branch::create(['name' => 'Branch', 'status' => BranchStatus::ACTIVE->value, 'company_id' => $company->id]);
        $user = $this->user('tenant-user', AccountType::TENANT, [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        foreach (['user', 'company', 'branch'] as $inactiveResource) {
            $login = $this->login($user);
            $secret = $login->headers->getCookies()[0]->getValue();
            [$selector] = explode('.', $secret, 2);
            $this->deactivate($inactiveResource, $user, $company, $branch);
            $this->withUnencryptedCookie(config('auth_session.cookie_name'), $secret)
                ->postJson('/api/v1/admin/auth/refresh')->assertUnauthorized();
            $this->assertNotNull(RefreshSession::query()->where('selector', $selector)->firstOrFail()->revoked_at);
            $this->reactivate($user, $company, $branch);
        }

        $unclassified = $this->user('unclassified-refresh', null);
        $session = RefreshSession::create($this->sessionAttributes($unclassified));
        $this->withUnencryptedCookie(config('auth_session.cookie_name'), $session->selector.'.'.str_repeat('a', 64))
            ->postJson('/api/v1/admin/auth/refresh')->assertUnauthorized();
    }

    private function login(User $user)
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'username' => $user->username,
            'password' => 'Password1!',
        ]);
    }

    private function deactivate(string $resource, User $user, Company $company, Branch $branch): void
    {
        match ($resource) {
            'user' => $user->update(['status' => UserStatus::INACTIVE->value]),
            'company' => $company->update(['status' => CompanyStatus::INACTIVE->value]),
            'branch' => $branch->update(['status' => BranchStatus::INACTIVE->value]),
        };
    }

    private function reactivate(User $user, Company $company, Branch $branch): void
    {
        $user->update(['status' => UserStatus::ACTIVE->value]);
        $company->update(['status' => CompanyStatus::ACTIVE->value]);
        $branch->update(['status' => BranchStatus::ACTIVE->value]);
    }

    private function sessionAttributes(User $user): array
    {
        return [
            'user_id' => $user->id,
            'selector' => str_repeat('b', 32),
            'secret_hash' => hash('sha256', str_repeat('a', 64)),
            'expires_at' => now()->addHour(),
        ];
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
