<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Submits an attempt. Grading is performed server-side against the frozen
 * snapshot; the client never supplies score/percentage/passed.
 *
 * Idempotent: submitting an already-submitted attempt returns the existing
 * result without re-grading.
 *
 * Deadline behavior (per exam policy):
 *  - 'auto_submit' — a submit that arrives after the deadline (or is processed
 *    late) finalizes exactly like the scheduled auto-submit: the saved answers
 *    are graded with the deadline as the submission time. The student never
 *    loses work to a slow request.
 *  - 'expire' (legacy strict mode) — the attempt becomes `expired` and cannot
 *    be submitted (historical behavior preserved as an opt-in policy).
 */
class SubmitExamAttemptAction
{
    public function __construct(
        private readonly GradeExamAttemptAction $gradeAttempt,
        private readonly FinalizeExpiredAttemptAction $finalizeExpired,
    ) {
    }

    public function execute(ExamAttempt $attempt): ExamAttempt
    {
        $fresh = $attempt->fresh();

        // Idempotent for ANY handed-in state (submitted, grading, published):
        // a duplicate submit must never re-grade or demote a published result.
        if ($this->isHandedIn($fresh)) {
            return $fresh;
        }

        if ($fresh->status->isExpired()) {
            throw new InvalidAttemptStateException('This attempt has expired and cannot be submitted.');
        }

        if ($fresh->isExpired()) {
            // Deadline passed while the attempt was still in progress:
            // finalize per the exam's documented expiry policy.
            $finalized = $this->finalizeExpired->execute($fresh);

            if ($finalized->status === ExamAttemptStatus::Expired) {
                throw new InvalidAttemptStateException('This attempt has expired and cannot be submitted.');
            }

            return $finalized;
        }

        return DB::transaction(function () use ($fresh) {
            // Re-check under lock to avoid concurrent double submission.
            $locked = ExamAttempt::query()
                ->lockForUpdate()
                ->find($fresh->getKey());

            if ($this->isHandedIn($locked)) {
                return $locked;
            }

            if ($locked->status->isExpired()) {
                throw new InvalidAttemptStateException('This attempt has expired and cannot be submitted.');
            }

            if ($locked->isExpired()) {
                $finalized = $this->finalizeExpired->execute($locked);

                if ($finalized->status === ExamAttemptStatus::Expired) {
                    throw new InvalidAttemptStateException('This attempt has expired and cannot be submitted.');
                }

                return $finalized;
            }

            $locked->load(['attemptQuestions.attemptOptions', 'answers.selectedOptions', 'exam']);
            $this->assertRequiredExplanations($locked);
            $locked->end_reason = 'submitted_by_student';

            $graded = $this->gradeAttempt->execute($locked);

            return $graded;
        });
    }

    private function assertRequiredExplanations(ExamAttempt $attempt): void
    {
        $answersByQuestion = $attempt->answers->keyBy('question_id');

        foreach ($attempt->attemptQuestions as $attemptQuestion) {
            if (
                ! $attemptQuestion->explanation_enabled
                || ! $attemptQuestion->explanation_required
                || $attemptQuestion->question_type === 'essay'
            ) {
                continue;
            }

            $answer = $answersByQuestion->get($attemptQuestion->question_id);
            if (! $answer || ($answer->selectedOptions->isEmpty() && $answer->option_id === null)) {
                // An unanswered MCQ does not require an explanation.
                continue;
            }

            if (trim((string) $answer->explanation) === '') {
                throw ValidationException::withMessages([
                    'answers.'.$attemptQuestion->question_id.'.explanation' => [
                        'Please provide an explanation for your selected answer before submitting.',
                    ],
                ]);
            }
        }
    }

    /**
     * Whether the attempt has already been handed in (submitted, awaiting
     * essay grading, or published) — the submitted-values set from the state
     * machine. In-progress and expired are not handed in.
     */
    private function isHandedIn(ExamAttempt $attempt): bool
    {
        return in_array($attempt->status?->value, ExamAttemptStatus::submittedValues(), true);
    }
}
