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

    'pixelforge' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'api_key' => env('PIXELFORGE_API_KEY'),

        // Signing secret (whsec_…) of the API key above; each key has its own.
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

        // Reject webhooks whose signed timestamp is older/newer than this (replay protection).
        'webhook_tolerance_seconds' => 300,

        // HTTP timeouts for calls to the API, in seconds.
        'timeout' => 15,
        'connect_timeout' => 5,
        // Pricing is fetched during page requests; never make a user wait long for it.
        'pricing_timeout' => 5,

        // GET /v1/pricing is cached for estimates; real charges always use each generation's own rates.
        'pricing_cache_seconds' => 3600,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
