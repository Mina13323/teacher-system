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
     * ACTIVE -> ENDED driven by the time window) are the only implicit paths;
     * every other move must be an explicit, allowed transition.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Published,
            self::Published => $next === self::Active,
            self::Active => $next === self::Ended,
            self::Ended => $next === self::Archived,
            self::Archived => false,
        };
    }
}
