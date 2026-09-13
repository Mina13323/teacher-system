<?php

namespace App\Actions\Student;

use App\Enums\StudentAccessStatus;
use App\Models\StudentAccessPeriod;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class RenewStudentAccessAction
{
    /**
     * Executes a staff renewal decision on a student's access period.
     *
     * @param  string  $decision  'keep_active' or 'suspend'
     */
    public function execute(
        User $staffUser,
        User $student,
        string $decision,
        int $months = 1,
        ?float $amount = null,
        ?string $notes = null,
    ): User {
        $months = max(1, min(12, $months));

        if ($decision === 'keep_active') {
            $latest = $student->latestAccessPeriod;

            // If existing period hasn't expired yet, extend from its expiration date
            if ($latest && $latest->status === StudentAccessStatus::Active && $latest->expires_at && $latest->expires_at->isFuture()) {
                $startsAt = $latest->expires_at;
                $expiresAt = $latest->expires_at->copy()->addMonths($months);
            } else {
                $startsAt = now();
                $expiresAt = now()->addMonths($months);
            }

            StudentAccessPeriod::create([
                'student_id' => $student->getKey(),
                'status' => StudentAccessStatus::Active->value,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'amount' => $amount,
                'notes' => $notes ?: 'Access extended by '.$staffUser->name,
                'approved_by' => $staffUser->getKey(),
                'approved_at' => now(),
            ]);

            $student->is_active = true;
            $student->save();
        } elseif ($decision === 'suspend') {
            $latest = $student->latestAccessPeriod;

            if ($latest) {
                $latest->status = StudentAccessStatus::Suspended;
                $latest->approved_by = $staffUser->getKey();
                $latest->approved_at = now();
                if ($notes) {
                    $latest->notes = $latest->notes ? $latest->notes."\n".$notes : $notes;
                }
                $latest->save();
            } else {
                StudentAccessPeriod::create([
                    'student_id' => $student->getKey(),
                    'status' => StudentAccessStatus::Suspended->value,
                    'starts_at' => now(),
                    'expires_at' => now(),
                    'amount' => null,
                    'notes' => $notes ?: 'Access suspended by '.$staffUser->name,
                    'approved_by' => $staffUser->getKey(),
                    'approved_at' => now(),
                ]);
            }

            // Immediately invalidate all active student tokens/sessions
            $student->tokens()->delete();
        }

        // Clean up / mark read any outstanding renewal notifications for this student
        DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('type', 'like', '%StudentRenewalDueNotification%')
            ->whereJsonContains('data->student_id', $student->getKey())
            ->update(['read_at' => now()]);

        return $student->fresh(['accessPeriods', 'roles']);
    }
}
