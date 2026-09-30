<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Observability\MetricsService;
use Illuminate\Http\JsonResponse;

/**
 * PHASE 5 §40-41 — Operational metrics endpoint (admin only).
 */
class MetricsController extends Controller
{
    public function __invoke(MetricsService $metrics): JsonResponse
    {
        abort_unless(request()->user()?->hasRole('admin'), 403);

        return $this->success($metrics->snapshot(), 'Metrics snapshot.');
    }
}
