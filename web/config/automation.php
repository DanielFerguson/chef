<?php

return [
    'connection_enabled' => env('WOOLWORTHS_CONNECTION_ENABLED', false),
    'cart_mutation_enabled' => env('WOOLWORTHS_CART_MUTATION_ENABLED', false),
    'normal_app_sync_proven' => env('WOOLWORTHS_NORMAL_APP_SYNC_PROVEN', false),
    'queue' => env('AUTOMATION_QUEUE', 'automation'),
    'run_ttl_minutes' => (int) env('AUTOMATION_RUN_TTL_MINUTES', 60),
    'lease_seconds' => (int) env('AUTOMATION_LEASE_SECONDS', 900),
    'max_actions' => (int) env('AUTOMATION_MAX_ACTIONS', 40),
    'max_runtime_seconds' => (int) env('AUTOMATION_MAX_RUNTIME_SECONDS', 180),
    'max_actions_per_job' => (int) env('AUTOMATION_MAX_ACTIONS_PER_JOB', 8),
    'max_item_attempts' => (int) env('AUTOMATION_MAX_ITEM_ATTEMPTS', 4),
];
