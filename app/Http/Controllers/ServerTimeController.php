<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Database-free server clock for the exam screen.
 *
 * The exam client pings this between status checks to keep its connection
 * indicator fresh and to measure how far the device clock is from the
 * server's. It needs no authentication, no session and no database: it
 * reveals only the current time, and every deadline is still enforced on the
 * server from the attempt row. The route skips the throttle middleware
 * because, with the database cache store, the rate limiter itself would open
 * a MySQL connection.
 */
class ServerTimeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $now = now();

        return $this->success([
            'server_time' => $now->toISOString(),
            'server_time_ms' => (int) $now->getTimestampMs(),
        ], 'Server time.')->header('Cache-Control', 'no-store');
    }
}
