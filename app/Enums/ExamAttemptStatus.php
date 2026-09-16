<?php

namespace App\Enums;

/**
 * Lifecycle status of an exam attempt.
 */
enum ExamAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Grading = 'grading';
    case Published = 'published';
    case Expired = 'expired';

    public function isInProgress(): bool
    {
        return $this === self::InProgress;
    }

    public function isSubmitted(): bool
    {
        return $this === self::Submitted;
    }

    public function isGrading(): bool
    {
        return $this === self::Grading;
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    public function isExpired(): bool
    {
        return $this === self::Expired;
    }

    /**
     * Statuses representing an attempt the student actually handed in.
     *
     * `in_progress` was never submitted and `expired` never completed. Every
     * "how many attempts" figure must use this set: filtering on `submitted`
     * alone dropped every attempt that had been through essay grading.
     *
     * @return list<string>
     */
    public static function submittedValues(): array
    {
        return [
            self::Submitted->value,
            self::Grading->value,
            self::Published->value,
        ];
    }

    /**
     * Statuses whose score is final and may be averaged.
     *
     * An attempt still `grading` counts its ungraded essay points as zero
     * against the full point total, so its percentage is partial and would
     * understate any average it feeds.
     *
     * @return list<string>
     */
    public static function scoredValues(): array
    {
        return [
            self::Submitted->value,
            self::Published->value,
        ];
    }
}
