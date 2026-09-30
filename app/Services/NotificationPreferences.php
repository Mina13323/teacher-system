<?php

namespace App\Services;

use App\Models\User;

/**
 * Per-user notification preferences (users.notification_preferences JSON).
 *
 * Shape (all keys optional, missing = ON):
 *   exam_reminders | assignment_reminders | competition_reminders |
 *   result_notifications | quiet_hours: {start: "22:00", end: "07:00"}
 *
 * Quiet hours suppress SCHEDULED REMINDERS only (grade publications and other
 * mandatory academic records are never suppressed). A suppressed reminder is
 * retried on the next scheduler run until its window closes, so quiet hours
 * delay — they do not silently drop — time-sensitive reminders.
 */
class NotificationPreferences
{
    private const CATEGORY_KEYS = [
        'exam_reminders' => 'exam_reminders',
        'assignment_reminders' => 'assignment_reminders',
        'competition_reminders' => 'competition_reminders',
    ];

    /**
     * Whether this user accepts the given scheduled-reminder category and is
     * currently outside quiet hours.
     */
    public function allows(User $user, string $category): bool
    {
        $prefs = $user->notification_preferences ?? [];

        $key = self::CATEGORY_KEYS[$category] ?? $category;

        if (array_key_exists($key, $prefs) && ! $prefs[$key]) {
            return false;
        }

        return ! $this->inQuietHours($user);
    }

    public function inQuietHours(User $user, ?\DateTimeInterface $now = null): bool
    {
        $quiet = ($user->notification_preferences ?? [])['quiet_hours'] ?? null;

        if (! is_array($quiet) || empty($quiet['start']) || empty($quiet['end'])) {
            return false;
        }

        $now = $now ? \Carbon\Carbon::parse($now) : now();
        $start = \Carbon\Carbon::parse($quiet['start']);
        $end = \Carbon\Carbon::parse($quiet['end']);

        if ($start->lessThan($end)) {
            return $now->greaterThanOrEqualTo($start) && $now->lessThanOrEqualTo($end);
        }

        // Window wraps midnight (e.g. 22:00 -> 07:00).
        return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
    }
}
