<?php

namespace App\Services\Auth;

use App\Enums\User\AccountType;
use App\Http\Resources\Role\RoleResource;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use App\Services\UserRolePremission\UserPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthService
{
    public function __construct(
        private readonly UserPermissionService $userPermissionService,
        private readonly AccountAccess $accountAccess,
        private readonly RefreshSessionService $refreshSessions,
        private readonly RefreshCookie $refreshCookie,
    ) {}

    public function login(array $credentials, Request $request): JsonResponse
    {
        $userToken = Auth::attempt([
            'username' => $credentials['username'],
            'password' => $credentials['password'],
        ]);

        if (! $userToken) {
            return response()->json(['message' => 'Invalid or inactive account.'], 401);
        }

        $user = Auth::user();
        if (! $this->accountAccess->isActive($user)) {
            Auth::logout();

            return response()->json(['message' => 'Invalid or inactive account.'], 401);
        }

        $refreshSecret = $this->refreshSessions->create($user, $request);

        return $this->authResponse($userToken, $user)->withCookie($this->refreshCookie->make($refreshSecret));
    }

    public function refresh(string $refreshSecret, Request $request): JsonResponse
    {
        try {
            $rotation = $this->refreshSessions->rotate($refreshSecret, $request);
        } catch (RefreshSessionRejected) {
            return response()->json(['message' => 'Unable to refresh session.'], 401)
                ->withCookie($this->refreshCookie->forget());
        }

        $accessToken = Auth::login($rotation->user);

        return $this->authResponse($accessToken, $rotation->user)
            ->withCookie($this->refreshCookie->make($rotation->secret));
    }

    public function logout(string $refreshSecret): JsonResponse
    {
        $this->refreshSessions->revoke($refreshSecret);
        $this->invalidateAccessToken();

        return response()->json(['message' => 'you have logged out'])
            ->withCookie($this->refreshCookie->forget());
    }

    public function me(User $user): JsonResponse
    {
        abort_unless($this->accountAccess->isActive($user), 403);

        return response()->json($this->sessionPayload($user));
    }

    private function authResponse(string $accessToken, User $user): JsonResponse
    {
        return response()->json([
            'token' => $accessToken,
            'expiresIn' => Auth::factory()->getTTL() * 60,
            ...$this->sessionPayload($user),
        ])->header('Authorization', $accessToken);
    }

    private function invalidateAccessToken(): void
    {
        try {
            Auth::logout();
        } catch (JWTException) {
            return;
        }
    }

    private function sessionPayload(User $user): array
    {
        $role = $user->roles()->with('permissions')->first();

        return [
            'profile' => new UserResource($user),
            'role' => $role ? new RoleResource($role) : null,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $this->userPermissionService->getUserPermissions($user),
            'accountType' => $user->account_type?->value,
            'tenant' => $this->tenantSummary($user),
        ];
    }

    private function tenantSummary(User $user): ?array
    {
        if ($user->account_type !== AccountType::TENANT) {
            return null;
        }

        return [
            'companyId' => $user->company_id,
            'companyName' => $user->company->name,
            'usesBranches' => $user->company->uses_branches,
            'branchId' => $user->branch_id,
            'branchName' => $user->branch?->name,
        ];
    }
}
