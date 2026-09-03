<?php

namespace App\Actions\Integrity;

use App\Enums\IntegrityStatus;
use App\Models\ExamAttempt;

/**
 * Persists the server-computed risk score and automatic integrity status onto an
 * attempt. These values are never writable from client input.
 *
 * A teacher's human review decision (CLEARED / FLAGGED) is preserved: once an
 * attempt has been reviewed, the automatic calculation does not overwrite the
 * status, though the risk score continues to reflect new evidence.
 */
class UpdateAttemptIntegrityStatusAction
{
    public function execute(ExamAttempt $attempt, int $riskScore, IntegrityStatus $status): ExamAttempt
    {
        $attempt->risk_score = $riskScore;

        // Preserve the teacher's human decision once a review has occurred.
        if (! in_array($attempt->integrity_status?->value, [
            IntegrityStatus::Reviewed->value,
            IntegrityStatus::Cleared->value,
        ], true)) {
            $attempt->integrity_status = $status;
        }

        $attempt->save();

        return $attempt->fresh();
    }
}
