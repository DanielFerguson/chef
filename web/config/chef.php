<?php

return [
    'release' => [
        'database' => env('CHEF_RELEASE_DATABASE', 'mysql'),
        'queue' => env('CHEF_RELEASE_QUEUE', 'redis'),
        'cache' => env('CHEF_RELEASE_CACHE', 'redis'),
        'session' => env('CHEF_RELEASE_SESSION', 'redis'),
        'broadcast' => env('CHEF_RELEASE_BROADCAST', 'reverb'),
        'filesystem' => env('CHEF_RELEASE_FILESYSTEM', 's3'),
    ],

    'retention' => [
        'automation_screenshots_hours' => (int) env('AUTOMATION_SCREENSHOT_RETENTION_HOURS', 24),
        'conversations_days' => (int) env('CONVERSATION_RETENTION_DAYS', 730),
        'audit_days' => (int) env('AUDIT_RETENTION_DAYS', 730),
    ],

    'storage' => [
        'automation_screenshots_disk' => env('AUTOMATION_SCREENSHOT_DISK', 'local'),
    ],

    'support' => [
        'email' => env('SUPPORT_EMAIL'),
        'privacy_url' => env('PRIVACY_URL'),
        'terms_url' => env('TERMS_URL'),
    ],

    'legal' => [
        'operator' => env('LEGAL_ENTITY_NAME'),
        'contact_address' => env('LEGAL_CONTACT_ADDRESS'),
        'processing_countries' => env('DATA_PROCESSING_COUNTRIES'),
    ],

    'quotas' => [
        'ai_requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 12),
        'ai_tokens_per_month' => (int) env('AI_TOKENS_PER_MONTH', 2_000_000),
        'ai_cost_usd_per_month' => (float) env('AI_COST_USD_PER_MONTH', 25),
        'ai_input_usd_per_million' => (float) env('AI_INPUT_USD_PER_MILLION', 0),
        'ai_output_usd_per_million' => (float) env('AI_OUTPUT_USD_PER_MILLION', 0),
        'ai_cache_read_usd_per_million' => (float) env('AI_CACHE_READ_USD_PER_MILLION', 0),
        'automation_runs_per_day' => (int) env('AUTOMATION_RUNS_PER_DAY', 10),
        'automation_steps_per_run' => (int) env('AUTOMATION_STEPS_PER_RUN', 100),
        'voice_sessions_per_day' => (int) env('VOICE_SESSIONS_PER_DAY', 20),
    ],
];
