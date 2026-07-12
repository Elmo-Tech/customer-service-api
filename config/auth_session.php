<?php

return [
    'cookie_name' => env('AUTH_REFRESH_COOKIE', 'refresh_session'),
    'ttl_minutes' => (int) env('AUTH_REFRESH_TTL', 20160),
    'cookie_path' => env('AUTH_REFRESH_PATH', '/api/v1/admin/auth'),
    'cookie_domain' => env('AUTH_REFRESH_DOMAIN'),
    'cookie_secure' => env('AUTH_REFRESH_SECURE', true),
    'cookie_same_site' => env('AUTH_REFRESH_SAME_SITE', 'none'),
];
