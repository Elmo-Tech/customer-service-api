<?php

namespace App\Http\Middleware;

use App\Services\Auth\AccountAccess;
use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    public function __construct(Auth $auth, private readonly AccountAccess $accountAccess)
    {
        parent::__construct($auth);
    }

    public function handle($request, Closure $next, ...$guards)
    {
        return parent::handle($request, function (Request $authenticatedRequest) use ($next) {
            abort_unless($this->accountAccess->isActive($authenticatedRequest->user()), 403);

            return $next($authenticatedRequest);
        }, ...$guards);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }
}
