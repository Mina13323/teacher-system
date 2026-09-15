<?php

namespace App\Actions\Student;

use App\Enums\StudentAccessStatus;
use App\Models\StudentAccessPeriod;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class SuspendStudentAction
{
    /**
     * Suspends a student's access without deleting any student history or account records.
     */
    public function execute(User $staffUser, User $student, ?string $reason = null): User
    {
        $student->is_active = false;
        $student->save();

        StudentAccessPeriod::create([
            'student_id' => $student->getKey(),
            'status' => StudentAccessStatus::Suspended->value,
            'starts_at' => now(),
            'expires_at' => now(),
            'amount' => null,
            'notes' => $reason ?: 'Account suspended by '.$staffUser->name,
            'approved_by' => $staffUser->getKey(),
            'approved_at' => now(),
        ]);

        // Revoke active login tokens immediately
        $student->tokens()->delete();

        // Mark any unread renewal notifications for this student as read
        DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('type', 'like', '%StudentRenewalDueNotification%')
            ->whereJsonContains('data->student_id', $student->getKey())
            ->update(['read_at' => now()]);

        return $student->fresh(['accessPeriods', 'roles', 'latestAccessPeriod']);
    }
}
