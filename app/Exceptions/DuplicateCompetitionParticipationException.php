<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student attempts to join a competition they are already a
 * participant of. Guarded by a unique (competition_id, student_id) constraint,
 * so concurrent duplicate join requests cannot produce duplicate participants.
 *
 * Rendered as a 409 JSON response by the API exception handler.
 */
class DuplicateCompetitionParticipationException extends RuntimeException
{
    public function __construct(string $message = 'You are already participating in this competition.')
    {
        parent::__construct($message);
    }
}
