<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Updates a managed account (a student, or a teacher by an admin). Only the
 * given safe fields may be changed; role assignment is never handled here.
 *
 * Email and password changes are intentionally handled by separate, explicit
 * methods so their validation and side effects stay isolated.
 */
class UpdateUserAccountAction
{
    public function execute(User $account, array $data): User
    {
        $fillable = ['name', 'phone', 'bio', 'avatar'];

        // Email is editable by a manager, with a unique-value check performed in
        // the Form Request. is_active is handled by dedicated activate/deactivate.
        $account->fill(array_intersect_key($data, array_flip($fillable)));

        if ($account->isProfileComplete() && $account->profile_completed_at === null) {
            $account->profile_completed_at = now();
        }

        $account->save();

        return $account->fresh();
    }
}
