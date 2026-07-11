<?php

return [

    /*
    |--------------------------------------------------------------------------
    | L4 Dashboard Express
    |--------------------------------------------------------------------------
    | The deterministic dashboard pipeline: PHP owns the flow, models answer
    | bounded questions at gates. `enabled` gates the explicit entry points
    | (endpoint + button); `autoroute` additionally routes dashboard-intent
    | chat messages into the pipeline (rollout phase 2).
    */

    'enabled' => env('DASHBOARD_EXPRESS_ENABLED', false),

    'autoroute' => env('DASHBOARD_EXPRESS_AUTOROUTE', false),

    /*
    | Plumbing model (option B): runs invisible gates — intent routing and the
    | adversarial verifier — where latency matters and the user's voice doesn't.
    | Null = use the user's own model everywhere. Never a model the tenant has
    | not enabled.
    */
    'plumbing_model' => env('DASHBOARD_EXPRESS_PLUMBING_MODEL'),

    /*
    | Models treated as slow/weak instruction-followers: the spec-overrides
    | gate runs best-of-2 with a deterministic judge for these (substring
    | match on the resolved model id).
    */
    'slow_models' => ['deepseek', 'glm', 'qwen', 'mistral'],

    /*
    | Economy mode: skip the model gates entirely when the DETERMINISTIC fit
    | is unambiguous — every discriminating topic word of the ask matches the
    | tool catalog and the on-topic tool set is small enough to take whole.
    | A laser-specific ask ("tendencia semanal del nps") then builds in
    | seconds for $0 (A/B observed: near-identical output either way — the
    | deterministic floor caught up with the gates). The gates still run
    | whenever the ask is compound, ambiguous, or possibly unanswerable —
    | the cases where model judgment (and the honest halt) earn their cost.
    */
    'economy' => env('DASHBOARD_EXPRESS_ECONOMY', false),

    /*
    | The spec-overrides gate (G-2a): a model pass that may refine the
    | deterministic suggestion. Retired by default — once the fidelity floor
    | shipped, four consecutive prod builds saw every candidate rejected
    | («pierde forma», «label sin sustento») at ~$0.012 + 5-9s per build,
    | while voice_insights and the grounded verifier cover its upside.
    */
    'spec_overrides' => env('DASHBOARD_EXPRESS_SPEC_OVERRIDES', false),

    /*
    | Short-TTL tenant cache over connected (MCP) reads: filter toggles,
    | drills and reloads within the window serve from Redis instead of
    | re-reading every live source. 0 disables. Keys carry the resolved
    | arguments and acting user; TenantCache scopes them per tenant.
    */
    'connected_cache_ttl' => (int) env('DASHBOARD_EXPRESS_CONNECTED_CACHE_TTL', 90),

];
