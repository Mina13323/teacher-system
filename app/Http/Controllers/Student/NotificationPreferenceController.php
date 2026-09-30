<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user notification preferences (P2 in-app delivery control).
 *
 * GET returns the effective preferences (defaults filled in); PUT merges the
 * allowed keys. Quiet hours only delay SCHEDULED reminders — grade
 * publications and other academic records always arrive.
 */
class NotificationPreferenceController extends Controller
{
    private const DEFAULTS = [
        'exam_reminders' => true,
        'assignment_reminders' => true,
        'competition_reminders' => true,
        'result_notifications' => true,
    ];

    public function show(Request $request): JsonResponse
    {
        return $this->success($this->effective($request), 'Notification preferences retrieved.');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_reminders' => ['sometimes', 'boolean'],
            'assignment_reminders' => ['sometimes', 'boolean'],
            'competition_reminders' => ['sometimes', 'boolean'],
            'result_notifications' => ['sometimes', 'boolean'],
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i'],
        ]);

        $user = $request->user();
        $merged = $user->notification_preferences ?? [];

        foreach (['exam_reminders', 'assignment_reminders', 'competition_reminders', 'result_notifications'] as $key) {
            if (array_key_exists($key, $data)) {
                $merged[$key] = (bool) $data[$key];
            }
        }

        if (array_key_exists('quiet_hours', $data)) {
            $merged['quiet_hours'] = $data['quiet_hours']; // null clears
        }

        $user->forceFill(['notification_preferences' => $merged])->save();

        return $this->success($this->effective($request), 'Notification preferences updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function effective(Request $request): array
    {
        $prefs = $request->user()->notification_preferences ?? [];

        return [
            'exam_reminders' => (bool) ($prefs['exam_reminders'] ?? self::DEFAULTS['exam_reminders']),
            'assignment_reminders' => (bool) ($prefs['assignment_reminders'] ?? self::DEFAULTS['assignment_reminders']),
            'competition_reminders' => (bool) ($prefs['competition_reminders'] ?? self::DEFAULTS['competition_reminders']),
            'result_notifications' => (bool) ($prefs['result_notifications'] ?? self::DEFAULTS['result_notifications']),
            'quiet_hours' => $prefs['quiet_hours'] ?? null,
        ];
    }
}
