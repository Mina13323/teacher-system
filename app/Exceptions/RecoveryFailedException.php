<?php

namespace App\Exceptions;

/**
 * Thrown when a password recovery attempt cannot be verified. The message is
 * the same whatever part was wrong (unknown code, no phone on file, wrong
 * phone, wrong recovery code) so it cannot be used to probe accounts.
 *
 * Rendered as a 401 JSON response by the InvalidCredentialsException handler.
 */
class RecoveryFailedException extends InvalidCredentialsException
{
    public function __construct()
    {
        parent::__construct('We could not verify your details. Please contact the admin to reset your password.');
    }
}
