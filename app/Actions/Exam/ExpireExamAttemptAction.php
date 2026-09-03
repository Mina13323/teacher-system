<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Models\ExamAttempt;

/**
 * Transitions an in-progress attempt to expired once its server-side deadline
 * has passed. The backend is the source of truth for timing.
 */
class ExpireExamAttemptAction
{
    public function execute(ExamAttempt $attempt): ExamAttempt
    {
        if ($attempt->status->isInProgress() && $attempt->isExpired()) {
            $attempt->status = ExamAttemptStatus::Expired->value;
            $attempt->active_key = null;
            $attempt->save();
        }

        return $attempt->fresh();
    }
}
