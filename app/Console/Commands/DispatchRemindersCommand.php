<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\Competition;
use App\Models\Exam;
use App\Models\User;
use App\Notifications\ScheduledReminderNotification;
use App\Services\NotificationPreferences;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled in-app reminders (P2): exam opening/closing, assignment deadline,
 * competition ending. Runs hourly on the scheduler.
 *
 * Guarantees:
 *  - Idempotent per (recipient, subject, event): a `dedupe_key` is stored in
 *    each notification and checked before sending — a re-run or a slow loop
 *    can never double-notify.
 *  - Respects users.notification_preferences and quiet hours (a suppressed
 *    reminder is RETRIED next run until its window closes — quiet hours delay,
 *    they never silently drop).
 *  - Grade/result notifications are NOT handled here: those are event-driven
 *    (publication) and are delivered immediately regardless of quiet hours.
 */
class DispatchRemindersCommand extends Command
{
    protected $signature = 'reminders:dispatch {--dry-run : Report what would be sent without notifying}';

    protected $description = 'Dispatch scheduled exam/assignment/competition reminders (idempotent).';

    /** Reminder windows: how far ahead each event is announced. */
    private const EXAM_OPEN_WINDOW_HOURS = 24;

    private const EXAM_CLOSE_WINDOW_HOURS = 6;

    private const ASSIGNMENT_DUE_WINDOW_HOURS = 24;

    private const COMPETITION_END_WINDOW_HOURS = 6;

    private int $sent = 0;

    private int $skipped = 0;

    public function handle(NotificationPreferences $preferences): int
    {
        $this->dispatchExamOpenings($preferences);
        $this->dispatchExamClosings($preferences);
        $this->dispatchAssignmentDeadlines($preferences);
        $this->dispatchCompetitionEndings($preferences);

        $this->info("Reminders sent: {$this->sent}; skipped (deduped/quiet/preferences): {$this->skipped}.");

        return self::SUCCESS;
    }

    private function dispatchExamOpenings(NotificationPreferences $preferences): void
    {
        Exam::query()
            ->with('course')
            ->where('status', 'published')
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [now(), now()->addHours(self::EXAM_OPEN_WINDOW_HOURS)])
            ->each(function (Exam $exam) use ($preferences) {
                $this->notifyCourseStudents(
                    $exam->course_id,
                    $preferences,
                    'exam_reminders',
                    kind: 'exam_opening',
                    title: 'Exam opens soon',
                    message: "The exam \"{$exam->title}\" opens at {$exam->starts_at->format('Y-m-d H:i')}.",
                    subjectType: 'exam',
                    subjectId: $exam->getKey(),
                    dedupeKey: "exam_open:{$exam->getKey()}",
                    url: '/student/exams',
                );
            });
    }

    private function dispatchExamClosings(NotificationPreferences $preferences): void
    {
        Exam::query()
            ->with('course')
            ->where('status', 'published')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addHours(self::EXAM_CLOSE_WINDOW_HOURS)])
            ->each(function (Exam $exam) use ($preferences) {
                $this->notifyCourseStudents(
                    $exam->course_id,
                    $preferences,
                    'exam_reminders',
                    kind: 'exam_closing',
                    title: 'Exam closes soon',
                    message: "The exam \"{$exam->title}\" closes at {$exam->ends_at->format('Y-m-d H:i')}.",
                    subjectType: 'exam',
                    subjectId: $exam->getKey(),
                    dedupeKey: "exam_close:{$exam->getKey()}",
                    url: '/student/exams',
                );
            });
    }

    private function dispatchAssignmentDeadlines(NotificationPreferences $preferences): void
    {
        Assignment::query()
            ->where('is_published', true)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addHours(self::ASSIGNMENT_DUE_WINDOW_HOURS)])
            ->each(function (Assignment $assignment) use ($preferences) {
                $this->notifyCourseStudents(
                    $assignment->course_id,
                    $preferences,
                    'assignment_reminders',
                    kind: 'assignment_due',
                    title: 'Assignment due soon',
                    message: "The assignment \"{$assignment->title}\" is due at {$assignment->due_at->format('Y-m-d H:i')}.",
                    subjectType: 'assignment',
                    subjectId: $assignment->getKey(),
                    dedupeKey: "assignment_due:{$assignment->getKey()}",
                    url: '/student/assignments',
                );
            });
    }

    private function dispatchCompetitionEndings(NotificationPreferences $preferences): void
    {
        Competition::query()
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addHours(self::COMPETITION_END_WINDOW_HOURS)])
            ->each(function (Competition $competition) use ($preferences) {
                $studentIds = $competition->participants()->pluck('student_id');

                foreach ($studentIds as $studentId) {
                    $this->send(
                        User::query()->find($studentId),
                        $preferences,
                        'competition_reminders',
                        kind: 'competition_ending',
                        title: 'Competition ending soon',
                        message: "The competition \"{$competition->title}\" ends at {$competition->ends_at->format('Y-m-d H:i')}.",
                        subjectType: 'competition',
                        subjectId: $competition->getKey(),
                        dedupeKey: "competition_end:{$competition->getKey()}",
                        url: '/student/competitions',
                    );
                }
            });
    }

    /**
     * @param  string  $url
     */
    private function notifyCourseStudents(
        int $courseId,
        NotificationPreferences $preferences,
        string $category,
        string $kind,
        string $title,
        string $message,
        string $subjectType,
        int $subjectId,
        string $dedupeKey,
        ?string $url = null,
    ): void {
        $studentIds = DB::table('enrollments')
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->pluck('student_id');

        foreach ($studentIds as $studentId) {
            $this->send(
                User::query()->find($studentId),
                $preferences,
                $category,
                kind: $kind,
                title: $title,
                message: $message,
                subjectType: $subjectType,
                subjectId: $subjectId,
                dedupeKey: $dedupeKey,
                url: $url,
            );
        }
    }

    private function send(
        ?User $user,
        NotificationPreferences $preferences,
        string $category,
        string $kind,
        string $title,
        string $message,
        string $subjectType,
        int $subjectId,
        string $dedupeKey,
        ?string $url = null,
    ): void {
        if ($user === null || ! $user->isActive()) {
            return;
        }

        // Per-recipient dedupe: same event + same student = one notification.
        $personalKey = "{$dedupeKey}:u{$user->getKey()}";

        $already = DB::table('notifications')
            ->where('notifiable_id', $user->getKey())
            ->where('type', ScheduledReminderNotification::class)
            ->where('data', 'like', '%"dedupe_key":"'.$personalKey.'"%')
            ->exists();

        if ($already) {
            $this->skipped++;

            return;
        }

        if (! $preferences->allows($user, $category)) {
            // Quiet hours / opted out: retried on the next run (dedupe not set).
            $this->skipped++;

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$kind} -> user {$user->getKey()}");
            $this->sent++;

            return;
        }

        $user->notify(new ScheduledReminderNotification(
            kind: $kind,
            title: $title,
            message: $message,
            subjectType: $subjectType,
            subjectId: $subjectId,
            dedupeKey: $personalKey,
            url: $url,
        ));

        $this->sent++;
    }
}
