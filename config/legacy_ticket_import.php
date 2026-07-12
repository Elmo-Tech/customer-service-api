<?php

return [
    'connection' => 'legacy',
    'source_key' => env('LEGACY_TICKET_SOURCE_KEY', 'legacy-customer-service'),
    'attachment_disk' => 'public',
    'chunk_size' => 200,
];
