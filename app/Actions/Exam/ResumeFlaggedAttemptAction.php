<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use App\Models\ExamAttempt;
use App\Models\ExamIntegrityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 1 §12 — Resume an integrity-terminated attempt (teacher override).
 *
 * The SAME attempt continues — never a new attempt. Preserved immutably:
 * attempt_id, snapshot, answers, warning history, integrity events, previous
 * score, and the prior termination reason (copied to `previous_end_reason`).
 * Preserved in the append-only audit trail:
 *   Actor / action=attempt.resume_flagged / attempt / timestamp / note.
 *
 * Timer policy (§9 + §12): the server clock stays authoritative. If the
 * original `expires_at` is still in the future it is kept untouched. If the
 * deadline already passed, the student gets back exactly the time that
 * remained when the threshold terminated them (termination time is the
 * immutable ThresholdTermination event); `time_restored_seconds` and
 * `previous_expires_at` record the math.
 *
 * Guard: only attempts terminated for `integrity_threshold` (not yet
 * grade-published) may be resumed. Grading state is recomputed on the next
 * submit — the stored score rows are never destroyed here.
 */
class ResumeFlaggedAttemptAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    /**
     * @return array{attempt: ExamAttempt, time_restored_seconds: int}
     */
    public function execute(User $actor, ExamAttempt $attempt, ?string $note = null): array
    {
        $result = DB::transaction(function () use ($attempt, $actor, $note) {
            $locked = ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            if ($locked->previous_end_reason !== null && $locked->status->isInProgress()) {
                // Idempotent: already resumed — return the live state.
                return [$locked, (int) ($locked->time_restored_seconds ?? 0), false];
            }

            if ($locked->end_reason !== 'integrity_threshold') {
                throw new \DomainException('Only integrity-terminated attempts can be resumed.');
            }
            if ($locked->grades_published_at !== null) {
                throw new \DomainException('Published attempts cannot be resumed.');
            }

            $termination = ExamIntegrityEvent::query()
                ->where('attempt_id', $locked->getKey())
                ->where('event_type', \App\Enums\IntegrityEventType::ThresholdTermination->value)
                ->orderByDesc('occurred_at')
                ->first();
            $terminatedAt = $termination?->occurred_at ?? $locked->updated_at ?? now();

            $previousExpiresAt = $locked->expires_at?->copy();
            $timeRestored = 0;
            if ($previousExpiresAt !== null && $previousExpiresAt->isFuture()) {
                // Deadline untouched — the student simply keeps the remainder.
            } elseif ($previousExpiresAt !== null) {
                $remaining = (int) max(0, $previousExpiresAt->getTimestamp() - $terminatedAt->getTimestamp());
                $timeRestored = $remaining;
                $locked->expires_at = now()->addSeconds($remaining);
            }

            $locked->previous_end_reason = $locked->end_reason;
            $locked->previous_expires_at = $previousExpiresAt;
            $locked->time_restored_seconds = $timeRestored;
            $locked->resumed_at = now();
            $locked->resumed_by = $actor->getKey();
            $locked->resume_note = $note;
            $locked->end_reason = null;
            $locked->status = ExamAttemptStatus::InProgress;
            $locked->integrity_status = IntegrityStatus::Reviewed;
            $locked->active_key = $locked->student_id.':'.$locked->exam_id;
            $locked->submitted_at = null;
            $locked->save();

            return [$locked, $timeRestored, true];
        });

        [$attempt, $timeRestored, $changed] = $result;

        if ($changed) {
            // Events, warnings and prior review rows stay untouched (§13).
            $this->auditLog->execute('attempt.resume_flagged', $attempt, [
                'student_id' => (string) $attempt->student_id,
                'exam_id' => (string) $attempt->exam_id,
                'previous_end_reason' => (string) ($attempt->previous_end_reason ?? 'integrity_threshold'),
                'time_restored_seconds' => (string) $timeRestored,
                'note' => (string) ($note ?? ''),
            ]);
        }

        return ['attempt' => $attempt, 'time_restored_seconds' => $timeRestored];
    }
}
