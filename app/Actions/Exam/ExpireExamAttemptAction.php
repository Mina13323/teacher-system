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
    public function __construct(
        private readonly TerminateExamAttemptAction $terminateAttempt,
    ) {
    }

    public function execute(ExamAttempt $attempt): ExamAttempt
    {
        if ($attempt->status->isInProgress()) {
            if ($attempt->isExpired()) {
                $attempt->status = ExamAttemptStatus::Expired->value;
                $attempt->active_key = null;
                $attempt->save();

                return $attempt->fresh();
            }

            // If exam requires termination on violation, check heartbeat liveness.
            $attempt->loadMissing('integritySetting');
            $terminateOnViolation = (bool) ($attempt->integritySetting?->terminate_on_violation ?? config('integrity.defaults.terminate_on_violation', true));

            if ($terminateOnViolation && $attempt->last_heartbeat_at !== null) {
                $timeoutSeconds = (int) config('integrity.heartbeat_timeout_seconds', 60);
                if ($attempt->last_heartbeat_at->diffInSeconds(now()) > $timeoutSeconds) {
                    return $this->terminateAttempt->execute($attempt, 'HEARTBEAT_TIMEOUT', [
                        'last_heartbeat_at' => $attempt->last_heartbeat_at->toISOString(),
                        'timeout_seconds' => $timeoutSeconds,
                    ]);
                }
            }
        }

        return $attempt->fresh();
    }
}
