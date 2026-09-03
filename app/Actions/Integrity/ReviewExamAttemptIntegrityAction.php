<?php

namespace App\Actions\Integrity;

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
    public function execute(User $reviewer, ExamAttempt $attempt, IntegrityReviewDecision $decision, ?string $note): ExamIntegrityReview
    {
        $status = $decision === IntegrityReviewDecision::Cleared
            ? IntegrityStatus::Cleared
            : IntegrityStatus::Flagged;

        $review = ExamIntegrityReview::create([
            'attempt_id' => $attempt->getKey(),
            'reviewed_by' => $reviewer->getKey(),
            'decision' => $decision->value,
            'note' => $note,
            'reviewed_at' => now(),
        ]);

        $attempt->integrity_status = $status;
        $attempt->save();

        return $review->load(['reviewer']);
    }
}
