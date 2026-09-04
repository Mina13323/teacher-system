<?php

namespace App\Policies;

use App\Models\Competition;
use App\Models\User;

class CompetitionPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('teacher') || $user->hasRole('admin');
    }

    public function view(User $user, Competition $competition): bool
    {
        return $this->canManage($user, $competition);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('teacher') && $user->hasPermissionTo('competitions.manage');
    }

    public function update(User $user, Competition $competition): bool
    {
        return $this->canManage($user, $competition) && $user->hasPermissionTo('competitions.manage');
    }

    public function delete(User $user, Competition $competition): bool
    {
        return $this->canManage($user, $competition) && $user->hasPermissionTo('competitions.manage');
    }

    /**
     * Publish / archive / participants / leaderboard / recalculate /
     * disqualify — all contingent on managing the competition.
     */
    public function manage(User $user, Competition $competition): bool
    {
        return $this->canManage($user, $competition) && $user->hasPermissionTo('competitions.manage');
    }

    private function canManage(User $user, Competition $competition): bool
    {
        return $user->hasRole('admin') || $competition->isOwnedBy($user);
    }
}
