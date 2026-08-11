<?php

return [
    'features' => [
        'experience' => (bool) env('CHEF_RETAILER_EXPERIENCE_ENABLED', false),
        'discovery' => (bool) env('CHEF_RETAILER_DISCOVERY_ENABLED', false),
        'mutation' => (bool) env('CHEF_RETAILER_MUTATION_ENABLED', false),
        'mutation_circuit_breaker' => (bool) env('CHEF_RETAILER_MUTATION_CIRCUIT_BREAKER', true),
        'ai_recovery' => (bool) env('CHEF_RETAILER_AI_RECOVERY_ENABLED', false),
    ],

    'protocol' => 'chef.retailer.v1',

    'browserbase' => [
        'api_key' => env('BROWSERBASE_API_KEY'),
        'project_id' => env('BROWSERBASE_PROJECT_ID'),
        'region' => env('BROWSERBASE_REGION', 'ap-southeast-1'),
        'proxy_country' => env('BROWSERBASE_PROXY_COUNTRY', 'AU'),
        'record_session' => false,
        'log_session' => false,
        'session_timeout_seconds' => (int) env('BROWSERBASE_SESSION_TIMEOUT_SECONDS', 300),
    ],

    'worker' => [
        'node_binary' => env('RETAILER_WORKER_NODE_BINARY', 'node'),
        'entrypoint' => base_path('automation/dist/worker.js'),
        'timeout_seconds' => (int) env('RETAILER_WORKER_TIMEOUT_SECONDS', 120),
    ],

    'runtime_circuit_breaker' => [
        'store' => env('RETAILER_RUNTIME_CIRCUIT_BREAKER_STORE', 'redis'),
        'key' => env('RETAILER_RUNTIME_CIRCUIT_BREAKER_KEY', 'chef:retailer:mutation-circuit-breaker'),
    ],

    'readiness' => [
        'cache_store' => env('RETAILER_READINESS_CACHE_STORE', 'redis'),
        'queue_probe_timeout_seconds' => (int) env('RETAILER_QUEUE_PROBE_TIMEOUT_SECONDS', 5),
    ],

    'testing' => [
        'recorded_fixture' => env('CHEF_RETAILER_RECORDED_FIXTURE'),
    ],

    'providers' => [
        'coles' => [
            'base_url' => 'https://www.coles.com.au',
            'allowed_hosts' => [
                'www.coles.com.au',
                'coles.com.au',
            ],
        ],
    ],

    'consent' => [
        'disclosure_version' => 'coles-basket-replacement-2026-07-31',
        'disclosure' => 'Chef will use the connected Coles browser session to replace the current basket after each approved meal plan. This standing consent covers reversible basket changes only. It never covers fulfilment selection, checkout, payment, restricted products, or order placement. Coles advises customers not to share login details with other apps; review the linked Coles terms and Chef privacy disclosure before continuing.',
        'links' => [
            'coles_online_safety' => 'https://www.coles.com.au/help/safety/online-safety',
            'coles_customer_agreement' => 'https://www.coles.com.au/important-information/customer-agreement?cid=wsm',
        ],
    ],

    'rollout' => [
        'requires_legal_approval' => true,
        'requires_privacy_approval' => true,
        'requires_postgres_and_redis' => true,
    ],
];
