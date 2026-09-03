<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an otherwise valid credential belongs to a deactivated account.
 *
 * Rendered as a 403 JSON response by the API exception handler.
 */
class AccountDisabledException extends RuntimeException
{
    public function __construct(string $message = 'This account has been disabled.')
    {
        parent::__construct($message);
    }
}
