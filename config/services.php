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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    'embeddings' => [
        'default_provider' => env('EMBEDDING_PROVIDER', 'openai'),
        'default_model' => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
        'providers' => [
            'openai' => [
                'models' => [
                    'text-embedding-3-small' => ['dimensions' => 1536, 'cost_per_1m' => 0.02],
                    'text-embedding-3-large' => ['dimensions' => 3072, 'cost_per_1m' => 0.13],
                ],
            ],
        ],
    ],

    /*
     * Node.js binary used to run the QuickJS sandbox for workflow script.run
     * steps. PHP-FPM and queue workers often don't inherit the interactive
     * shell's PATH, so set NODE_BINARY to an absolute path in those
     * environments (e.g. Herd's bundled node). Defaults to the bare `node`.
     */
    'node' => [
        'binary' => env('NODE_BINARY', 'node'),
    ],

    /*
     * Cloudflare Turnstile — bot protection on the public landing lead form.
     * Optional: with no secret configured, verification is skipped (honeypot +
     * throttling still apply), so local/dev landings work without keys.
     */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    /*
     * Cloudflare for SaaS — custom hostnames for published landings. Optional:
     * without a token/zone the domain flow still works on the DNS check alone
     * (local/dev, or a deployment fronted some other way). cname_target is the
     * host customers point their CNAME at; defaults to the app host.
     */
    'cloudflare_saas' => [
        'api_token' => env('CLOUDFLARE_SAAS_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_SAAS_ZONE_ID'),
        'cname_target' => env('CLOUDFLARE_SAAS_CNAME_TARGET'),
    ],

];
