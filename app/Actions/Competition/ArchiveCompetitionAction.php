<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionStatus;
use App\Exceptions\InvalidCompetitionStateException;
use App\Models\Competition;

/**
 * Archives a competition, removing it from student availability.
 *
 * The ordinary lifecycle ends a competition before archiving (ACTIVE -> ENDED
 * -> ARCHIVED). A competition that has not yet started (DRAFT / PUBLISHED) may
 * also be retired directly to ARCHIVED because it has no participation. An
 * ACTIVE competition cannot be archived: it must first be ended so its final
 * leaderboard is frozen.
 */
class ArchiveCompetitionAction
{
    public function execute(Competition $competition): Competition
    {
        $competition = $competition->lazyFinalize();

        if ($competition->status->isActive()) {
            throw new InvalidCompetitionStateException(
                'An active competition must be ended before it can be archived.'
            );
        }

        if ($competition->status->isArchived()) {
            throw new InvalidCompetitionStateException('This competition is already archived.');
        }

        $competition->status = CompetitionStatus::Archived->value;
        $competition->save();

        return $competition->fresh();
    }
}
