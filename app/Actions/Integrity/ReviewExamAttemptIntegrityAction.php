<?php

namespace App\Actions\Integrity;

use App\Actions\Audit\RecordAuditLogAction;
use App\Actions\Exam\ResumeFlaggedAttemptAction;
use App\Enums\IntegrityReviewDecision;
use App\Enums\IntegrityStatus;
use App\Models\ExamAttempt;
use App\Models\ExamIntegrityReview;
use App\Models\User;

/**
 * Records a teacher review decision against an attempt's integrity evidence and
 * updates the attempt's integrity status accordingly.
 *
 * The review is an immutable, append-only audit record (historical reviews are
 * never overwritten). The decision is a human judgement layered on top of the
 * raw evidence; it does not mutate the stored event risk points.
 */
class ReviewExamAttemptIntegrityAction
{
    public function __construct(
        private readonly ResumeFlaggedAttemptAction $resumeAttempt,
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    public function execute(User $reviewer, ExamAttempt $attempt, IntegrityReviewDecision $decision, ?string $note): ExamIntegrityReview
    {
        // Append-only: every decision is recorded, none is ever overwritten.
        $review = ExamIntegrityReview::create([
            'attempt_id' => $attempt->getKey(),
            'reviewed_by' => $reviewer->getKey(),
            'decision' => $decision->value,
            'note' => $note,
            'reviewed_at' => now(),
        ]);

        if ($decision === IntegrityReviewDecision::Resume) {
            // §12: the SAME attempt continues; history stays immutable.
            $this->resumeAttempt->execute($reviewer, $attempt, $note);

            return $review->load(['reviewer']);
        }

        $status = $decision === IntegrityReviewDecision::Cleared
            ? IntegrityStatus::Cleared
            : IntegrityStatus::Flagged;

        $attempt->integrity_status = $status;
        $attempt->save();

        if ($decision === IntegrityReviewDecision::Flagged) {
            // Explicit teacher decision to keep the attempt terminated.
            $this->auditLog->execute('attempt.disqualify', $attempt, [
                'student_id' => (string) $attempt->student_id,
                'exam_id' => (string) $attempt->exam_id,
                'note' => (string) ($note ?? ''),
            ]);
        }

        return $review->load(['reviewer']);
    }
}
