<?php

namespace App\Actions\Exam;

use App\Models\ExamAttempt;

/**
 * Transitions an in-progress attempt whose server-side deadline has passed to
 * its final state, according to the exam's expiry policy (auto-submit / expire
 * — see FinalizeExpiredAttemptAction).
 *
 * Heartbeat handling (P0.5 fairness rules):
 *  - A missed or late heartbeat (network drop, laptop sleep, app suspension,
 *    phone call) NEVER terminates an attempt, NEVER records an integrity event
 *    and NEVER adds risk. Heartbeats are a liveness/recovery signal only.
 *  - The only authority that can end an attempt is the deadline (this action /
 *    the scheduled job) or the warning-threshold integrity policy.
 */
class ExpireExamAttemptAction
{
    public function __construct(
        private readonly FinalizeExpiredAttemptAction $finalizeExpired,
    ) {
    }

    /**
     * @param  bool  $justRetrieved  The model was read from the database in this
     *                               request (route binding), so it is not re-read.
     */
    public function execute(ExamAttempt $attempt, bool $justRetrieved = false): ExamAttempt
    {
        $fresh = $justRetrieved ? $attempt : $attempt->fresh();

        if ($fresh !== null && $fresh->status->isInProgress() && $fresh->isExpired()) {
            return $this->finalizeExpired->execute($fresh);
        }

        return $fresh ?? $attempt;
    }
}
