<?php

namespace App\Actions\Exam;

use App\Actions\Integrity\UpdateAttemptIntegrityStatusAction;
use App\Enums\IntegrityEventType;
use App\Enums\IntegritySeverity;
use App\Enums\IntegrityStatus;
use App\Models\ExamAttempt;
use App\Models\ExamIntegrityEvent;
use Illuminate\Support\Facades\DB;

class TerminateExamAttemptAction
{
    public function __construct(
        private readonly GradeExamAttemptAction $gradeAttempt,
        private readonly UpdateAttemptIntegrityStatusAction $updateStatus,
    ) {
    }

    /**
     * Terminate an in-progress exam attempt due to policy or integrity violation.
     * Grades current answers, marks attempt as submitted/grading, flags integrity status,
     * and releases the active lock key so the student cannot continue answering.
     */
    public function execute(ExamAttempt $attempt, string $reason = 'TERMINATED_BY_INTEGRITY', array $metadata = []): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $reason, $metadata) {
            $locked = ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            if (! $locked->status->isInProgress()) {
                return $locked;
            }

            $eventType = match ($reason) {
                'TAB_SWITCH' => IntegrityEventType::TabSwitch,
                'FULLSCREEN_EXIT' => IntegrityEventType::FullscreenExit,
                default => IntegrityEventType::WindowBlur,
            };

            ExamIntegrityEvent::create([
                'attempt_id' => $locked->getKey(),
                'event_type' => $eventType->value,
                'occurred_at' => now(),
                'metadata' => array_merge($metadata, [
                    'reason' => $reason,
                    'terminated' => true,
                ]),
                'severity' => IntegritySeverity::High->value,
                'risk_points' => 10,
            ]);

            $this->updateStatus->execute(
                $locked,
                max(10, (int) $locked->risk_score + 10),
                IntegrityStatus::Flagged
            );

            $locked->load(['attemptQuestions.attemptOptions', 'answers', 'exam']);

            $graded = $this->gradeAttempt->execute($locked);
            $graded->integrity_status = IntegrityStatus::Flagged;
            $graded->save();

            return $graded;
        });
    }
}
