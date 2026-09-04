<?php

namespace App\Actions\Course;

use App\Exceptions\ResourceDeletionBlockedException;
use App\Models\Competition;
use App\Models\Course;

/**
 * Deletes a course after the caller has been authorized.
 *
 * Deleting a course cascades to its exams. If any of those exams is referenced
 * by a competition, the deletion would be blocked by the RESTRICT FK and would
 * otherwise surface as a raw SQL exception. We detect that up front and return
 * a clean, friendly error instead.
 */
class DeleteCourseAction
{
    public function execute(Course $course): void
    {
        $hasCompetitionExam = Competition::query()
            ->whereIn('exam_id', $course->exams()->select('id'))
            ->exists();

        if ($hasCompetitionExam) {
            throw new ResourceDeletionBlockedException(
                'This course contains exams that are referenced by competitions and cannot be deleted. Remove or archive those competitions first.'
            );
        }

        $course->delete();
    }
}
