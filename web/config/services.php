<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'browserbase' => [
        'api_key' => env('BROWSERBASE_API_KEY'),
        'project_id' => env('BROWSERBASE_PROJECT_ID'),
        'base_url' => env('BROWSERBASE_BASE_URL', 'https://api.browserbase.com'),
        'region' => env('BROWSERBASE_REGION', 'ap-southeast-1'),
        'proxy_city' => env('BROWSERBASE_PROXY_CITY', 'MELBOURNE'),
        'proxy_country' => env('BROWSERBASE_PROXY_COUNTRY', 'AU'),
        'record_local_cart_sessions' => (bool) env('BROWSERBASE_RECORD_LOCAL_CART_SESSIONS', false),
        'session_timeout' => (int) env('BROWSERBASE_SESSION_TIMEOUT', 900),
        'viewport_width' => (int) env('BROWSERBASE_VIEWPORT_WIDTH', 1024),
        'viewport_height' => (int) env('BROWSERBASE_VIEWPORT_HEIGHT', 768),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'store' => env('OPENAI_STORE', false),
        'computer_use_model' => env('OPENAI_COMPUTER_USE_MODEL', 'gpt-5.6-sol'),
        'computer_use_reasoning_effort' => env('OPENAI_COMPUTER_USE_REASONING_EFFORT', 'low'),
        'computer_use_timeout' => (int) env('OPENAI_COMPUTER_USE_TIMEOUT', 90),
    ],

    'chef_automation' => [
        'node_binary' => env('AUTOMATION_NODE_BINARY', 'node'),
        'worker_path' => env('AUTOMATION_WORKER_PATH', base_path('automation/dist/worker.js')),
        'stagehand_worker_path' => env('AUTOMATION_STAGEHAND_WORKER_PATH', base_path('automation/dist/src/main.js')),
        'actor_launcher_path' => env('AUTOMATION_ACTOR_LAUNCHER_PATH', base_path('automation/dist/launch-actor.js')),
        'actor_path' => env('AUTOMATION_ACTOR_PATH', base_path('automation/dist/actor.js')),
        'worker_timeout' => (int) env('AUTOMATION_WORKER_TIMEOUT', 45),
        'authentication_worker_timeout' => (int) env('AUTOMATION_AUTHENTICATION_WORKER_TIMEOUT', 20),
        'actor_startup_timeout' => (int) env('AUTOMATION_ACTOR_STARTUP_TIMEOUT', 12),
        'actor_rpc_timeout' => (int) env('AUTOMATION_ACTOR_RPC_TIMEOUT', 50),
        'actor_heartbeat_ttl' => (int) env('AUTOMATION_ACTOR_HEARTBEAT_TTL', 90),
    ],

    'woolworths' => [
        'login_url' => env('WOOLWORTHS_LOGIN_URL', 'https://www.woolworths.com.au/shop/securelogin'),
        'cart_url' => env('WOOLWORTHS_CART_URL', 'https://www.woolworths.com.au/shop/checkout/cart'),
        'open_cart_url' => env('WOOLWORTHS_OPEN_CART_URL', 'https://www.woolworths.com.au/shop/checkout/cart'),
        'catalogue_search_url' => env('WOOLWORTHS_CATALOGUE_SEARCH_URL', 'https://www.woolworths.com.au/apis/ui/Search/products'),
        'discovery_concurrency' => (int) env('WOOLWORTHS_DISCOVERY_CONCURRENCY', 4),
        'discovery_max_candidates' => (int) env('WOOLWORTHS_DISCOVERY_MAX_CANDIDATES', 5),
        'discovery_timeout' => (int) env('WOOLWORTHS_DISCOVERY_TIMEOUT', 12),
    ],

];
