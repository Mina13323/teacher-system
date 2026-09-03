<?php

namespace App\Actions\Exam;

use App\Enums\ExamStatus;
use App\Models\Exam;

/**
 * Archives an exam, removing it from student availability.
 */
class ArchiveExamAction
{
    public function execute(Exam $exam): Exam
    {
        $exam->status = ExamStatus::Archived->value;
        $exam->save();

        return $exam->fresh();
    }
}
