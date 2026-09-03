<?php

namespace App\Enums;

/**
 * Supported question types.
 *
 * Only single_choice is implemented; the enum is extensible so additional
 * types (multiple_choice, true_false, ...) can be added later without schema
 * or API restructuring.
 */
enum QuestionType: string
{
    case SingleChoice = 'single_choice';

    public static function default(): self
    {
        return self::SingleChoice;
    }
}
