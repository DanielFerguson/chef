<?php

return [
    'default' => 'openai',
    'default_for_images' => 'openai',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'openai',

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    'computer_use' => [
        'model' => env('OPENAI_COMPUTER_MODEL', 'gpt-5.6'),
        'timeout' => env('OPENAI_COMPUTER_TIMEOUT', 90),
        'screenshot_retention_hours' => env('AUTOMATION_SCREENSHOT_RETENTION_HOURS', 24),
        'run_expiry_minutes' => env('AUTOMATION_RUN_EXPIRY_MINUTES', 60),
    ],

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', false),
        ],
    ],
];
