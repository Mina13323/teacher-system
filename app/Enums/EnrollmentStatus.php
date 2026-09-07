<?php

namespace App\Enums;

/**
 * Lifecycle status of a student enrollment in a course.
 */
enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
