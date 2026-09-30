<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of the append-only staff audit trail.
 *
 * Filters (all optional): action, actor_id, target_type, target_id,
 * from/to (created_at bounds). Newest first, paginated.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = AuditLog::query()->with('actor');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', (int) $request->input('actor_id'));
        }

        if ($request->filled('target_type')) {
            $query->where('target_type', $request->string('target_type')->toString());
        }

        if ($request->filled('target_id')) {
            $query->where('target_id', (int) $request->input('target_id'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request, 25));

        return $this->success(AuditLogResource::collection($logs), 'Audit trail retrieved.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Admin access required.');
    }
}
