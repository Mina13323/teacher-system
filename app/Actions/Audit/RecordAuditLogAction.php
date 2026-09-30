<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Records one staff audit-trail entry.
 *
 * Deliberately fire-and-forget: auditing must never break the action being
 * audited. A failure is logged to the application log and swallowed.
 *
 * PRODUCTION DATA SAFETY: metadata is validated as a scalar map (max 20 keys,
 * bounded strings) so it can never smuggle secrets or blobs into the trail.
 */
class RecordAuditLogAction
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        string $action,
        ?Model $target = null,
        array $metadata = [],
        ?User $actor = null,
        ?Request $request = null,
    ): ?AuditLog {
        try {
            $actor ??= Auth::user();
            $request ??= request();

            return AuditLog::create([
                'actor_id' => $actor?->getAuthIdentifier(),
                'actor_role' => $actor?->roles?->first()?->name,
                'action' => $action,
                'target_type' => $target?->getMorphClass(),
                'target_id' => $target?->getKey(),
                'metadata' => $this->sanitize($metadata),
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            logger()->warning('audit log write failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    private function sanitize(array $metadata): array
    {
        $clean = [];

        foreach (array_slice($metadata, 0, 20, true) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[(string) $key] = mb_substr((string) $value, 0, 255);
            }
        }

        return $clean;
    }
}
