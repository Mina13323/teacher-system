<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionParticipantStatus;
use App\Models\CompetitionParticipant;

/**
 * Explicitly disqualifies a participant (teacher/admin decision). The
 * participant is excluded from ranked positions on the leaderboard, but their
 * historical result and audit data are preserved — nothing is deleted.
 */
class DisqualifyCompetitionParticipantAction
{
    public function execute(CompetitionParticipant $participant): CompetitionParticipant
    {
        $participant->status = CompetitionParticipantStatus::Disqualified->value;
        $participant->save();

        $participant->result()->update([
            'qualified' => false,
            'rank' => null,
        ]);

        return $participant->fresh();
    }
}
