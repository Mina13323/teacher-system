<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\User;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicitly applies a corrected live MCQ answer key to handed-in attempts.
 *
 * Ordinary edits remain future-only: their frozen attempt snapshots are not
 * touched. This action is the deliberate, audited exception requested when a
 * teacher has found an incorrect key. In-progress and strictly-expired
 * attempts are never changed; their deadlines and saved work stay intact.
 */
class RegradeQuestionAttemptsAction
{
    public function __construct(
        private readonly CalculateExamResultAction $calculateResult,
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    /**
     * @return array{
     *     attempts_regraded: int,
     *     scores_changed: int,
     *     published_scores_changed: int
     * }
     */
    public function execute(User $staffUser, Question $question, ?string $reason = null): array
    {
        $questionId = (int) $question->getKey();

        $work = DB::transaction(function () use ($questionId) {
            $lockedQuestion = Question::query()->lockForUpdate()->findOrFail($questionId);

            if (! $lockedQuestion->isMcq()) {
                throw ValidationException::withMessages([
                    'question' => ['Only multiple-choice questions can be regraded automatically.'],
                ]);
            }

            $liveOptions = $lockedQuestion->options()->lockForUpdate()->get();
            $liveType = $lockedQuestion->type?->value ?? QuestionType::SingleChoice->value;
            $correctOptionIds = $liveOptions
                ->filter(fn ($option) => (bool) $option->is_correct)
                ->map(fn ($option) => (int) $option->getKey())
                ->sort()
                ->values()
                ->all();

            $hasValidKey = $liveType === QuestionType::SingleChoice->value
                ? count($correctOptionIds) === 1
                : count($correctOptionIds) >= 2;

            if (! $hasValidKey) {
                throw ValidationException::withMessages([
                    'correct_option_ids' => [
                        $liveType === QuestionType::SingleChoice->value
                            ? 'Select exactly one correct option before regrading.'
                            : 'Select at least two correct options before regrading.',
                    ],
                ]);
            }

            $attemptsRegraded = 0;
            $scoresChanged = 0;
            $publishedScoresChanged = 0;
            $scoreChanges = [];
            $publishedAttemptIds = [];

            ExamAttempt::query()
                ->where('exam_id', $lockedQuestion->exam_id)
                ->whereIn('status', ExamAttemptStatus::submittedValues())
                ->whereHas('attemptQuestions', fn ($query) => $query->where('question_id', $questionId))
                ->with(['attemptQuestions.attemptOptions', 'answers.selectedOptions'])
                ->orderBy('id')
                ->lockForUpdate()
                ->chunkById(50, function ($attempts) use (
                    $questionId,
                    $liveType,
                    $correctOptionIds,
                    &$attemptsRegraded,
                    &$scoresChanged,
                    &$publishedScoresChanged,
                    &$scoreChanges,
                    &$publishedAttemptIds
                ): void {
                    foreach ($attempts as $attempt) {
                        $attemptQuestion = $attempt->attemptQuestions
                            ->firstWhere('question_id', $questionId);

                        if (! $attemptQuestion) {
                            continue;
                        }

                        // Do not reinterpret historical questions whose type or
                        // option set changed after students took them. A regrade
                        // is safe only when the corrected key existed in each
                        // submitted attempt's original snapshot.
                        $snapshotType = $attemptQuestion->question_type
                            ?? QuestionType::SingleChoice->value;
                        $snapshotOptionIds = $attemptQuestion->attemptOptions
                            ->pluck('option_id')
                            ->map(fn ($id) => (int) $id)
                            ->all();

                        if (
                            $snapshotType !== $liveType
                            || array_diff($correctOptionIds, $snapshotOptionIds) !== []
                        ) {
                            throw ValidationException::withMessages([
                                'correct_option_ids' => [
                                    'The corrected answer key cannot be applied because this question changed after at least one submitted attempt began.',
                                ],
                            ]);
                        }

                        foreach ($attemptQuestion->attemptOptions as $snapshotOption) {
                            $isCorrect = in_array((int) $snapshotOption->option_id, $correctOptionIds, true);

                            if ((bool) $snapshotOption->is_correct !== $isCorrect) {
                                $snapshotOption->is_correct = $isCorrect;
                                $snapshotOption->save();
                            }
                        }

                        $answer = $attempt->answers
                            ->firstWhere('question_id', $questionId);

                        if (! $answer) {
                            // Handed-in MCQ attempts normally already have a
                            // zero-grade row for unanswered questions. Repair a
                            // legacy missing row without changing the response.
                            $answer = ExamAnswer::create([
                                'attempt_id' => $attempt->getKey(),
                                'question_id' => $questionId,
                                'is_correct' => false,
                                'points_earned' => 0,
                            ]);
                            $attempt->unsetRelation('answers');
                        }

                        $answer->is_correct = $this->calculateResult
                            ->isChoiceAnswerCorrect($attemptQuestion, $answer);
                        $answer->points_earned = $answer->is_correct
                            ? $attemptQuestion->points
                            : 0;
                        $answer->save();

                        $oldScore = $attempt->score;
                        $oldPercentage = $attempt->percentage;
                        $oldRawPercentage = $attempt->raw_percentage;
                        $wasPublished = $attempt->status === ExamAttemptStatus::Published
                            || $attempt->grades_published_at !== null;
                        $oldStatus = $attempt->status?->value;

                        // Calculate the full result again so essay points, the
                        // frozen pass threshold, and exact multi-select rules
                        // remain part of the total.
                        $attempt->unsetRelation('answers');
                        $result = $this->calculateResult->execute($attempt);

                        $attempt->score = $result['earned_points'];
                        $attempt->percentage = $result['percentage'];
                        $attempt->raw_percentage = $result['raw_percentage'];
                        $attempt->status = $wasPublished
                            ? ExamAttemptStatus::Published->value
                            : ($result['requires_manual_grading']
                                ? ExamAttemptStatus::Grading->value
                                : ExamAttemptStatus::Submitted->value);
                        $attempt->save();

                        $scoreChanged = $oldScore !== $attempt->score
                            || $oldPercentage !== $attempt->percentage
                            || ($oldRawPercentage === null
                                ? $attempt->raw_percentage !== null
                                : abs((float) $oldRawPercentage - (float) $attempt->raw_percentage) > 0.000001);

                        $attemptsRegraded++;

                        if ($scoreChanged) {
                            $scoresChanged++;
                            if ($wasPublished) {
                                $publishedScoresChanged++;
                                $publishedAttemptIds[] = (int) $attempt->getKey();
                            }

                            $scoreChanges[] = [
                                'attempt_id' => (int) $attempt->getKey(),
                                'question_id' => $questionId,
                                'old_score' => $oldScore,
                                'new_score' => $attempt->score,
                                'old_percentage' => $oldPercentage,
                                'new_percentage' => $attempt->percentage,
                                'old_status' => $oldStatus,
                                'published' => $wasPublished,
                            ];
                        }
                    }
                });

            return [
                'attempts_regraded' => $attemptsRegraded,
                'scores_changed' => $scoresChanged,
                'published_scores_changed' => $publishedScoresChanged,
                'score_changes' => $scoreChanges,
                'published_attempt_ids' => $publishedAttemptIds,
                'correct_option_count' => count($correctOptionIds),
            ];
        });

        // Keep a question-level record and per-attempt before/after score values
        // without copying the answer key itself into audit metadata.
        $this->auditLog->execute('exam.answer_key_regraded', $question, [
            'exam_id' => $question->exam_id,
            'correct_option_count' => $work['correct_option_count'],
            'attempts_regraded' => $work['attempts_regraded'],
            'scores_changed' => $work['scores_changed'],
            'published_scores_changed' => $work['published_scores_changed'],
            'reason' => $reason ?? 'Teacher corrected an MCQ answer key.',
        ], $staffUser);

        foreach ($work['score_changes'] as $change) {
            $attempt = ExamAttempt::query()->find($change['attempt_id']);
            $this->auditLog->execute('grade.answer_key_regraded', $attempt, [
                'question_id' => $change['question_id'],
                'old_score' => $change['old_score'],
                'new_score' => $change['new_score'],
                'old_percentage' => $change['old_percentage'],
                'new_percentage' => $change['new_percentage'],
                'old_status' => $change['old_status'],
                'published' => $change['published'] ? '1' : '0',
            ], $staffUser);
        }

        if ($work['published_attempt_ids'] !== []) {
            ExamAttempt::query()
                ->with('student')
                ->whereIn('id', $work['published_attempt_ids'])
                ->get()
                ->each(function (ExamAttempt $attempt): void {
                    if ($attempt->student) {
                        $attempt->student->notify(new ResultAvailableNotification($attempt));
                    }
                });
        }

        return [
            'attempts_regraded' => $work['attempts_regraded'],
            'scores_changed' => $work['scores_changed'],
            'published_scores_changed' => $work['published_scores_changed'],
        ];
    }
}
