<?php

namespace App\Actions\Student;

use App\Enums\StudentAccessStatus;
use App\Models\StudentAccessPeriod;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class RestoreStudentAction
{
    /**
     * Restores a suspended student: reactivates account, sets an active access period,
     * restores LMS access, and records audit trail.
     */
    public function execute(User $staffUser, User $student, int $months = 1, ?string $notes = null): User
    {
        $months = max(1, min(12, $months));

        $student->is_active = true;
        $student->save();

        $startsAt = now();
        $expiresAt = now()->addMonths($months);

        StudentAccessPeriod::create([
            'student_id' => $student->getKey(),
            'status' => StudentAccessStatus::Active->value,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'amount' => null,
            'notes' => $notes ?: 'Account restored by '.$staffUser->name,
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
