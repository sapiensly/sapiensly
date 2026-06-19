<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SSRF guard
    |--------------------------------------------------------------------------
    |
    | Central protection for every outbound HTTP call that carries a
    | user-controlled URL (workflow `http.request`, wireframe import). The
    | guard resolves DNS, validates the RESOLVED IP against a blocklist and
    | pins the connection to it (anti-rebinding). The CIDR blocklist itself
    | lives in App\Services\Security\Ssrf\IpRangeMatcher as constants — not
    | here — so it can't be loosened by an env var.
    |
    */

    'ssrf' => [
        // Master switch. Keep `true` in production. Only flip to false in test
        // environments that deliberately need the guard out of the way.
        'enabled' => env('SSRF_GUARD_ENABLED', true),

        // Hard cap on redirect hops; each hop is re-validated.
        'max_redirects' => (int) env('SSRF_MAX_REDIRECTS', 5),

        // Abort downloads larger than this (bytes). Default 5 MB.
        'max_response_bytes' => (int) env('SSRF_MAX_RESPONSE_BYTES', 5 * 1024 * 1024),

        // DNS resolution timeout (ms). Best-effort; PHP's stub resolver is
        // ultimately governed by the system resolv.conf.
        'dns_timeout_ms' => (int) env('SSRF_DNS_TIMEOUT_MS', 2000),

        // Optional allowlist of internal hosts that are legitimately reachable
        // (exact host match, case-insensitive). Empty by default.
        'host_allowlist' => array_filter(
            explode(',', (string) env('SSRF_HOST_ALLOWLIST', '')),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime rate limits
    |--------------------------------------------------------------------------
    |
    | Per-surface throttles for the authenticated endpoints that execute
    | expensive work. Each limiter applies BOTH a per-user (identity) and a
    | per-org (paying-tenant) dimension; the most restrictive wins. The AI
    | surface adds a per-org-per-day quota — that is the cost ceiling, since
    | every builder turn is a paid Claude call.
    |
    | These are starting points; tune from real usage. They live here so they
    | can change per environment without a redeploy.
    |
    | STORE: the throttle middleware uses the cache (see RATE_LIMITER_STORE /
    | cache.default). In production this MUST be a shared, atomic store (Redis):
    | with multi-process PHP-FPM + Horizon workers, a file/array store counts
    | per process, so limits would not hold globally.
    |
    | FAIL-OPEN vs FAIL-CLOSED (decision, per surface):
    |   - runtime-actions, builder-workflow-run -> FAIL-OPEN if the limiter
    |     store is down (don't break the customer-facing product over a Redis
    |     hiccup); pair with a critical alert on store unavailability.
    |   - builder-ai -> store-down means uncapped spend, so this is the bucket
    |     to monitor hardest; alert at max severity. Default framework behaviour
    |     on a store error is fail-closed (request errors) — operationally we
    |     mitigate with Redis HA rather than silently letting AI spend run free.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sensitive-data redaction
    |--------------------------------------------------------------------------
    |
    | Single source of truth for what counts as a secret when sanitising stored
    | artifacts (integration_executions request/response, and — reusing the same
    | redactor — future audit surfaces). Consumed by CredentialRedactor and by
    | IntegrationService::maskAuthConfig so the list never drifts across places.
    |
    */

    'redaction' => [
        // Object/body/url-param key names (case-insensitive, also matched on
        // nested JSON keys and form fields).
        'sensitive_fields' => [
            'value', 'token', 'password', 'client_secret', 'access_token',
            'refresh_token', 'api_key', 'apikey', 'secret', 'authorization', 'key',
        ],

        // Exact header names whose value is redacted (case-insensitive).
        'sensitive_headers' => [
            'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
            'x-api-key', 'api-key', 'x-auth-token',
        ],

        // Any header name ending with one of these is also redacted.
        'sensitive_header_suffixes' => ['-key', '-token', '-secret', '-password'],
    ],

    'rate_limits' => [
        'runtime_actions' => [
            'per_user' => (int) env('RL_RUNTIME_ACTIONS_USER', 120),
            'per_org' => (int) env('RL_RUNTIME_ACTIONS_ORG', 600),
        ],
        'builder_workflow_run' => [
            'per_user' => (int) env('RL_BUILDER_WF_USER', 30),
            'per_org' => (int) env('RL_BUILDER_WF_ORG', 120),
        ],
        'builder_ai' => [
            'per_user' => (int) env('RL_BUILDER_AI_USER', 20),
            'per_org' => (int) env('RL_BUILDER_AI_ORG', 60),
            'per_org_daily' => (int) env('RL_BUILDER_AI_ORG_DAILY', 500),
            // Daily ceiling for accounts with no organization. Without this a
            // personal account would only have the per-minute burst cap and
            // could spend on Claude all day unbounded.
            'per_user_daily' => (int) env('RL_BUILDER_AI_USER_DAILY', 200),
        ],
        // MCP endpoint: one HTTP request per tool call. Generous per-minute caps
        // (tool listing + chained calls are normal); paid agent/builder calls are
        // additionally capped by the AI spend guard + budgets, not here.
        'mcp' => [
            'per_user' => (int) env('RL_MCP_USER', 120),
            'per_org' => (int) env('RL_MCP_ORG', 600),
        ],
    ],

];
