<?php

namespace App\Services\Auth;

use App\DTOs\Auth\RefreshRotation;
use App\Models\Auth\RefreshSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefreshSessionService
{
    public function __construct(private readonly AccountAccess $accountAccess) {}

    public function create(User $user, Request $request): string
    {
        [$rawSecret, $attributes] = $this->newSessionAttributes($user, $request);
        RefreshSession::create($attributes);

        return $rawSecret;
    }

    public function rotate(string $rawSecret, Request $request): RefreshRotation
    {
        $rotation = DB::transaction(fn () => $this->rotateLocked($rawSecret, $request));

        if (! $rotation) {
            throw new RefreshSessionRejected;
        }

        return $rotation;
    }

    public function revoke(string $rawSecret): void
    {
        $secretParts = explode('.', $rawSecret, 2);

        if (count($secretParts) !== 2 || strlen($secretParts[0]) !== 32) {
            return;
        }

        [$selector] = $secretParts;
        RefreshSession::query()->where('selector', $selector)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function revokeUser(User $user): void
    {
        $user->refreshSessions()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function lockedSession(string $rawSecret): RefreshSession
    {
        [$selector, $secret] = $this->parts($rawSecret);
        $session = RefreshSession::query()->where('selector', $selector)->lockForUpdate()->first();

        if (! $session || $session->revoked_at || $session->expires_at->isPast()
            || ! hash_equals($session->secret_hash, hash('sha256', $secret))) {
            throw new RefreshSessionRejected;
        }

        return $session;
    }

    private function rotateLocked(string $rawSecret, Request $request): ?RefreshRotation
    {
        $session = $this->lockedSession($rawSecret);
        $user = $session->user;

        if (! $this->accountAccess->isActive($user)) {
            $session->update(['revoked_at' => now()]);

            return null;
        }

        [$nextSecret, $attributes] = $this->newSessionAttributes($user, $request);
        $replacement = RefreshSession::create($attributes);
        $session->update(['revoked_at' => now(), 'last_used_at' => now(), 'replaced_by_id' => $replacement->id]);

        return new RefreshRotation($user, $nextSecret);
    }

    private function parts(string $rawSecret): array
    {
        $secretParts = explode('.', $rawSecret, 2);

        if (count($secretParts) !== 2 || strlen($secretParts[0]) !== 32 || strlen($secretParts[1]) < 40) {
            throw new RefreshSessionRejected;
        }

        return $secretParts;
    }

    private function newSessionAttributes(User $user, Request $request): array
    {
        $selector = bin2hex(random_bytes(16));
        $secret = Str::random(64);

        return ["{$selector}.{$secret}", [
            'user_id' => $user->id,
            'selector' => $selector,
            'secret_hash' => hash('sha256', $secret),
            'expires_at' => now()->addMinutes(config('auth_session.ttl_minutes')),
            'ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            'user_agent_hash' => $request->userAgent() ? hash('sha256', $request->userAgent()) : null,
        ]];
    }
}
