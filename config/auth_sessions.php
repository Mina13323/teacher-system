<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Signed-in devices
    |--------------------------------------------------------------------------
    |
    | A sign-in is a Sanctum personal access token: a random secret the browser
    | keeps and the server stores only as a hash. It stays valid until the
    | user logs out, staff revoke it (password reset, suspension,
    | deactivation) or a confirmed sign-in on another device replaces it.
    |
    | single_device_roles: accounts with one of these roles may be signed in
    | on one device at a time. A correct password on a second device does not
    | sign the first one out: the second device is asked to confirm first,
    | and only that confirmation revokes the other sign-in. Comma separated;
    | empty turns the policy off. Existing sign-ins are never revoked by the
    | policy itself, only by a later confirmed transfer.
    |
    | transfer_ttl_seconds: how long that confirmation stays possible after
    | the password was checked.
    |
    | idle_timeout_days: a sign-in unused for this many days stops working
    | (the user signs in again). Null (the default) keeps sign-ins until they
    | are revoked, as before. last_used_at is refreshed at most every five
    | minutes, which is far finer than this setting.
    |
    | ended_notice_days: how long a revoked sign-in is answered with the
    | reason ("signed in on another device") instead of a bare 401.
    |
    */

    'single_device_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AUTH_SINGLE_DEVICE_ROLES', 'student'))
    ))),

    'transfer_ttl_seconds' => 300,

    'idle_timeout_days' => env('AUTH_IDLE_TIMEOUT_DAYS') !== null && env('AUTH_IDLE_TIMEOUT_DAYS') !== ''
        ? max(1, (int) env('AUTH_IDLE_TIMEOUT_DAYS'))
        : null,

    'ended_notice_days' => 30,

];
