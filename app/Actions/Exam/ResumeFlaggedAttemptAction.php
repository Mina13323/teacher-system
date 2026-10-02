<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use App\Models\ExamAttempt;
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
 * Timer policy (§9 + §12): the persisted `expires_at` is the hard, original
 * deadline. Resume is allowed only while that deadline is still in the future;
 * it is never moved forward or reconstructed from the termination time. A
 * late review cannot reopen an expired attempt or restore time.
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

            $previousExpiresAt = $locked->expires_at?->copy();
            if ($previousExpiresAt === null || ! $previousExpiresAt->isFuture()) {
                throw new \DomainException('The attempt deadline has passed; this attempt cannot be resumed.');
            }

            // Keep the original server-authoritative deadline byte-for-byte.
            // The teacher review time and termination-event timestamp never
            // create extra exam time.
            $timeRestored = 0;

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
