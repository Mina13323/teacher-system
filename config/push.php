<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web Push (VAPID)
    |--------------------------------------------------------------------------
    |
    | Web Push requires an application server key pair (RFC 8292 "VAPID").
    | Generate one with `php artisan push:vapid-keys` and store the values in
    | .env:
    |
    |   VAPID_PUBLIC_KEY=B...
    |   VAPID_PRIVATE_KEY=...
    |   VAPID_SUBJECT=mailto:admin@example.com
    |
    | When the private key is empty, the push channel is disabled and reminders
    | fall back to in-app database notifications only — the graceful fallback
    | required by the spec. No secrets are hardcoded here.
    |
    */

    'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
    'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
    'vapid_subject' => env('VAPID_SUBJECT', 'mailto:admin@example.com'),

    // Default TTL for reminder pushes (seconds).
    'ttl' => (int) env('PUSH_TTL', 24 * 60 * 60),

    // Hard cap per user so one browser storm cannot fan out unbounded pushes.
    'max_subscriptions_per_user' => (int) env('PUSH_MAX_SUBSCRIPTIONS', 10),
];
