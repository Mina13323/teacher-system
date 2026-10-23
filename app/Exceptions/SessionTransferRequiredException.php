<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The password was correct, but the account is signed in on another device
 * and may use one at a time. Nothing was revoked; the caller may confirm the
 * move with the one-time transfer token.
 */
class SessionTransferRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $transferToken,
        public readonly int $expiresIn,
        public readonly array $otherSession,
    ) {
        parent::__construct('This account is signed in on another device.');
    }
}
