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
        // Every state check runs once, on the locked row. A duplicate submit
        // therefore sees the committed result of the first one, and a submit
        // racing an answer save or the expiry sweep is serialized by the lock.
        $result = DB::transaction(function () use ($attempt) {
            // Re-check under lock to avoid concurrent double submission.
            $locked = ExamAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->getKey());

            // Idempotent for ANY handed-in state (submitted, grading, published):
            // a duplicate submit must never re-grade or demote a published result.
            if ($this->isHandedIn($locked)) {
                return $locked;
            }

            if ($locked->status->isExpired()) {
                throw new InvalidAttemptStateException('This attempt has expired and cannot be submitted.');
            }

            if ($locked->isExpired()) {
                // Deadline passed while the attempt was still in progress:
                // finalize per the exam's expiry policy on this same lock. The
                // strict-policy refusal is thrown only after commit (below), so
                // it cannot roll the finalization back.
                return ['finalized' => $this->finalizeExpired->finalizeLocked($locked)];
            }

            if ($attempt->relationLoaded('student')) {
                $locked->setRelation('student', $attempt->student);
            }

            // The exam was loaded by isExpired() above.
            $locked->load(['attemptQuestions.attemptOptions', 'answers.selectedOptions']);
            $this->assertRequiredExplanations($locked);
            $locked->end_reason = 'submitted_by_student';

            return $this->gradeAttempt->execute($locked, relationsLoadedUnderLock: true);
        });

        if ($result instanceof ExamAttempt) {
            return $result;
        }

        $finalized = $result['finalized'];

        if ($finalized->status === ExamAttemptStatus::Expired) {
            throw new InvalidAttemptStateException('This attempt has expired and cannot be submitted.');
        }

        return $finalized;
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
