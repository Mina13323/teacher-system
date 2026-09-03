<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a teacher attempts to publish an exam that is incomplete or
 * invalid (e.g. no questions, missing correct option, invalid settings).
 *
 * Rendered as a 422 JSON response by the API exception handler.
 */
class ExamNotReadyToPublishException extends RuntimeException
{
    public function __construct(string $message = 'This exam is not ready to be published.')
    {
        parent::__construct($message);
    }
}
