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
     * @param  list<int>|null  $explicitCorrectOptionIds
     * @return array{
     *     attempts_regraded: int,
     *     scores_changed: int,
     *     published_scores_changed: int
     * }
     */
    public function execute(
        User $staffUser,
        Question $question,
        ?string $reason = null,
        ?array $explicitCorrectOptionIds = null
    ): array {
        $questionId = (int) $question->getKey();

        $work = DB::transaction(function () use ($questionId, $explicitCorrectOptionIds, $staffUser) {
            $lockedQuestion = Question::query()->lockForUpdate()->findOrFail($questionId);

            if (! $lockedQuestion->isMcq()) {
                throw ValidationException::withMessages([
                    'question' => ['Only multiple-choice questions can be regraded automatically.'],
                ]);
            }

            if ($explicitCorrectOptionIds !== null) {
                $allOptionIds = $lockedQuestion->options()->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
                if (array_diff($explicitCorrectOptionIds, $allOptionIds) !== []) {
                    throw ValidationException::withMessages([
                        'correct_option_ids' => ['One or more selected options do not belong to this question.'],
                    ]);
                }

                $lockedQuestion->options()->whereIn('id', $explicitCorrectOptionIds)->update(['is_correct' => true]);
                $lockedQuestion->options()->whereNotIn('id', $explicitCorrectOptionIds)->update(['is_correct' => false]);
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

            $newCorrectOptionIds = $correctOptionIds;
            $newCorrectOptionTexts = $liveOptions
                ->filter(fn ($o) => in_array((int) $o->getKey(), $correctOptionIds, true))
                ->pluck('option_text')
                ->all();

            $oldCorrectOptionIds = [];
            $oldCorrectOptionTexts = [];

            $attemptsRegraded = 0;
            $scoresChanged = 0;
            $publishedScoresChanged = 0;
            $scoreChanges = [];
            $publishedAttemptIds = [];

            ExamAttempt::query()
                ->where('exam_id', $lockedQuestion->exam_id)
                ->where(function ($query) {
                    $query->whereIn('status', ExamAttemptStatus::submittedValues())
                        ->orWhereNotNull('grades_published_at');
                })
                ->whereHas('attemptQuestions', fn ($query) => $query->where('question_id', $questionId))
                ->with(['attemptQuestions.attemptOptions', 'answers.selectedOptions'])
                ->orderBy('id')
                ->lockForUpdate()
                ->chunkById(50, function ($attempts) use (
                    $questionId,
                    $liveType,
                    $correctOptionIds,
                    &$oldCorrectOptionIds,
                    &$oldCorrectOptionTexts,
                    &$attemptsRegraded,
                    &$scoresChanged,
                    &$publishedScoresChanged,
                    &$scoreChanges,
                    &$publishedAttemptIds,
                    $staffUser
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

                        // Capture previous correct answer key from snapshot before mutating.
                        if ($oldCorrectOptionIds === []) {
                            $oldOpts = $attemptQuestion->attemptOptions
                                ->filter(fn ($o) => (bool) $o->is_correct);
                            $oldCorrectOptionIds = $oldOpts
                                ->pluck('option_id')
                                ->map(fn ($id) => (int) $id)
                                ->sort()
                                ->values()
                                ->all();
                            $oldCorrectOptionTexts = $oldOpts
                                ->pluck('option_text')
                                ->all();
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

                        $oldAnswerCorrect = $answer->is_correct;
                        $oldAnswerPoints = $answer->points_earned;

                        $answer->is_correct = $this->calculateResult
                            ->isChoiceAnswerCorrect($attemptQuestion, $answer);
                        $answer->points_earned = $answer->is_correct
                            ? $attemptQuestion->points
                            : 0;
                        $answer->graded_at = now();
                        $answer->graded_by = $staffUser->getKey();
                        $answer->save();

                        $oldScore = $attempt->score;
                        $oldPercentage = $attempt->percentage;
                        $oldRawPercentage = $attempt->raw_percentage;
                        $oldOutcome = $attempt->outcome()->value;
                        $wasPublished = $attempt->status === ExamAttemptStatus::Published
                            || $attempt->grades_published_at !== null;
                        $oldStatus = $attempt->status?->value;

                        // Calculate the full result again so essay points, the
                        // frozen pass threshold, and exact multi-select rules
                        // remain part of the total.
                        $attempt->unsetRelation('answers');
                        $attempt->unsetRelation('attemptQuestions');
                        $attempt->loadMissing([
                            'attemptQuestions.attemptOptions',
                            'answers.selectedOptions',
                        ]);
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

                        $newOutcome = $attempt->outcome()->value;

                        $scoreChanged = $oldScore !== $attempt->score
                            || $oldPercentage !== $attempt->percentage
                            || $oldOutcome !== $newOutcome
                            || ($oldRawPercentage === null
                                ? $attempt->raw_percentage !== null
                                : abs((float) $oldRawPercentage - (float) $attempt->raw_percentage) > 0.000001);

                        $answerChanged = $oldAnswerCorrect !== $answer->is_correct
                            || $oldAnswerPoints !== $answer->points_earned;

                        $attemptsRegraded++;

                        if ($scoreChanged || $answerChanged) {
                            if ($scoreChanged) {
                                $scoresChanged++;
                                if ($wasPublished) {
                                    $publishedScoresChanged++;
                                    $publishedAttemptIds[] = (int) $attempt->getKey();
                                }
                            }

                            $scoreChanges[] = [
                                'attempt_id' => (int) $attempt->getKey(),
                                'question_id' => $questionId,
                                'old_score' => $oldScore,
                                'new_score' => $attempt->score,
                                'old_percentage' => $oldPercentage,
                                'new_percentage' => $attempt->percentage,
                                'old_outcome' => $oldOutcome,
                                'new_outcome' => $newOutcome,
                                'old_status' => $oldStatus,
                                'new_status' => $attempt->status?->value,
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
                'old_correct_option_ids' => $oldCorrectOptionIds,
                'new_correct_option_ids' => $newCorrectOptionIds,
                'old_correct_answer' => implode(', ', $oldCorrectOptionTexts),
                'new_correct_answer' => implode(', ', $newCorrectOptionTexts),
            ];
        });

        // Keep a question-level record and per-attempt before/after score values.
        $this->auditLog->execute('exam.answer_key_regraded', $question, [
            'exam_id' => (string) $question->exam_id,
            'question_id' => (string) $questionId,
            'old_correct_option_ids' => implode(',', $work['old_correct_option_ids']),
            'new_correct_option_ids' => implode(',', $work['new_correct_option_ids']),
            'old_correct_answer' => $work['old_correct_answer'],
            'new_correct_answer' => $work['new_correct_answer'],
            'correct_option_count' => (string) $work['correct_option_count'],
            'attempts_regraded' => (string) $work['attempts_regraded'],
            'scores_changed' => (string) $work['scores_changed'],
            'published_scores_changed' => (string) $work['published_scores_changed'],
            'reason' => $reason ?? 'Teacher corrected an MCQ answer key.',
        ], $staffUser);

        foreach ($work['score_changes'] as $change) {
            $attemptModel = ExamAttempt::query()->find($change['attempt_id']);
            if ($attemptModel) {
                $this->auditLog->execute('grade.answer_key_regraded', $attemptModel, [
                    'question_id' => $change['question_id'],
                    'old_score' => $change['old_score'],
                    'new_score' => $change['new_score'],
                    'old_percentage' => $change['old_percentage'],
                    'new_percentage' => $change['new_percentage'],
                    'old_outcome' => $change['old_outcome'],
                    'new_outcome' => $change['new_outcome'],
                    'old_status' => $change['old_status'],
                    'new_status' => $change['new_status'],
                    'published' => $change['published'] ? '1' : '0',
                ], $staffUser);
            }
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
