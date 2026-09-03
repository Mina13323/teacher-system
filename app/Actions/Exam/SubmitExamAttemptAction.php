<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Submits an attempt. Grading is performed server-side against the frozen
 * snapshot; the client never supplies score/percentage/passed.
 *
 * Idempotent: submitting an already-submitted attempt returns the existing
 * result without re-grading.
 */
class SubmitExamAttemptAction
{
    public function __construct(
        private readonly GradeExamAttemptAction $gradeAttempt,
    ) {
    }

    public function execute(ExamAttempt $attempt): ExamAttempt
    {
        $fresh = $attempt->fresh();

        if ($fresh->status->isSubmitted()) {
            return $fresh;
        }

        if ($fresh->status->isExpired() || $fresh->isExpired()) {
            $fresh->status = ExamAttemptStatus::Expired->value;
            $fresh->active_key = null;
            $fresh->save();

            throw new InvalidAttemptStateException('This attempt has expired and cannot be submitted.');
        }

        return DB::transaction(function () use ($fresh) {
            // Re-check under lock to avoid concurrent double submission.
            $locked = ExamAttempt::query()
                ->lockForUpdate()
                ->find($fresh->getKey());

            if ($locked->status->isSubmitted()) {
                return $locked;
            }

            if ($locked->status->isExpired() || $locked->isExpired()) {
                throw new InvalidAttemptStateException('This attempt has expired and cannot be submitted.');
            }

            $locked->load(['attemptQuestions.attemptOptions', 'answers', 'exam']);

            return $this->gradeAttempt->execute($locked);
        });
    }
}
