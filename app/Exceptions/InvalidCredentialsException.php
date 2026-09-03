<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an authentication attempt fails due to bad credentials.
 *
 * Rendered as a 401 JSON response by the API exception handler.
 */
class InvalidCredentialsException extends RuntimeException
{
    public function __construct(string $message = 'The provided credentials are incorrect.')
    {
        parent::__construct($message);
    }
}
