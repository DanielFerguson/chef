<?php

return [
    'paths' => ['api/extension/*'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => [],
    'allowed_origins_patterns' => ['/^chrome-extension:\/\/[a-p]{32}$/'],
    'allowed_headers' => ['Content-Type', 'Accept', 'X-Chef-Connection-Token'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
