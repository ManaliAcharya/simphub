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
        // Clio's webhook subscription rejects state/number/total with InvalidFields. updated_at
        // changes on every edit (approval included), and ingestion re-fetches the bill's state.
        'webhook_fields' => array_values(array_filter(array_map('trim', explode(',', env('CLIO_WEBHOOK_FIELDS', 'id,created_at,updated_at'))))),
        'webhook_expiry_days' => (int) env('CLIO_WEBHOOK_EXPIRY_DAYS', 30),
        // Clio bill states: draft, awaiting_approval, awaiting_payment, paid, void, deleted.
        // Approving a bill moves it to awaiting_payment, which releases the payment-link email.
        'approved_bill_states' => array_values(array_filter(array_map('trim', array_map('strtoupper', explode(',', env('CLIO_APPROVED_BILL_STATES', 'awaiting_payment')))))),
        // After SimpHub emails the client, record the send on the Clio bill (mark_as_sent).
        'mark_bills_sent' => (bool) env('CLIO_MARK_BILLS_SENT', true),
        // Emailed when a firm's Clio webhook is suspended, missing, or can't be renewed.
        'ops_alert_email' => env('CLIO_OPS_ALERT_EMAIL'),
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

    // Zoho CRM (subscription clients). Uses the same Zoho OAuth app as Zoho Books; that
    // app's redirect URIs in the Zoho API Console must also include /inbound/zohocrm/callback.
    'zohocrm' => [
        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        'redirect_uri' => env('ZOHO_CRM_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/inbound/zohocrm/callback'),
        // The Zoho Books scopes let the same connection create the subscription invoices and
        // payments in Books (see ZohoBooksSubscriptionSyncService).
        'scope' => env('ZOHO_CRM_SCOPE', implode(',', [
            'ZohoCRM.modules.contacts.READ',
            'ZohoCRM.modules.contacts.UPDATE',
            'ZohoCRM.settings.fields.ALL',
            'ZohoCRM.org.READ',
            'ZohoCRM.coql.READ',
            'ZohoBooks.settings.READ',
            'ZohoBooks.accountants.READ',
            'ZohoBooks.contacts.CREATE',
            'ZohoBooks.contacts.READ',
            'ZohoBooks.invoices.CREATE',
            'ZohoBooks.invoices.READ',
            'ZohoBooks.invoices.UPDATE',
            'ZohoBooks.customerpayments.CREATE',
        ])),
        'api_version' => env('ZOHO_CRM_API_VERSION', 'v8'),
        // Which Contact fields receive the payment values is mapped per client on the
        // config page (see ZohoCrmPaymentSyncService).
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
        'rest_api_base_url'    => rtrim(env('LAWCUS_REST_API_BASE_URL', 'https://api.us.lawcus.com'), '/'),
        'client_id'            => env('LAWCUS_CLIENT_ID'),
        'client_secret'        => env('LAWCUS_CLIENT_SECRET'),
        'redirect_uri'         => env('LAWCUS_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/').'/inbound/lawcus/callback'),
        'scope'                => env('LAWCUS_SCOPE', 'openid'),
        'webhook_callback_url' => env('LAWCUS_WEBHOOK_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/inbound/webhooks/lawcus'),
        'webhook_events'       => array_values(array_filter(array_map('trim', explode(',', env('LAWCUS_WEBHOOK_EVENTS', 'invoice.created'))))),
        'ops_alert_email'      => env('LAWCUS_OPS_ALERT_EMAIL'),
        // Matter activity feed entries SimpHub posts (LawcusMatterActivityService): SECURE_MESSAGE or PHONE.
        'activity_interaction_type' => env('LAWCUS_ACTIVITY_INTERACTION_TYPE', 'SECURE_MESSAGE'),
    ],

    // JobTread (Pave API). Field names and enums live in Modules\Inbound\Services\JobTreadPave;
    // the values marked "verify" there are unconfirmed until the first-day tests with HEP's grant key.
    'jobtread' => [
        'api_url'              => rtrim(env('JOBTREAD_API_URL', 'https://api.jobtread.com/pave'), '/'),
        'webhook_callback_url' => env('JOBTREAD_WEBHOOK_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/v1/inbound/webhooks/jobtread'),
        // Names verified against the live API on 2026-11-27. Payment events let a payment recorded
        // in JobTread (check, card) close or reopen the SimpHub link right away instead of at the next poll.
        'webhook_events'       => array_values(array_filter(array_map('trim', explode(',', env('JOBTREAD_WEBHOOK_EVENTS', 'documentCreated,documentUpdated,documentSent,documentDeleted,documentPaymentCreated,documentPaymentDeleted'))))),
        // Payment written back for a customer receipt: credit or debit (first-day test #3).
        'payment_type'         => env('JOBTREAD_PAYMENT_TYPE', 'credit'),
        // Optional createPayment "source" value; left out when blank (its allowed values are unverified).
        'payment_source'       => env('JOBTREAD_PAYMENT_SOURCE'),
        // No published rate limits: at most one request per this many milliseconds per connection.
        'min_request_interval_ms' => (int) env('JOBTREAD_MIN_REQUEST_INTERVAL_MS', 350),
        // Attach JobTread's own invoice PDF instead of SimpHub's (first-day test #7). Off until verified.
        'native_pdf'           => (bool) env('JOBTREAD_NATIVE_PDF', false),
        'pdf_base_url'         => rtrim(env('JOBTREAD_PDF_BASE_URL', 'https://api.jobtread.com/t'), '/'),
        'ops_alert_email'      => env('JOBTREAD_OPS_ALERT_EMAIL'),

        // ── Safety switches while testing on a live JobTread account ──
        // Off: connecting does not register a webhook; the 5-minute poll finds invoices instead.
        'webhooks_enabled'     => (bool) env('JOBTREAD_WEBHOOKS_ENABLED', false),
        // Off: a SimpHub payment is not written back to JobTread (nothing is created there).
        'payment_writeback'    => (bool) env('JOBTREAD_PAYMENT_WRITEBACK', false),
        // When either list is set, only invoices for these recipients / JobTread customer
        // accounts are processed; every other invoice is ignored (not stored, not emailed).
        'only_recipients'      => array_values(array_filter(array_map(fn ($v) => strtolower(trim($v)), explode(',', (string) env('JOBTREAD_ONLY_RECIPIENTS', ''))))),
        'only_account_ids'     => array_values(array_filter(array_map('trim', explode(',', (string) env('JOBTREAD_ONLY_ACCOUNT_IDS', ''))))),
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

    // Paya GETI ACH status lookup, polled daily by PollAchSettlementsJob. The AuthGateway
    // WSDL we charge through has no status call, so this stays off (ACH notifications go
    // out at submit, as before) until Paya gives us the reporting service and method.
    'paya' => [
        'status_wsdl'   => env('PAYA_STATUS_WSDL'),
        'status_method' => env('PAYA_STATUS_METHOD'),
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
