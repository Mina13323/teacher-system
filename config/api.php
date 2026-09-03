<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Versioning
    |--------------------------------------------------------------------------
    |
    | The current API version. Routes are mounted under the "api" route group
    | with the "prefix" below, e.g. /api/v1/auth/register.
    |
    */

    'prefix' => 'v1',

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | The default rate limiter applied to the "api" middleware group. A higher
    | limit is provided for the unauthenticated public endpoints.
    |
    */

    'rate_limit' => [
        'default' => 120,
        'auth' => 10,
    ],

];
