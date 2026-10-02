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
    'connect_timeout' => max(1, (int) env('PUSH_CONNECT_TIMEOUT', 3)),
    'timeout' => max(1, (int) env('PUSH_TIMEOUT', 10)),

    // Maximum stored endpoints per account. The controller clamps this to a
    // safe positive range even if a deployment sets an invalid value.
    'max_subscriptions_per_user' => (int) env('PUSH_MAX_SUBSCRIPTIONS', 10),

    /*
    |--------------------------------------------------------------------------
    | Allowed browser push-service endpoint hosts
    |--------------------------------------------------------------------------
    |
    | The browser controls a subscription endpoint, and the server later makes
    | an authenticated outbound request to it. Keep this allowlist narrow to
    | prevent SSRF. Add provider hosts explicitly per deployment, comma-separated.
    | Wildcards use a leading `*.` and match subdomains only.
    |
    */
    'allowed_endpoint_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env(
            'PUSH_ALLOWED_ENDPOINT_HOSTS',
            'fcm.googleapis.com,android.googleapis.com,updates.push.services.mozilla.com,*.push.services.mozilla.com,web.push.apple.com,push.opera.com,*.notify.windows.com'
        ))
    ))),
];
