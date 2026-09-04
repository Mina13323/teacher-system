<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student tries to join a competition that has reached its
 * `max_participants` limit. Enforced server-side under a transaction and row
 * lock so concurrent join requests cannot exceed the capacity.
 *
 * Rendered as a 409 JSON response by the API exception handler.
 */
class CompetitionCapacityFullException extends RuntimeException
{
    public function __construct(string $message = 'This competition has reached its maximum number of participants.')
    {
        parent::__construct($message);
    }
}
