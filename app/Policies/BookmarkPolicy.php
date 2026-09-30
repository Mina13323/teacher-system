<?php

namespace App\Policies;

use App\Models\Bookmark;
use App\Models\User;

/**
 * PHASE 4 §33 — Bookmarks are personal: owner-only for every ability.
 */
class BookmarkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('student');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('student');
    }

    public function delete(User $user, Bookmark $bookmark): bool
    {
        return $bookmark->user_id === $user->getKey();
    }
}
