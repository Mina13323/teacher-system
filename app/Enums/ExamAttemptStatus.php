<?php

namespace App\Enums;

/**
 * Lifecycle status of an exam attempt.
 */
enum ExamAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Expired = 'expired';

    public function isInProgress(): bool
    {
        return $this === self::InProgress;
    }

    public function isSubmitted(): bool
    {
        return $this === self::Submitted;
    }

    public function isExpired(): bool
    {
        return $this === self::Expired;
    }
}
