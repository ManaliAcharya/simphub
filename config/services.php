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

    'clio' => [
        'base_url' => rtrim(env('CLIO_BASE_URL', 'https://app.clio.com'), '/'),
        'api_base_url' => rtrim(env('CLIO_API_BASE_URL', env('CLIO_BASE_URL', 'https://app.clio.com')), '/'),
        'client_id' => env('CLIO_CLIENT_ID'),
        'client_secret' => env('CLIO_CLIENT_SECRET'),
        'redirect_uri' => env('CLIO_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/inbound/clio/callback'),
        'webhook_callback_url' => env('CLIO_WEBHOOK_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/inbound/webhooks/clio'),
        'api_version' => env('CLIO_API_VERSION', '4'),
        'webhook_model' => env('CLIO_WEBHOOK_MODEL', 'bill'),
        'webhook_events' => array_values(array_filter(array_map('trim', explode(',', env('CLIO_WEBHOOK_EVENTS', 'created'))))),
        'webhook_fields' => array_values(array_filter(array_map('trim', explode(',', env('CLIO_WEBHOOK_FIELDS', 'id,number,total,balance,state,issued_at,created_at,updated_at'))))),
        'webhook_expiry_days' => (int) env('CLIO_WEBHOOK_EXPIRY_DAYS', 30),
    ],

    'zoho' => [
        'accounts_base_url' => rtrim(env('ZOHO_ACCOUNTS_BASE_URL', 'https://accounts.zoho.com'), '/'),
        'api_base_url' => rtrim(env('ZOHO_API_BASE_URL', 'https://www.zohoapis.com/books/v3'), '/'),
        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        'redirect_uri' => env('ZOHO_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/inbound/zoho/callback'),
        'scope' => env('ZOHO_SCOPE', 'ZohoBooks.fullaccess.all'),
        'webhook_callback_url' => env('ZOHO_WEBHOOK_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/inbound/webhooks/zoho'),
    ],

    'payment' => [
        'host_url' => rtrim(env('PAYMENT_HOST_URL', env('APP_URL', 'http://localhost')), '/'),
    ],

];
