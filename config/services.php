<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    // AI assistant: the Anthropic provider's key when none is saved in
    // Settings -> AI Assistant. OpenAI-compatible providers use config/openai.php.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    // Optional "How to use the AI Assistant" video shown on the assistant page.
    // Leave unset until a real recording exists — no placeholder URL.
    'ai_assistant' => [
        'intro_video_url' => env('AI_ASSISTANT_INTRO_VIDEO_URL'),
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

    // Biometric app — source of truth for all attendance data
    'biometric_app' => [
        'url' => env('BIOMETRIC_APP_URL', 'http://localhost:8080'),
        'key' => env('BIOMETRIC_APP_KEY'),
        'timeout' => (int) env('BIOMETRIC_APP_TIMEOUT', 10),
        'verify_ssl' => (bool) env('BIOMETRIC_APP_VERIFY_SSL', true),
    ],

];
