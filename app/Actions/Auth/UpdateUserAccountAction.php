<?php

namespace App\Actions\Auth;

use App\Enums\StudentCapabilityPreset;
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
        $fillable = [
            'name',
            'phone',
            'bio',
            'avatar',
            'academic_year',
            'can_access_lessons',
            'can_take_exams',
            'can_join_competitions',
        ];

        if (! empty($data['capability_preset'])) {
            $preset = StudentCapabilityPreset::tryFrom(strtoupper($data['capability_preset']));
            if ($preset && $preset !== StudentCapabilityPreset::Custom) {
                $caps = $preset->capabilities();
                if ($caps) {
                    $account->can_access_lessons = $caps['can_access_lessons'];
                    $account->can_take_exams = $caps['can_take_exams'];
                    $account->can_join_competitions = $caps['can_join_competitions'];
                }
            }
        }

        $account->fill(array_intersect_key($data, array_flip($fillable)));

        if ($account->isProfileComplete() && $account->profile_completed_at === null) {
            $account->profile_completed_at = now();
        }

        $account->save();

        return $account->fresh();
    }
}
