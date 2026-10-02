<?php

namespace App\Actions\Auth;

use App\Enums\StudentAccessStatus;
use App\Models\StudentAccessPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates an account. Deactivation revokes the user's API
 * tokens so a deactivated account cannot continue to authenticate under an
 * existing session. Student access-period history is kept and transitions are
 * idempotent, so repeated deactivation does not create duplicate periods.
 */
class SetAccountActiveStateAction
{
    public function execute(User $account, bool $active): User
    {
        return DB::transaction(function () use ($account, $active): User {
            $account = User::query()->lockForUpdate()->findOrFail($account->getKey());
            $isStudent = $account->isStudent();

            // DATA-002: anonymized accounts must never be reactivated through
            // ordinary flows. A permanent durable marker (anonymized_at) is set
            // by AnonymizeStudentAction and is only clearable by a super-admin
            // through a separate, explicitly confirmed, audited action.
            if ($active && $account->isAnonymized()) {
                abort(409, 'This account has been anonymized and cannot be reactivated through ordinary flows.');
            }

            $account->is_active = $active;
            $account->save();

            if ($isStudent) {
                $latest = $account->latestAccessPeriod()->first();

                if ($active) {
                    if (! $latest
                        || $latest->status !== StudentAccessStatus::Active
                        || ($latest->expires_at && $latest->expires_at->isPast())) {
                        StudentAccessPeriod::create([
                            'student_id' => $account->getKey(),
                            'status' => StudentAccessStatus::Active->value,
                            'starts_at' => now(),
                            'expires_at' => now()->addMonth(),
                            'notes' => 'Account activated',
                            'approved_at' => now(),
                        ]);
                    }
                } elseif (! $latest || $latest->status !== StudentAccessStatus::Suspended) {
                    StudentAccessPeriod::create([
                        'student_id' => $account->getKey(),
                        'status' => StudentAccessStatus::Suspended->value,
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
        });
    }
}
