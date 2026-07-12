<?php

return [
    'purpose' => 'ticket_review',
    'ttl_minutes' => (int) env('TICKET_REVIEW_TTL', 10080),
    'frontend_url' => env('FRONTEND_URL', 'https://tickets-sys.testingelmo.com'),
];
