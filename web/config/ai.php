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

    'workloads' => [
        'recipe_batch' => [
            'model' => env('OPENAI_RECIPE_BATCH_MODEL', 'gpt-5.6-sol'),
            'reasoning_effort' => env('OPENAI_RECIPE_BATCH_REASONING_EFFORT', 'high'),
        ],
        'shopping_list' => [
            'model' => env('OPENAI_SHOPPING_LIST_MODEL', 'gpt-5.6-sol'),
            'reasoning_effort' => env('OPENAI_SHOPPING_LIST_REASONING_EFFORT', 'high'),
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
