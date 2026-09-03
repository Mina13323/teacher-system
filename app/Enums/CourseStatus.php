<?php

namespace App\Enums;

/**
 * Lifecycle status of a course.
 */
enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * The OpenAPI/POST-friendly default value.
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
}
