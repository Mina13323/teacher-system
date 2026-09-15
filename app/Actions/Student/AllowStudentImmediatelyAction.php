<?php

namespace App\Actions\Student;

use App\Enums\StudentAccessStatus;
use App\Models\StudentAccessPeriod;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class AllowStudentImmediatelyAction
{
    /**
     * Staff action "Allow Immediately": activates student immediately, creates/extends an
     * active access period, revokes suspension, and records staff audit.
     */
    public function execute(
        User $staffUser,
        User $student,
        int $months = 1,
        ?float $amount = null,
        ?string $notes = null
    ): User {
        $months = max(1, min(12, $months));

        $student->is_active = true;
        $student->save();

        $latest = $student->latestAccessPeriod;
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
            'notes' => $notes ?: 'Access allowed immediately by '.$staffUser->name,
            'approved_by' => $staffUser->getKey(),
            'approved_at' => now(),
        ]);

        DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('type', 'like', '%StudentRenewalDueNotification%')
            ->whereJsonContains('data->student_id', $student->getKey())
            ->update(['read_at' => now()]);

        return $student->fresh(['accessPeriods', 'roles', 'latestAccessPeriod']);
    }
}
