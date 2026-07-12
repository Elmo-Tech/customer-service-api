<?php

namespace App\Http\Middleware;

use App\Enums\User\AccountType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireInternalAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->account_type !== AccountType::INTERNAL) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
