<?php

namespace App\Enums;

/**
 * Status of a student's participation in a competition.
 *
 *   REGISTERED   Joined but no final result yet.
 *   ACTIVE       Has an in-progress or qualifying attempt in flight.
 *   COMPLETED    A final competition result has been recorded.
 *   DISQUALIFIED Teacher/admin explicitly removed the participant from ranking.
 *   WITHDRAWN    Reserved for a future self-withdrawal flow (not used yet).
 *
 * Participants cannot change their own status; every transition is server- or
 * teacher-controlled.
 */
enum CompetitionParticipantStatus: string
{
    case Registered = 'registered';
    case Active = 'active';
    case Completed = 'completed';
    case Disqualified = 'disqualified';
    case Withdrawn = 'withdrawn';

    public function isRegistered(): bool
    {
        return $this === self::Registered;
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    public function isDisqualified(): bool
    {
        return $this === self::Disqualified;
    }

    public function isWithdrawn(): bool
    {
        return $this === self::Withdrawn;
    }
}
