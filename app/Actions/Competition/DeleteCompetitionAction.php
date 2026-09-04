<?php

namespace App\Actions\Competition;

use App\Exceptions\InvalidCompetitionStateException;
use App\Models\Competition;

/**
 * Deletes a competition.
 *
 * A hard delete is only permitted when the competition has no participation or
 * results — otherwise deleting it would destroy historical competition data.
 * Such competitions should instead be archived.
 */
class DeleteCompetitionAction
{
    public function execute(Competition $competition): void
    {
        if ($competition->participants()->exists() || $competition->results()->exists()) {
            throw new InvalidCompetitionStateException(
                'This competition has participation history and cannot be deleted. Archive it instead.'
            );
        }

        $competition->delete();
    }
}
