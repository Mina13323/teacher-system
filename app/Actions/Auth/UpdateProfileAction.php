<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Updates a user's own profile. The user may edit their own display name,
 * avatar and public profile fields; it never allows changing the email or the
 * account role.
 */
class UpdateProfileAction
{
    public function execute(User $user, array $data): User
    {
        $fillable = ['name', 'avatar', 'phone', 'bio'];

        $user->fill(array_intersect_key($data, array_flip($fillable)));

        // Marking a profile complete when the user has supplied the core fields.
        if ($user->isProfileComplete() && $user->profile_completed_at === null) {
            $user->profile_completed_at = now();
        }

        $user->save();

        return $user->fresh();
    }
}
