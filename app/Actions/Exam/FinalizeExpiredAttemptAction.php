<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Models\ExamAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Finalizes an in-progress attempt whose server-side deadline has passed,
 * according to the EXAM's expiry policy:
 *
 *   'auto_submit' (default) — the saved answers are submitted and graded; the
 *      student never loses work to a dead battery, network loss or closed lid.
 *      The submission time is the DEADLINE itself (expires_at), never the
 *      moment the job got around to processing it, so competition timing and
 *      any deadline-based rule stay honest. Essay answers are preserved for
 *      manual grading; publication follows `show_result_immediately`.
 *
 *   'expire' (legacy strict mode) — the attempt becomes `expired`, is never
 *      graded, and answers stay as historical record only.
 *
 * Idempotent: an attempt that is no longer in progress is returned untouched,
 * so running the process job twice can never double-grade, duplicate answers
 * or duplicate notifications.
 */
class FinalizeExpiredAttemptAction
{
    public function __construct(
        private readonly GradeExamAttemptAction $gradeAttempt,
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    public function execute(ExamAttempt $attempt): ExamAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $locked = ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            if (! $locked || ! $locked->status->isInProgress()) {
                return $attempt->fresh() ?? $attempt;
            }

            // Not yet due: leave it alone (a stale row race guard).
            if (! $locked->isExpired()) {
                return $locked;
            }

            $exam = $locked->exam;

            if ($exam === null || $exam->autoSubmitsAtDeadline()) {
                // Auto-submit: grade the saved answers as a deadline submission.
                $locked->submitted_at = $locked->submitted_at ?? $locked->expires_at ?? now();
                $locked->end_reason = 'auto_submit_at_deadline';
                $locked->save();

                $graded = $this->gradeAttempt->execute($locked->fresh());

                $auditData = [
                    'student_id' => $graded->student_id,
                    'exam_id' => $graded->exam_id,
                    'submitted_at' => $graded->submitted_at?->toISOString(),
                    'score' => $graded->score,
                ];

                DB::afterCommit(function () use ($graded, $auditData) {
                    $this->auditLog->execute('attempt.auto_submit', $graded, $auditData);
                });

                return $graded;
            }

            // Legacy strict policy: the attempt is discarded ungraded.
            $locked->status = ExamAttemptStatus::Expired->value;
            $locked->active_key = null;
            $locked->end_reason = 'expired';
            $locked->save();

            $auditData = [
                'student_id' => $locked->student_id,
                'exam_id' => $locked->exam_id,
                'policy' => 'expire',
            ];

            DB::afterCommit(function () use ($locked, $auditData) {
                $this->auditLog->execute('attempt.expire', $locked, $auditData);
            });

            return $locked->fresh();
        });
    }
}
