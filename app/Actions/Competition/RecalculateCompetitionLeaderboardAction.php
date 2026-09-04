<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionParticipantStatus;
use App\Enums\CompetitionRankingType;
use App\Models\Competition;
use App\Models\CompetitionResult;
use Illuminate\Support\Facades\DB;

/**
 * Deterministically recomputes the competition leaderboard and persists ranks.
 *
 * Steps:
 *  1. Sync each participant's result from their submitted exam attempts.
 *  2. Retrieve the valid (non-disqualified) results.
 *  3. Order them deterministically by score DESC, completion_time ASC,
 *     completed_at ASC, participant_id ASC.
 *  4. Assign ranks using standard competition ranking on the score: equal
 *     scores share a rank and the next distinct score has its rank skipped
 *     (e.g. 100, 100, 95 -> 1, 1, 3).
 *
 * The result is deterministic, so repeated recalculation always produces the
 * identical ordering and ranks. Students cannot trigger this with arbitrary
 * parameters; it is run by the system (finalization / lazy finalization) and by
 * an authorized teacher via an explicit endpoint.
 */
class RecalculateCompetitionLeaderboardAction
{
    public function __construct(
        private readonly SyncCompetitionResultsAction $syncResults,
    ) {
    }

    public function execute(Competition $competition): void
    {
        // The leaderboard recomputation spans many writes (syncing each
        // participant's result and persisting the assigned ranks). Run it inside
        // a transaction so a failure cannot leave a partially updated leaderboard
        // (e.g. some ranks assigned and others stale). Recalculation is idempotent.
        DB::transaction(function () use ($competition) {
            $this->syncResults->execute($competition);

            $results = $competition->results()
                ->whereHas('participant', function ($query) {
                    $query->where('status', '!=', CompetitionParticipantStatus::Disqualified->value);
                })
                ->with('participant')
                ->get();

            $sorted = $results
                ->sortBy([
                    ['score', 'desc'],
                    ['completion_time', 'asc'],
                    ['completed_at', 'asc'],
                    ['participant_id', 'asc'],
                ])
                ->values();

            $rank = 0;
            $previousScore = null;

            foreach ($sorted as $index => $result) {
                /** @var CompetitionResult $result */
                if ($previousScore === null || $result->score !== $previousScore) {
                    $rank = $index + 1;
                    $previousScore = $result->score;
                }

                $result->rank = $rank;
                $result->qualified = true;
                $result->save();
            }
        });
    }
}
