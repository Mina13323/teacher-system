<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Actions\Integrity\UpdateAttemptIntegrityStatusAction;
use App\Enums\IntegrityEventType;
use App\Enums\IntegrityStatus;
use App\Models\ExamAttempt;
use App\Models\ExamIntegrityEvent;
use App\Services\Integrity\IntegrityRiskConfig;
use Illuminate\Support\Facades\DB;

/**
 * Terminates an in-progress attempt under the WARNING-THRESHOLD integrity
 * policy: the attempt is graded from its saved answers, flagged for teacher
 * review, and locked so the student cannot continue answering.
 *
 * Honesty rules (P0.5):
 *  - The recorded event is ALWAYS a THRESHOLD_TERMINATION with its risk points
 *    and severity taken from `config/integrity.php` — never a fabricated
 *    WINDOW_BLUR, never hardcoded points inside this action.
 *  - This action is only invoked once the server-side warning threshold has
 *    been exceeded (RecordIntegrityEventAction / the threshold-gated terminate
 *    endpoint). A network failure or heartbeat loss never reaches it.
 *  - Idempotent: an attempt that is no longer in progress is returned as-is.
 *
 * The saved answers are preserved and auto-graded exactly like a normal
 * submission (essay questions still await manual grading); publication follows
 * the exam's existing `show_result_immediately` policy.
 */
class TerminateExamAttemptAction
{
    public function __construct(
        private readonly GradeExamAttemptAction $gradeAttempt,
        private readonly UpdateAttemptIntegrityStatusAction $updateStatus,
        private readonly IntegrityRiskConfig $riskConfig,
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(ExamAttempt $attempt, string $reason = 'THRESHOLD_TERMINATION', array $metadata = []): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $reason, $metadata) {
            $locked = ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            if (! $locked->status->isInProgress()) {
                return $locked;
            }

            // One honest event: the threshold policy ended the attempt. The
            // triggering reason is preserved in metadata, not mislabeled as a
            // browser event that may never have happened.
            ExamIntegrityEvent::create([
                'attempt_id' => $locked->getKey(),
                'event_type' => IntegrityEventType::ThresholdTermination->value,
                'occurred_at' => now(),
                'metadata' => array_merge($metadata, [
                    'reason' => $reason,
                    'terminated' => true,
                ]),
                'severity' => $this->riskConfig->severity(IntegrityEventType::ThresholdTermination)->value,
                'risk_points' => $this->riskConfig->riskPoints(IntegrityEventType::ThresholdTermination),
            ]);

            $riskScore = (int) $locked->risk_score
                + $this->riskConfig->riskPoints(IntegrityEventType::ThresholdTermination);

            $this->updateStatus->execute(
                $locked,
                $riskScore,
                IntegrityStatus::Flagged
            );

            $locked->load(['attemptQuestions.attemptOptions', 'answers.selectedOptions', 'exam']);

            // Grade the saved answers; never discard them.
            $graded = $this->gradeAttempt->execute($locked);
            $graded->integrity_status = IntegrityStatus::Flagged;
            $graded->end_reason = 'integrity_threshold';
            $graded->save();

            $this->auditLog->execute('attempt.terminate', $graded, [
                'reason' => $reason,
                'end_reason' => 'integrity_threshold',
                'student_id' => $graded->student_id,
                'exam_id' => $graded->exam_id,
                'warning_count' => $graded->violation_warnings,
            ]);

            return $graded;
        });
    }
}
