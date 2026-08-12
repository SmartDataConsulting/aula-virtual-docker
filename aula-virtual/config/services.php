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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'wordpress' => [
        'base_url' => env('WP_AUTH_BASE_URL'),
        'jwt_token_path' => env('WP_JWT_TOKEN_PATH', '/wp-json/jwt-auth/v1/token'),
        'jwt_validate_path' => env('WP_JWT_VALIDATE_PATH', '/wp-json/jwt-auth/v1/token/validate'),
        'timeout' => env('WP_AUTH_TIMEOUT', 10),
        'validate_timeout' => env('WP_AUTH_VALIDATE_TIMEOUT', 5),
        'retry_times' => env('WP_AUTH_RETRY_TIMES', 1),
        'retry_sleep' => env('WP_AUTH_RETRY_SLEEP', 200),
        'validation_cache_ttl' => env('WP_JWT_CACHE_TTL', 60),
    ],

    'api_servicios' => [
        'base_url' => env('API_SERVICIOS_BASE_URL'),
        'token' => env('INTERNAL_SERVICE_TOKEN'),
        'timeout' => env('API_SERVICIOS_TIMEOUT', 5),
        'retry_times' => env('API_SERVICIOS_RETRY_TIMES', 0),
        'retry_sleep' => env('API_SERVICIOS_RETRY_SLEEP', 200),
        'log_success_body' => env('API_SERVICIOS_LOG_SUCCESS_BODY', false),
        'debug_body' => env('APP_DEBUG_API_BODY', false),
        'slow_log_ms' => env('API_SERVICIOS_SLOW_LOG_MS', 800),
        'log_sample_rate' => env('API_SERVICIOS_LOG_SAMPLE_RATE', 0),
    ],

    'certificates' => [
        'public_base_url' => env('CERTIFICADO_PUBLIC_BASE_URL'),
    ],

    'google_drive' => [
        'service_account_path' => env(
            'GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH',
            'storage/google/service-account.json'
        ),
        'lms_folder_id' => env('GOOGLE_DRIVE_LMS_FOLDER_ID'),
    ],

    'correlation' => [
        'header' => env('CORRELATION_HEADER', 'X-Correlation-ID'),
    ],

];
