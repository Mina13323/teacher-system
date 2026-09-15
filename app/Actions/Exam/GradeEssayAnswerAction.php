<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GradeEssayAnswerAction
{
    public function __construct(
        private readonly CalculateExamResultAction $calculateResult,
    ) {
    }

    public function execute(
        User $staffUser,
        ExamAttempt $attempt,
        int $questionId,
        int $awardedPoints,
        ?string $feedback = null
    ): ExamAttempt {
        $attemptQuestion = $attempt->attemptQuestions()
            ->where('question_id', $questionId)
            ->first();

        if (! $attemptQuestion) {
            throw new InvalidAttemptStateException('Question does not belong to this attempt.');
        }

        // The frozen snapshot is authoritative here, not the live Question
        // model: only a snapshot essay question may enter the manual grading
        // path. This stops staff (or a replayed request) from overwriting an
        // auto-graded MCQ's correctness and awarded points.
        //
        // A NULL question_type is deliberately rejected rather than treated as
        // an essay. Legacy snapshot rows written before migration
        // 2026_01_01_000801 have NULL here, but those attempts can only contain
        // MCQs: the Essay case was added to App\Enums\QuestionType in the very
        // same commit that added both that column and this grading action, so
        // no pre-existing attempt can ever be awaiting manual essay grading.
        // Do not add a lenient fallback to the live Question here.
        if ($attemptQuestion->question_type !== QuestionType::Essay->value) {
            throw new InvalidAttemptStateException('Only essay questions can be graded manually.');
        }

        if ($awardedPoints < 0 || $awardedPoints > $attemptQuestion->points) {
            throw new InvalidAttemptStateException("Awarded points must be between 0 and {$attemptQuestion->points}.");
        }

        DB::transaction(function () use ($attempt, $attemptQuestion, $staffUser, $questionId, $awardedPoints, $feedback) {
            // Lock the attempt so two concurrent grading requests cannot both
            // recompute the total from a stale set of answers.
            ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            $answer = ExamAnswer::firstOrCreate(
                [
                    'attempt_id' => $attempt->getKey(),
                    'question_id' => $questionId,
                ],
                [
                    'answered_at' => now(),
                ]
            );

            $answer->points_earned = $awardedPoints;
            $answer->is_correct = $awardedPoints === $attemptQuestion->points;
            $answer->feedback = $feedback;
            $answer->graded_by = $staffUser->getKey();
            $answer->graded_at = now();
            $answer->save();

            // Recompute from the freshly persisted answers.
            $attempt->unsetRelation('answers');
            $result = $this->calculateResult->execute($attempt);

            $attempt->score = $result['earned_points'];
            $attempt->percentage = $result['percentage'];
            $attempt->graded_by = $staffUser->getKey();

            if ($attempt->status->isSubmitted() && $result['requires_manual_grading']) {
                $attempt->status = ExamAttemptStatus::Grading->value;
            }

            $attempt->save();
        });

        return $attempt->fresh(['answers', 'attemptQuestions']);
    }
}
