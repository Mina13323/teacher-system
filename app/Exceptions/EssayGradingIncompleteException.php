<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when grades are published while essay answers are still ungraded.
 * Rendered as a 422 carrying only the number of outstanding essays (never
 * any answer content).
 */
class EssayGradingIncompleteException extends RuntimeException
{
    public function __construct(public readonly int $ungradedEssays)
    {
        parent::__construct($ungradedEssays === 1
            ? '1 essay answer still needs a grade before this result can be published.'
            : "{$ungradedEssays} essay answers still need a grade before this result can be published.");
    }
}
