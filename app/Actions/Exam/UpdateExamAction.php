<?php

namespace App\Actions\Exam;

use App\Models\Exam;

/**
 * Updates the editable settings of an exam.
 */
class UpdateExamAction
{
    public function execute(Exam $exam, array $data): Exam
    {
        $exam->fill($data);
        $exam->save();

        return $exam->fresh();
    }
}
