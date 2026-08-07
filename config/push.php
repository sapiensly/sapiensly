<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web Push (VAPID)
    |--------------------------------------------------------------------------
    |
    | The application server's identity, as every push service demands it. Both
    | keys are raw P-256, base64url — generate a pair with `php artisan
    | push:vapid` and paste them into the environment.
    |
    | The PUBLIC key is handed to every browser that subscribes, and a browser's
    | subscription is bound to it: rotating the pair invalidates every existing
    | subscription, so it is generated once and left alone.
    |
    | With no keys configured, notify.send's `push` channel reports that it
    | could not send rather than failing a workflow — an app whose owner has
    | not set this up is missing a feature, not broken.
    |
    */

    'vapid' => [
        'public' => env('VAPID_PUBLIC_KEY'),
        'private' => env('VAPID_PRIVATE_KEY'),

        // Who a push service should contact about a misbehaving sender. A
        // mailto: or an https: URL; required by RFC 8292.
        'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'soporte@sapiensly.com')),
    ],

    /*
    | How long to wait on a push service. Short on purpose: this runs inside a
    | workflow step that may have a dozen recipients, and a service having a bad
    | minute must not become the app having a bad minute.
    */
    'timeout' => (int) env('PUSH_TIMEOUT', 5),

];
