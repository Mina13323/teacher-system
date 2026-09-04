<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionParticipantStatus;
use App\Models\CompetitionParticipant;
use Illuminate\Support\Facades\DB;

/**
 * Explicitly disqualifies a participant (teacher/admin decision). The
 * participant is excluded from ranked positions on the leaderboard, but their
 * historical result and audit data are preserved — nothing is deleted.
 *
 * After disqualification the remaining valid participants are re-ranked so the
 * leaderboard has no gaps (e.g. disqualifying the current #1 promotes the next
 * best to #1). Re-ranking is deterministic and idempotent.
 */
class DisqualifyCompetitionParticipantAction
{
    public function __construct(
        private readonly RecalculateCompetitionLeaderboardAction $recalculateLeaderboard,
    ) {
    }

    public function execute(CompetitionParticipant $participant): CompetitionParticipant
    {
        DB::transaction(function () use ($participant) {
            $participant->status = CompetitionParticipantStatus::Disqualified->value;
            $participant->save();

            // Preserve the historical result but drop it from ranked positions.
            $participant->result()->update([
                'qualified' => false,
                'rank' => null,
            ]);

            // Re-rank the remaining valid participants so the leaderboard has no
            // gaps. This is an explicit, idempotent recalculation.
            $competition = $participant->competition;
            $this->recalculateLeaderboard->execute($competition->fresh());
        });

        return $participant->fresh();
    }
}
