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

];
