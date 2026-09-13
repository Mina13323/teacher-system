<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Activates or deactivates an account. Deactivation revokes the user's API
 * tokens so a deactivated (suspended) account cannot continue to authenticate
 * under an existing session; activation re-enables login.
 */
class SetAccountActiveStateAction
{
    public function execute(User $account, bool $active): User
    {
        $account->is_active = $active;
        $account->save();

        if ($account->isStudent()) {
            if ($active) {
                $latest = $account->latestAccessPeriod;
                if (! $latest || $latest->status !== \App\Enums\StudentAccessStatus::Active || ($latest->expires_at && $latest->expires_at->isPast())) {
                    \App\Models\StudentAccessPeriod::create([
                        'student_id' => $account->getKey(),
                        'status' => \App\Enums\StudentAccessStatus::Active->value,
                        'starts_at' => now(),
                        'expires_at' => now()->addMonth(),
                        'notes' => 'Account activated',
                        'approved_at' => now(),
                    ]);
                }
            } else {
                \App\Models\StudentAccessPeriod::create([
                    'student_id' => $account->getKey(),
                    'status' => \App\Enums\StudentAccessStatus::Suspended->value,
                    'starts_at' => now(),
                    'expires_at' => now(),
                    'notes' => 'Account deactivated',
                    'approved_at' => now(),
                ]);
            }
        }

        if (! $active) {
            $account->tokens()->delete();
        }

        return $account->fresh(['accessPeriods', 'latestAccessPeriod']);
    }
}
