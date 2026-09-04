<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation is attempted against a competition in a state that
 * does not permit it — for example an invalid lifecycle transition, editing an
 * already-finalized competition, or publishing without a valid schedule.
 *
 * Rendered as a 422 JSON response by the API exception handler.
 */
class InvalidCompetitionStateException extends RuntimeException
{
    public function __construct(string $message = 'This competition is not in a state that permits that action.')
    {
        parent::__construct($message);
    }
}
