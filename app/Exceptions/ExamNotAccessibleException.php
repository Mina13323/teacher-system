<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a student is not enrolled in the course that owns an exam, or
 * otherwise lacks access to the exam.
 *
 * Rendered as a 403 JSON response by the API exception handler.
 */
class ExamNotAccessibleException extends RuntimeException
{
    public function __construct(string $message = 'You do not have access to this exam.')
    {
        parent::__construct($message);
    }
}
