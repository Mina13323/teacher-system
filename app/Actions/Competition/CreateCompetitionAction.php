<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionRankingType;
use App\Enums\CompetitionScoringType;
use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\User;

/**
 * Creates a competition in draft state for a teacher.
 */
class CreateCompetitionAction
{
    public function execute(User $creator, array $data): Competition
    {
        return Competition::create(array_merge($data, [
            'created_by' => $creator->getKey(),
            'status' => CompetitionStatus::Draft->value,
            'scoring_type' => $data['scoring_type'] ?? CompetitionScoringType::default()->value,
            'ranking_type' => $data['ranking_type'] ?? CompetitionRankingType::default()->value,
        ]));
    }
}
