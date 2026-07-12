<?php

namespace App\Services\Auth;

use App\DTOs\InvitationSecret;
use App\Enums\Company\BranchStatus;
use App\Enums\Company\CompanyStatus;
use App\Enums\User\UserStatus;
use App\Models\Auth\AccountInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountInvitationService
{
    public function issue(User $user, User $actor): InvitationSecret
    {
        if ($user->status !== UserStatus::INACTIVE) {
            throw ValidationException::withMessages(['invitation' => 'Only inactive accounts can be invited.']);
        }

        return DB::transaction(fn () => $this->issueRecord($user, $actor));
    }

    private function issueRecord(User $user, User $actor): InvitationSecret
    {
        $this->revokePending($user);
        $selector = bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(32));
        $invitation = AccountInvitation::create([
            'user_id' => $user->id,
            'selector' => $selector,
            'secret_hash' => hash('sha256', $secret),
            'expires_at' => now()->addMinutes(config('account_invitations.ttl_minutes')),
            'created_by_user_id' => $actor->id,
        ]);

        return new InvitationSecret($invitation->id, "{$selector}.{$secret}", $user->email);
    }

    public function consume(string $token, string $password): void
    {
        [$selector, $secret] = $this->tokenParts($token);
        DB::transaction(function () use ($selector, $secret, $password): void {
            $invitation = AccountInvitation::query()->where('selector', $selector)->lockForUpdate()->first();
            $this->assertConsumable($invitation, $secret);
            $invitation->user->update(['password' => $password, 'status' => UserStatus::ACTIVE->value]);
            $invitation->update(['consumed_at' => now()]);
        });
    }

    public function revoke(AccountInvitation $invitation): void
    {
        $invitation->update(['revoked_at' => now()]);
    }

    private function revokePending(User $user): void
    {
        $user->accountInvitations()->whereNull('consumed_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function tokenParts(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new AuthorizationException('Invitation is invalid.');
        }

        return $parts;
    }

    private function assertConsumable(?AccountInvitation $invitation, string $secret): void
    {
        if (! $invitation || $invitation->purpose !== 'password_setup' || $invitation->consumed_at
            || $invitation->revoked_at || $invitation->expires_at->isPast()) {
            throw new AuthorizationException('Invitation is invalid.');
        }
        $user = $invitation->user()->with(['company', 'branch'])->firstOrFail();
        $companyActive = $user->company && (int) $user->company->status === CompanyStatus::ACTIVE->value;
        $branchActive = ! $user->branch_id || ($user->branch && (int) $user->branch->status === BranchStatus::ACTIVE->value);
        if (! $companyActive || ! $branchActive || ! hash_equals($invitation->secret_hash, hash('sha256', $secret))) {
            throw new AuthorizationException('Invitation is invalid.');
        }
    }
}
