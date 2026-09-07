<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Analytics\BuildAdminOverviewAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only system overview.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly BuildAdminOverviewAction $buildOverview)
    {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Admin access required.');

        return $this->success($this->buildOverview->execute(), 'System overview retrieved.');
    }
}
