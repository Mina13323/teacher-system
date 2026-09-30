<?php

namespace App\Actions\Course;

use App\Actions\Audit\RecordAuditLogAction;
use App\Models\Course;

/**
 * Restores a soft-deleted course together with the units/lessons/exams that
 * were soft-deleted with it (P1 soft-delete recovery).
 *
 * Restore is exactly inverted delete: only rows still soft-deleted are
 * brought back — anything deleted separately beforehand stays deleted.
 */
class RestoreCourseAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    public function execute(Course $course): Course
    {
        if ($course->trashed()) {
            $course->restore();
        }

        $course->units()->onlyTrashed()->get()->each(function ($unit) {
            $unit->lessons()->onlyTrashed()->restore();
            $unit->restore();
        });
        $course->exams()->onlyTrashed()->restore();

        $this->auditLog->execute('course.restore', $course, [
            'title' => $course->title,
        ]);

        return $course->fresh();
    }
}
