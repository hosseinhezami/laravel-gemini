<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gemini API Key
    |--------------------------------------------------------------------------
    |
    | Your Google Gemini API key. You can get it from:
    | https://aistudio.google.com/app/apikey
    |
    */

    'api_key' => env('GEMINI_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Base URI
    |--------------------------------------------------------------------------
    |
    | The base URI for the Gemini API.
    |
    */

    'base_uri' => env('GEMINI_BASE_URI', 'https://generativelanguage.googleapis.com/v1beta/'),

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The default provider to use for API requests.
    |
    */

    'default_provider' => env('GEMINI_DEFAULT_PROVIDER', 'gemini'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for different providers. Each provider should specify
    | the class and default models and methods for different capabilities.
    |
    */

    'providers' => [
        'gemini' => [
            'class' => \HosseinHezami\LaravelGemini\Providers\GeminiProvider::class,
            'models' => [
                'text' => 'gemini-2.5-flash-lite',
                'image' => 'gemini-2.5-flash-image-preview',
                'video' => 'veo-3.0-fast-generate-001',
                'audio' => 'gemini-2.5-flash-preview-tts',
                'embedding' => 'gemini-embedding-001',
            ],
            'methods' => [
                'text' => 'generateContent',
                'image' => 'generateContent', // generateContent, predict
                'video' => 'predictLongRunning',
                'audio' => 'generateContent',
            ],
            /**
             * Set voice name for single-speaker TTS.
             *
             * @param  string  $voiceName  e.g., 'Kore', 'Puck'
             *                             Set speaker voices for multi-speaker TTS.
             * @param  array  $speakerVoices  e.g., [['speaker' => 'Joe', 'voiceName' => 'Kore'], ['speaker' => 'Jane', 'voiceName' => 'Puck']]
             */
            'default_speech_config' => [
                'voiceName' => 'Kore',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for API requests.
    |
    */

    'timeout' => env('GEMINI_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | Configuration for retrying failed requests.
    |
    */

    'retry_policy' => [
        'max_retries' => env('GEMINI_MAX_RETRIES', 30),
        'retry_delay' => env('GEMINI_RETRY_DELAY', 1000), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety Settings
    |--------------------------------------------------------------------------
    |
    | Default safety settings for content generation.
    |
    */

    'safety_settings' => [
        [
            'category' => 'HARM_CATEGORY_HARASSMENT',
            'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
        ],
        [
            'category' => 'HARM_CATEGORY_HATE_SPEECH',
            'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
        ],
        [
            'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
            'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
        ],
        [
            'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
            'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Enable or disable logging of API requests and responses.
    |
    */

    'logging' => env('GEMINI_LOGGING', false),

    /*
    |--------------------------------------------------------------------------
    | Stream Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for streaming responses.
    |
    */

    'stream' => [
        'chunk_size' => env('GEMINI_STREAM_CHUNK_SIZE', 1024),
        'timeout' => env('GEMINI_STREAM_TIMEOUT', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Blade Template Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Blade template support in prompts.
    |
    */

    'blade' => [
        'views_namespace' => 'prompts', // Default namespace for prompt templates
    ],

];
