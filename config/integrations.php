<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Execution caps
    |--------------------------------------------------------------------------
    */
    'max_timeout_ms' => 30_000,
    'max_request_body_bytes' => 1_048_576,   // 1 MB
    'response_store_cap' => 1_048_576,       // 1 MB stored
    'response_stream_cap' => 10_485_760,     // 10 MB hard abort
    'max_redirects' => 5,
    'async_threshold_ms' => 5_000,

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    | When true, the SSRF guard is bypassed for sysadmin actors. Default off.
    */
    'allow_internal_hosts' => env('INTEGRATIONS_ALLOW_INTERNAL_HOSTS', false),

    /*
    | Extra header names to strip from stored execution records. Always lower-
    | case. The base list always includes authorization, proxy-authorization,
    | x-api-key, api-key, cookie, x-auth-token.
    */
    'redact_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Execution retention
    |--------------------------------------------------------------------------
    | The scheduled `integrations:prune-executions` command keeps the last
    | {count} executions per integration AND anything newer than {days}.
    */
    'execution_retention' => [
        'count' => 200,
        'days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    */
    'execute_rate_limit' => [
        'per_minute' => 60,
    ],
];
