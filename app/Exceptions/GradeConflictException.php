<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an essay grade is saved against a grade that changed since the
 * grader loaded it (another grader saved first). Rendered as 409 with the
 * current server grade, so the client can show it without overwriting either
 * person's work.
 *
 * @phpstan-type CurrentGrade array{points_earned: int|null, feedback: string|null, graded_at: string|null, grade_version: string}
 */
class GradeConflictException extends RuntimeException
{
    /** @param  CurrentGrade  $current */
    public function __construct(public readonly array $current)
    {
        parent::__construct('This grade was changed by someone else after you opened it. Review the current grade before saving again.');
    }
}
