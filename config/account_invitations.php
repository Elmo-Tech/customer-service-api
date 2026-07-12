<?php

return [
    'ttl_minutes' => (int) env('ACCOUNT_INVITATION_TTL', 1440),
    'frontend_url' => env('ACCOUNT_INVITATION_FRONTEND_URL', 'https://tickets.testingelmo.com/setup-password'),
];
