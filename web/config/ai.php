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

    'conversations' => [
        'connection' => env('AI_CONVERSATION_DB_CONNECTION'),
        'generate_title' => false,
        'tables' => [
            'conversations' => 'agent_conversations',
            'messages' => 'agent_conversation_messages',
        ],
    ],

    'workloads' => [
        'conversation' => [
            'model' => env('OPENAI_CONVERSATION_MODEL', 'gpt-5.6-luna'),
        ],
        'recipe_batch' => [
            'model' => env('OPENAI_RECIPE_BATCH_MODEL', 'gpt-5.6-sol'),
            'reasoning_effort' => env('OPENAI_RECIPE_BATCH_REASONING_EFFORT', 'high'),
        ],
        'retailer_selection' => [
            'model' => env('OPENAI_RETAILER_SELECTION_MODEL', 'gpt-5.6-luna'),
            'reasoning_effort' => env('OPENAI_RETAILER_SELECTION_REASONING_EFFORT', 'medium'),
        ],
        'retailer_recovery' => [
            'model' => env('OPENAI_RETAILER_RECOVERY_MODEL', 'gpt-5.6-luna'),
            'reasoning_effort' => env('OPENAI_RETAILER_RECOVERY_REASONING_EFFORT', 'low'),
        ],
        'retailer_adjustment' => [
            'model' => env('OPENAI_RETAILER_ADJUSTMENT_MODEL', 'gpt-5.6-sol'),
            'reasoning_effort' => env('OPENAI_RETAILER_ADJUSTMENT_REASONING_EFFORT', 'medium'),
        ],
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
