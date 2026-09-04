<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionParticipantStatus;
use App\Models\Competition;
use App\Models\CompetitionParticipant;
use App\Models\CompetitionResult;

/**
 * Materializes a competition participant's result from their submitted exam
 * attempts. This is the bridge that reuses the existing exam attempt/result as
 * the single source of truth and mirrors it into a competition-specific result.
 *
 * Idempotent: re-running syncs each participant's best attempt again. It is the
 * lazy trigger behind the leaderboard and the finalization snapshot, so no cron
 * job is required for correctness.
 */
class SyncCompetitionResultsAction
{
    public function __construct(
        private readonly CalculateCompetitionScoreAction $calculateScore,
    ) {
    }

    public function execute(Competition $competition): void
    {
        $participants = $competition->participants()->get();

        foreach ($participants as $participant) {
            /** @var CompetitionParticipant $participant */
            if ($participant->isDisqualified()) {
                continue;
            }

            $score = $this->calculateScore->execute($competition, $participant->student_id);

            if ($score === null) {
                continue;
            }

            CompetitionResult::updateOrCreate(
                [
                    'competition_id' => $competition->getKey(),
                    'participant_id' => $participant->getKey(),
                ],
                [
                    'attempt_id' => $score['attempt_id'],
                    'score' => $score['score'],
                    'percentage' => $score['percentage'],
                    'completion_time' => $score['completion_time'],
                    'completed_at' => $score['completed_at'],
                ]
            );

            if ($participant->status->isRegistered()) {
                $participant->status = CompetitionParticipantStatus::Completed->value;
                $participant->save();
            }
        }
    }
}
