<?php

namespace App\Actions\Exam;

use App\Models\Exam;

/**
 * Deletes an exam and (via cascading) its questions, options, snapshots and
 * attempts.
 */
class DeleteExamAction
{
    public function execute(Exam $exam): void
    {
        $exam->delete();
    }
}
