<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\DB;

class PublishExamGradesAction
{
    public function __construct(
        private readonly CalculateExamResultAction $calculateResult,
    ) {
    }

    public function execute(User $staffUser, ExamAttempt $attempt): ExamAttempt
    {
        $attempt->loadMissing(['student', 'answers', 'attemptQuestions.attemptOptions']);

        $result = $this->calculateResult->execute($attempt);

        $firstPublication = DB::transaction(function () use ($attempt, $staffUser, $result) {
            // Lock the row so two concurrent publish requests cannot both
            // believe they are the first publication.
            $locked = ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            $wasPublished = $locked->grades_published_at !== null;

            $locked->score = $result['earned_points'];
            $locked->percentage = $result['percentage'];
            $locked->status = ExamAttemptStatus::Published->value;
            // Preserve the original publication timestamp on re-publication so
            // repeated calls are idempotent and the audit trail is not rewritten.
            $locked->grades_published_at = $locked->grades_published_at ?? now();
            $locked->graded_by = $staffUser->getKey();
            $locked->save();

            $attempt->setRawAttributes($locked->getAttributes(), true);

            return ! $wasPublished;
        });

        // Notify only on the first publication, and only once the transaction
        // has committed so a rollback cannot announce grades that do not exist.
        if ($firstPublication && $attempt->student) {
            $attempt->student->notify(new ResultAvailableNotification($attempt));
        }

        return $attempt->fresh();
    }
}
