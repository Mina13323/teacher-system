<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use ApiResponse, AuthorizesRequests;

    /**
     * Upper bound on a client-requested page size.
     *
     * `per_page` arrives from the request, so leaving it uncapped lets any
     * caller ask for `?per_page=1000000` and force the server to hydrate an
     * entire table into memory in a single response. Every list endpoint must
     * resolve its page size through perPage() rather than reading the request
     * directly.
     */
    protected const MAX_PER_PAGE = 100;

    /**
     * Resolve a safe page size for a list endpoint.
     *
     * Anything below 1 falls back to the caller's default, since Laravel would
     * otherwise pass a zero or negative page size straight through.
     */
    protected function perPage(Request $request, int $default = 20): int
    {
        $requested = $request->integer('per_page', $default);

        if ($requested < 1) {
            return $default;
        }

        return min($requested, self::MAX_PER_PAGE);
    }
}
