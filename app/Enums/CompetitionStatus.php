<?php

namespace App\Enums;

/**
 * Lifecycle status of a competition.
 *
 *   DRAFT      -> PUBLISHED
 *   PUBLISHED  -> ACTIVE
 *   ACTIVE     -> ENDED
 *   ENDED      -> ARCHIVED
 *
 * The linear lifecycle is enforced server-side. A competition is also
 * scheduled by its `starts_at` / `ends_at` window: the backend lazily promotes
 * a PUBLISHED competition to ACTIVE when the window opens, and finalizes it to
 * ENDED when the window closes. Invalid transitions are rejected.
 */
enum CompetitionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Active = 'active';
    case Ended = 'ended';
    case Archived = 'archived';

    /**
     * The default value when creating a competition.
     */
    public static function default(): self
    {
        return self::Draft;
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isEnded(): bool
    {
        return $this === self::Ended;
    }

    public function isArchived(): bool
    {
        return $this === self::Archived;
    }

    /**
     * Whether a transition to the given next status is permitted by the state
     * machine. Lazy scheduling transitions (PUBLISHED -> ACTIVE and
     * ACTIVE -> ENDED driven by the time window) are implicit paths; every
     * other move must be an explicit, allowed transition.
     *
     * Retirement is an explicit exception: a competition that has not started
     * (DRAFT or PUBLISHED with its window still in the future) may be retired
     * directly to ARCHIVED because it has no participation.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Published || $next === self::Archived,
            self::Published => $next === self::Active || $next === self::Archived,
            self::Active => $next === self::Ended,
            self::Ended => $next === self::Archived,
            self::Archived => false,
        };
    }
}
