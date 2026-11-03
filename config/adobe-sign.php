<?php

return [

    /*
    | How the default client authenticates: "integration_key" (one account-wide key) or
    | "oauth" (each user connects their own Acrobat Sign account; use AdobeSign::forUser()).
    */
    'auth' => env('ADOBE_SIGN_AUTH', 'integration_key'),

    'integration_key' => env('ADOBE_SIGN_INTEGRATION_KEY'),

    'oauth' => [
        'client_id' => env('ADOBE_SIGN_CLIENT_ID'),
        'client_secret' => env('ADOBE_SIGN_CLIENT_SECRET'),

        // Defaults to the package's callback route. Must match your app's OAuth configuration.
        'redirect_uri' => env('ADOBE_SIGN_REDIRECT_URI'),

        // Space-separated scope names with optional :self / :group / :account modifiers.
        'scopes' => array_values(array_filter(explode(' ', (string) env(
            'ADOBE_SIGN_SCOPES',
            'user_login:self agreement_read:self agreement_write:self agreement_send:self webhook_read:self webhook_write:self',
        )))),

        // Tokens are stored encrypted with your APP_KEY.
        'table' => 'adobe_sign_tokens',

        // GET {prefix}/redirect and {prefix}/callback; the signed-in user's id is the token key.
        'routes' => true,
        'route_prefix' => 'adobe-sign/oauth',
        'route_middleware' => ['web', 'auth'],
        'redirect_after' => '/',
    ],

    // Shard-agnostic host for GET /baseUris. Only change for e.g. Gov Cloud.
    'bootstrap_url' => env('ADOBE_SIGN_BOOTSTRAP_URL', 'https://api.adobesign.com'),

    // Cache store for discovered data-center URLs (null = default store).
    'cache_store' => env('ADOBE_SIGN_CACHE_STORE'),
    'base_uri_ttl' => 86400,

    'retries' => [
        'max' => 3,
        'max_delay_seconds' => 60,
    ],

    // Log channel for request/throttling logs (null = default channel, false = off).
    'log_channel' => env('ADOBE_SIGN_LOG_CHANNEL'),

    'webhooks' => [
        'enabled' => true,
        'path' => 'adobe-sign/webhook',

        // Deliberately not "web": Adobe can't send a CSRF token or session.
        'middleware' => [],

        // Client (application) ids allowed to deliver webhooks. Comma-separated in .env.
        // Defaults to oauth.client_id when empty.
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADOBE_SIGN_WEBHOOK_CLIENT_IDS', ''))))),

        // Adobe retries for 72 hours; remember notification ids that long to drop duplicates.
        'dedupe_ttl' => 72 * 3600,
    ],

];
