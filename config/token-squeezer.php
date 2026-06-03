<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    | Options: openai | claude | gemini | kimi | ollama
    */
    'default_provider' => env('TSQ_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    | Configure credentials and defaults for each provider.
    */
    'providers' => [

        'openai' => [
            'api_key'      => env('OPENAI_API_KEY'),
            'model'        => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'timeout'      => 15,
            'retries'      => 2,
            'rate_limit'   => env('TSQ_OPENAI_RATE_LIMIT', 0),   // max requests per window (0 = no limit)
            'rate_window'  => env('TSQ_OPENAI_RATE_WINDOW', 60), // window in seconds
        ],

        'claude' => [
            'api_key'      => env('ANTHROPIC_API_KEY'),
            'model'        => env('CLAUDE_MODEL', 'claude-haiku-4-5-20251001'),
            'timeout'      => 15,
            'retries'      => 2,
            'rate_limit'   => env('TSQ_CLAUDE_RATE_LIMIT', 0),
            'rate_window'  => env('TSQ_CLAUDE_RATE_WINDOW', 60),
        ],

        'gemini' => [
            'api_key'      => env('GEMINI_API_KEY'),
            'model'        => env('GEMINI_MODEL', 'gemini-1.5-flash'),
            'timeout'      => 15,
            'retries'      => 2,
            'rate_limit'   => env('TSQ_GEMINI_RATE_LIMIT', 0),
            'rate_window'  => env('TSQ_GEMINI_RATE_WINDOW', 60),
        ],

        'kimi' => [
            'api_key'      => env('KIMI_API_KEY'),
            'model'        => env('KIMI_MODEL', 'moonshot-v1-8k'),
            'timeout'      => 15,
            'retries'      => 2,
            'rate_limit'   => env('TSQ_KIMI_RATE_LIMIT', 0),
            'rate_window'  => env('TSQ_KIMI_RATE_WINDOW', 60),
        ],

        'mimo' => [
            'api_key'      => env('MIMO_API_KEY'),
            'model'        => env('MIMO_MODEL', 'mimo-v2.5'),
            'base_url'     => env('MIMO_BASE_URL', 'https://api.xiaomimimo.com/v1/chat/completions'),
            'timeout'      => 15,
            'retries'      => 2,
            'rate_limit'   => env('TSQ_MIMO_RATE_LIMIT', 0),
            'rate_window'  => env('TSQ_MIMO_RATE_WINDOW', 60),
        ],

        'ollama' => [
            'base_url'     => env('OLLAMA_URL', 'http://localhost:11434/api/chat'),
            'model'        => env('OLLAMA_MODEL', 'llama3'),
            'timeout'      => 30,
            'retries'      => 1,
            'rate_limit'   => 0,  // local model, no rate limit needed
            'rate_window'  => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | driver: array | file | redis | laravel
    | Use 'laravel' to delegate to Laravel's Cache facade (recommended).
    */
    'cache' => [
        'enabled' => env('TSQ_CACHE_ENABLED', true),
        'driver'  => env('TSQ_CACHE_DRIVER', 'laravel'),
        'prefix'  => env('TSQ_CACHE_PREFIX', 'tsq:'),
        'ttl'     => env('TSQ_CACHE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    */
    'compress' => [
        'default_mode'     => \TokenSqueezer\CompressMode::BALANCED->value,
        'strip_stopwords'  => true,
        'lowercase_keys'   => true,
        'max_context_keys' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Monitoring
    |--------------------------------------------------------------------------
    */
    'monitor' => env('TSQ_MONITOR', true),

];
