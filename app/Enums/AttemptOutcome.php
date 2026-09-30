<?php

namespace App\Enums;

/**
 * The official academic outcome of an attempt — ONE definition used by every
 * screen (student result, teacher attempt list, analytics, exports).
 *
 * Resolution order (see ExamAttempt::outcome()):
 *   1. EXPIRED        the attempt ran out of time under the 'expire' policy and
 *                     was never submitted/graded.
 *   2. DISQUALIFIED   integrity flagged AND a teacher confirmed the violation
 *                     (review decision FLAGGED).
 *   3. PENDING_REVIEW integrity flagged but not yet decided by a teacher, OR
 *                     grades not yet published (awaiting essay grading /
 *                     publication). A flagged attempt is NEVER shown as both
 *                     Passed and Failed on different screens.
 *   4. PASSED / FAILED decided on the RAW percentage against the FROZEN
 *                     pass threshold (rounded display values never decide).
 *
 * Legacy rows (raw_percentage NULL) compare their stored rounded `percentage`,
 * preserving historical results bit-for-bit.
 */
enum AttemptOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case PendingReview = 'pending_review';
    case Disqualified = 'disqualified';
    case Expired = 'expired';

    public function isDefinitive(): bool
    {
        return $this === self::Passed || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::PendingReview => 'Pending review',
            self::Disqualified => 'Disqualified',
            self::Expired => 'Expired',
        };
    }
}
