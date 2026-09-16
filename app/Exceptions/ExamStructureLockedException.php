<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a teacher tries to change the question structure of an exam that
 * students may already be sitting.
 *
 * Adding questions to a published exam changes what every enrolled student sees
 * — and a template scaffold adds *blank* questions, which would render as empty
 * prompts mid-attempt. Rendered as a 422 by the API exception handler.
 */
class ExamStructureLockedException extends RuntimeException
{
    public function __construct(string $message = 'The question structure of this exam can no longer be changed.')
    {
        parent::__construct($message);
    }
}
