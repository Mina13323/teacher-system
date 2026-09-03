<?php

namespace App\Enums;

/**
 * Lifecycle status of an exam.
 */
enum ExamStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * The default value when creating an exam.
     */
    public static function default(): self
    {
        return self::Draft;
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isArchived(): bool
    {
        return $this === self::Archived;
    }
}
