<?php

namespace App\Enums;

/**
 * How competition results are ranked.
 *
 * The only ranking rule implemented in this phase is deterministically ordered
 * by:
 *
 *   score DESC
 *   completion_time ASC
 *   completed_at ASC
 *   participant_id ASC
 *
 * The final `participant_id` tie-breaker guarantees a stable, total ordering so
 * repeated recalculation always produces the same result. The enum is kept
 * extensible so alternative ranking rules can be added without rewriting the
 * ranking engine.
 */
enum CompetitionRankingType: string
{
    case ScoreDesc = 'score_desc';

    public static function default(): self
    {
        return self::ScoreDesc;
    }
}
