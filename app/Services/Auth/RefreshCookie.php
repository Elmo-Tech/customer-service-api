<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class RefreshCookie
{
    public function make(string $secret): SymfonyCookie
    {
        return Cookie::make(
            config('auth_session.cookie_name'),
            $secret,
            config('auth_session.ttl_minutes'),
            config('auth_session.cookie_path'),
            config('auth_session.cookie_domain'),
            config('auth_session.cookie_secure'),
            true,
            false,
            config('auth_session.cookie_same_site'),
        );
    }

    public function forget(): SymfonyCookie
    {
        return Cookie::make(
            config('auth_session.cookie_name'),
            '',
            -1,
            config('auth_session.cookie_path'),
            config('auth_session.cookie_domain'),
            config('auth_session.cookie_secure'),
            true,
            false,
            config('auth_session.cookie_same_site'),
        );
    }
}
