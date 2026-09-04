<?php

namespace App\Enums;

/**
 * How a participant's competition value is derived from their exam attempts.
 *
 *   HIGHEST_SCORE  The best attempt is chosen by the highest percentage
 *                  achieved on the linked exam.
 *   BEST_ATTEMPT   The best attempt is chosen by the highest raw score
 *                  (earned points) on the linked exam.
 *
 * Both pick exactly one submitted attempt per participant and reuse the raw
 * score, percentage and completion time of that attempt as the competition
 * result. The scoring value is always derived server-side from the trusted
 * exam result; it is never supplied by the client.
 */
enum CompetitionScoringType: string
{
    case HighestScore = 'highest_score';
    case BestAttempt = 'best_attempt';

    public static function default(): self
    {
        return self::HighestScore;
    }

    public function isHighestScore(): bool
    {
        return $this === self::HighestScore;
    }

    public function isBestAttempt(): bool
    {
        return $this === self::BestAttempt;
    }
}
