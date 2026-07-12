<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
        $this->middleware('auth:api', ['except' => ['login', 'refresh', 'logout']]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->authService->login($request->validated(), $request);
    }

    public function logout(Request $request): JsonResponse
    {
        return $this->authService->logout($request->cookie(config('auth_session.cookie_name'), ''));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->authService->me($request->user());
    }

    public function refresh(Request $request): JsonResponse
    {
        return $this->authService->refresh(
            $request->cookie(config('auth_session.cookie_name'), ''),
            $request,
        );
    }
}
