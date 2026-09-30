<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamStatus;
use App\Models\Exam;

/**
 * Archives an exam, removing it from student availability.
 */
class ArchiveExamAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    public function execute(Exam $exam): Exam
    {
        $exam->status = ExamStatus::Archived->value;
        $exam->save();

        $this->auditLog->execute('exam.archive', $exam, [
            'course_id' => $exam->course_id,
            'title' => $exam->title,
        ]);

        return $exam->fresh();
    }
}
