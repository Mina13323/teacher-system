<?php

namespace App\Exceptions;

use RuntimeException;

/** The transfer token is unknown, used or expired: sign in again. */
class SessionTransferExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This confirmation has expired. Please sign in again.');
    }
}
