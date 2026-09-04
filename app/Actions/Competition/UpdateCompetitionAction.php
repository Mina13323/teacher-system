<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionStatus;
use App\Exceptions\InvalidCompetitionStateException;
use App\Models\Competition;

/**
 * Updates the editable settings of a competition according to its lifecycle.
 *
 *   DRAFT / PUBLISHED   title, description and the scheduling window
 *                       (starts_at, ends_at, max_participants) are editable.
 *   ACTIVE              only title / description may change; the scoring and
 *                       ranking configuration is frozen for the duration.
 *   ENDED / ARCHIVED    read-only.
 *
 * Scoring type, ranking type and the linked exam are never editable through
 * this path; they are frozen once the competition leaves DRAFT.
 */
class UpdateCompetitionAction
{
    public function execute(Competition $competition, array $data): Competition
    {
        $competition = $competition->lazyFinalize();
        $status = $competition->status;

        if ($status->isEnded() || $status->isArchived()) {
            throw new InvalidCompetitionStateException(
                'This competition is read-only because it has ended or been archived.'
            );
        }

        $editable = ['title', 'description'];

        if ($status->isDraft() || $status->isPublished()) {
            $editable = ['title', 'description', 'starts_at', 'ends_at', 'max_participants'];
        }

        $data = array_intersect_key($data, array_flip($editable));

        $competition->fill($data);
        $competition->save();

        return $competition->fresh();
    }
}
