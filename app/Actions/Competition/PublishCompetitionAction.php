<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionStatus;
use App\Exceptions\InvalidCompetitionStateException;
use App\Models\Competition;

/**
 * Publishes a competition, moving it from DRAFT to PUBLISHED.
 *
 * A competition must have a valid scheduling window before it can be published
 * (a start time and an end time that is strictly after the start). The status
 * transition is validated against the linear lifecycle and can only be DRAFT ->
 * PUBLISHED.
 */
class PublishCompetitionAction
{
    public function execute(Competition $competition): Competition
    {
        if ($competition->starts_at === null) {
            throw new InvalidCompetitionStateException('A start time is required to publish this competition.');
        }

        if ($competition->ends_at === null) {
            throw new InvalidCompetitionStateException('An end time is required to publish this competition.');
        }

        if ($competition->ends_at->lte($competition->starts_at)) {
            throw new InvalidCompetitionStateException('The end time must be after the start time.');
        }

        if (! $competition->status->canTransitionTo(CompetitionStatus::Published)) {
            throw new InvalidCompetitionStateException(
                'This competition cannot be published from its current state.'
            );
        }

        $competition->status = CompetitionStatus::Published->value;
        $competition->save();

        return $competition->fresh();
    }
}
