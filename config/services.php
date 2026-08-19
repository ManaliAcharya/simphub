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
        'scope' => env('CLIO_SCOPE', 'openid'),
        'webhook_callback_url' => env('CLIO_WEBHOOK_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/inbound/webhooks/clio'),
        'api_version' => env('CLIO_API_VERSION', '4'),
        'webhook_model' => env('CLIO_WEBHOOK_MODEL', 'bill'),
        'webhook_events' => array_values(array_filter(array_map('trim', explode(',', env('CLIO_WEBHOOK_EVENTS', 'created,updated,deleted'))))),
        'webhook_fields' => array_values(array_filter(array_map('trim', explode(',', env('CLIO_WEBHOOK_FIELDS', 'id,number,total,balance,state,issued_at,created_at,updated_at'))))),
        'webhook_expiry_days' => (int) env('CLIO_WEBHOOK_EXPIRY_DAYS', 30),
        'approved_bill_states' => array_values(array_filter(array_map('trim', array_map('strtoupper', explode(',', env('CLIO_APPROVED_BILL_STATES', 'approved')))))),
    ],

    'zoho' => [
        'book_base_url' => rtrim(env('ZOHO_BOOK_BASE_URL', 'https://www.zohoapis.com/books/v3'), '/'),
        'accounts_base_url' => rtrim(env('ZOHO_ACCOUNTS_BASE_URL', 'https://accounts.zoho.com'), '/'),
        'invoice_base_url' => rtrim(env('ZOHO_INVOICE_BASE_URL', 'https://www.zohoapis.com/invoice/v3'), '/'),
        'api_base_url' => rtrim(env('ZOHO_API_BASE_URL', 'https://www.zohoapis.com/books/v3'), '/'),
        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        'redirect_uri' => env('ZOHO_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/inbound/zoho/callback'),
        'scope' => env('ZOHO_SCOPE', 'ZohoBooks.fullaccess.all'),
        'organization_id' => env('ZOHO_ORGANIZATION_ID'),
        'organization_name' => env('ZOHO_ORGANIZATION_NAME'),
        'webhook_demo_video_url' => env('ZOHO_WEBHOOK_DEMO_VIDEO_URL'),
        'webhook_callback_url' => env('ZOHO_WEBHOOK_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/inbound/webhooks/zoho'),
    ],

    'quickbooks' => [
        'client_id'              => env('QB_CLIENT_ID'),
        'client_secret'          => env('QB_CLIENT_SECRET'),
        'client_id_production'     => env('QB_CLIENT_ID_PRODUCTION'),
        'client_secret_production' => env('QB_CLIENT_SECRET_PRODUCTION'),
        'redirect_uri'           => env('QB_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/inbound/quickbooks/callback'),
        'scope'                  => env('QB_SCOPE', 'com.intuit.quickbooks.accounting'),
        'base_url'               => rtrim(env('QB_BASE_URL', 'https://sandbox-quickbooks.api.intuit.com'), '/'),
        'base_url_production'    => rtrim(env('QB_BASE_URL_PRODUCTION', 'https://quickbooks.api.intuit.com'), '/'),
        'oauth_base_url'         => rtrim(env('QB_OAUTH_BASE_URL', 'https://appcenter.intuit.com'), '/'),
        'token_url'              => rtrim(env('QB_TOKEN_URL', 'https://oauth.platform.intuit.com'), '/'),
        'webhook_callback_url'   => env('QB_WEBHOOK_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/inbound/webhooks/quickbooks'),
        'webhook_verifier_token' => env('QB_WEBHOOK_VERIFIER_TOKEN'),
        'minor_version'          => env('QB_MINOR_VERSION', '75'),
    ],

    'lawcus' => [
        'base_url'             => rtrim(env('LAWCUS_BASE_URL', 'https://app.lawcus.com'), '/'),
        'api_base_url'         => rtrim(env('LAWCUS_API_BASE_URL', env('LAWCUS_BASE_URL', 'https://app.lawcus.com')), '/'),
        'client_id'            => env('LAWCUS_CLIENT_ID'),
        'client_secret'        => env('LAWCUS_CLIENT_SECRET'),
        'redirect_uri'         => env('LAWCUS_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/inbound/lawcus/callback'),
        'scope'                => env('LAWCUS_SCOPE', 'openid'),
        'webhook_callback_url' => env('LAWCUS_WEBHOOK_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/inbound/webhooks/lawcus'),
        'webhook_events'       => array_values(array_filter(array_map('trim', explode(',', env('LAWCUS_WEBHOOK_EVENTS', 'invoice.created'))))),
    ],

    'mindbody' => [
        'api_key' => env('MINDBODY_API_KEY'),
    ],

    'nmi' => [
        'collectjs_url'        => env('NMI_COLLECTJS_URL', 'https://secure.networkmerchants.com/token/Collect.js'),
        'collectjs_public_key' => env('NMI_COLLECTJS_PUBLIC_KEY'),
    ],

    'fluidpay' => [
        'base_url'              => rtrim(env('FLUIDPAY_BASE_URL', 'https://sandbox.fluidpay.com'), '/'),
        'base_url_production'   => rtrim(env('FLUIDPAY_BASE_URL_PRODUCTION', 'https://app.fluidpay.com'), '/'),
        'tokenizer_url'             => env('FLUIDPAY_TOKENIZER_URL'),
        'tokenizer_url_production'  => env('FLUIDPAY_TOKENIZER_URL_PRODUCTION'),
        'public_key'            => env('FLUIDPAY_PUBLIC_KEY'),
        'public_key_production' => env('FLUIDPAY_PUBLIC_KEY_PRODUCTION'),
        'api_key'               => env('FLUIDPAY_API_KEY'),
        'api_key_production'    => env('FLUIDPAY_API_KEY_PRODUCTION'),
    ],

    'wave' => [
        'base_url'             => rtrim(env('WAVE_BASE_URL', 'https://api.waveapps.com'), '/'),
        'graphql_url'          => env('WAVE_GRAPHQL_URL', 'https://gql.waveapps.com/graphql/public'),
        'client_id'            => env('WAVE_CLIENT_ID'),
        'client_secret'        => env('WAVE_CLIENT_SECRET'),
        'full_access_token'    => env('WAVE_FULL_ACCESS_TOKEN'),
        'redirect_uri'         => env('WAVE_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/inbound/wave/callback'),
        'scope'                => env('WAVE_SCOPE', 'account:*'),
        'webhook_callback_url' => env('WAVE_WEBHOOK_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/inbound/webhooks/wave'),
        'webhook_secret'       => env('WAVE_WEBHOOK_SECRET'),
    ],

    'payment' => [
        'host_url' => rtrim(env('PAYMENT_HOST_URL', env('APP_URL', 'http://localhost')), '/'),
    ],

    'booksync' => [
        'qb_client_id'     => env('BOOKSYNC_QB_CLIENT_ID'),
        'qb_client_secret' => env('BOOKSYNC_QB_CLIENT_SECRET'),
        'qb_redirect_uri'  => env('BOOKSYNC_QB_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/booksync/setup/qb/callback'),
    ],

];
