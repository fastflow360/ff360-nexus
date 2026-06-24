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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'cognito' => [
        'user_pool_id' => env('AWS_COGNITO_USER_POOL_ID', 'sa-east-1_ZomcFef3Z'),
        'region' => env('AWS_COGNITO_REGION'),
        'issuer' => env('AWS_COGNITO_ISSUER'),
        'resource_server' => env('AWS_COGNITO_RESOURCE_SERVER', 'ff360-api'),
        'token_leeway' => (int) env('AWS_COGNITO_TOKEN_LEEWAY', 60),
        'jwks_cache_ttl' => (int) env('AWS_COGNITO_JWKS_CACHE_TTL', 3600),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
