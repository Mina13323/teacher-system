<?php

namespace App\Actions\Course;

use App\Exceptions\ResourceDeletionBlockedException;
use App\Models\Competition;
use App\Models\Course;
use App\Models\ExamAttempt;

/**
 * Deletes a course after the caller has been authorized.
 *
 * Deleting a course cascades to its exams (and from there to any attempts). If
 * any of those exams is referenced by a competition, the deletion would be
 * blocked by the RESTRICT FK and would otherwise surface as a raw SQL exception.
 * We detect that up front and return a clean, friendly error instead.
 *
 * A course containing any exam that has recorded student attempts is also
 * protected: those attempts carry the frozen snapshot, answers, integrity
 * evidence and result history. Unpublish the course (or archive the exams)
 * instead of hard-deleting so historical assessment data is preserved.
 */
class DeleteCourseAction
{
    public function execute(Course $course): void
    {
        $examIds = $course->exams()->select('id');

        $hasCompetitionExam = Competition::query()
            ->whereIn('exam_id', $examIds)
            ->exists();

        if ($hasCompetitionExam) {
            throw new ResourceDeletionBlockedException(
                'This course contains exams that are referenced by competitions and cannot be deleted. Remove or archive those competitions first.'
            );
        }

        if (ExamAttempt::query()->whereIn('exam_id', $examIds)->exists()) {
            throw new ResourceDeletionBlockedException(
                'This course contains exams with recorded student attempts and cannot be deleted. Unpublish or archive them first to preserve historical results.'
            );
        }

        $course->delete();
    }
}
