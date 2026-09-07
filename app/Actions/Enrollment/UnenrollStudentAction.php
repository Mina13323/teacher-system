<?php

namespace App\Actions\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;

/**
 * Cancels a student's enrollment. The enrollment row is preserved (status set to
 * cancelled) so historical progress/attempt records are never destroyed.
 */
class UnenrollStudentAction
{
    public function execute(Enrollment $enrollment): Enrollment
    {
        $enrollment->status = EnrollmentStatus::Cancelled->value;
        $enrollment->save();

        return $enrollment->fresh();
    }
}
