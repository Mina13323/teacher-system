<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionScoringType;
use App\Enums\ExamAttemptStatus;
use App\Models\Competition;
use App\Models\ExamAttempt;

/**
 * Picks the single best submitted exam attempt for a participant, based on the
 * competition's scoring type. The chosen attempt's score, percentage and timing
 * become the participant's competition result.
 *
 *   HIGHEST_SCORE  best by percentage (then score, then earliest submission)
 *   BEST_ATTEMPT   best by raw score (then percentage, then earliest submission)
 *
 * The value is always derived server-side from the trusted exam attempt record;
 * the client never supplies a score.
 *
 * @return array{attempt_id: int, score: int, percentage: int, completion_time: int, completed_at: \Illuminate\Support\Carbon|null}|null
 */
class CalculateCompetitionScoreAction
{
    public function execute(Competition $competition, int $studentId): ?array
    {
        $attempts = ExamAttempt::query()
            ->where('exam_id', $competition->exam_id)
            ->where('student_id', $studentId)
            ->where('status', ExamAttemptStatus::Submitted->value)
            ->get();

        if ($attempts->isEmpty()) {
            return null;
        }

        if ($competition->scoring_type->isBestAttempt()) {
            $best = $attempts
                ->sortBy([
                    ['score', 'desc'],
                    ['percentage', 'desc'],
                    ['submitted_at', 'asc'],
                ])
                ->first();
        } else {
            // Default: HIGHEST_SCORE
            $best = $attempts
                ->sortBy([
                    ['percentage', 'desc'],
                    ['score', 'desc'],
                    ['submitted_at', 'asc'],
                ])
                ->first();
        }

        /** @var ExamAttempt $best */
        $completionTime = $best->started_at && $best->submitted_at
            ? max(0, $best->submitted_at->diffInSeconds($best->started_at))
            : 0;

        return [
            'attempt_id' => $best->getKey(),
            'score' => (int) $best->score,
            'percentage' => (int) $best->percentage,
            'completion_time' => $completionTime,
            'completed_at' => $best->submitted_at,
        ];
    }
}
