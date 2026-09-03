<?php

namespace App\Enums;

/**
 * Integrity event severity. Describes the event itself, never a direct
 * declaration that a student cheated.
 */
enum IntegritySeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function default(): self
    {
        return self::Low;
    }
}
