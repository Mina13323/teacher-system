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

        DB::transaction(function () use ($attempt, $staffUser, $result) {
            $attempt->score = $result['earned_points'];
            $attempt->percentage = $result['percentage'];
            $attempt->status = ExamAttemptStatus::Published->value;
            $attempt->grades_published_at = now();
            $attempt->graded_by = $staffUser->getKey();
            $attempt->save();

            // Notify student only upon publication
            if ($attempt->student) {
                $attempt->student->notify(new ResultAvailableNotification($attempt));
            }
        });

        return $attempt->fresh();
    }
}
