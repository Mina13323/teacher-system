<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an attempt to delete a resource is blocked because other records
 * depend on it (e.g. an exam referenced by a competition). Deleting it would
 * destroy historical data, so the operation is refused with a clean message.
 *
 * Rendered as a 409 JSON response by the API exception handler.
 */
class ResourceDeletionBlockedException extends RuntimeException
{
    public function __construct(string $message = 'This resource cannot be deleted because other records depend on it.')
    {
        parent::__construct($message);
    }
}
