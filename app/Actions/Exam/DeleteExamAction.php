<?php

namespace App\Actions\Exam;

use App\Exceptions\ResourceDeletionBlockedException;
use App\Models\Competition;
use App\Models\Exam;
use App\Models\ExamAttempt;

/**
 * Deletes an exam and (via cascading) its questions, options, snapshots and
 * attempts.
 *
 * An exam that is referenced by a competition cannot be deleted: the
 * competition's results depend on that exam's attempts as their source of
 * truth, and deleting the exam would destroy historical competition data. The
 * database enforces this with a RESTRICT FK; we surface it as a clean, friendly
 * error instead of a raw SQL exception.
 *
 * An exam that has any recorded student attempts (submitted, in-progress or
 * expired) likewise cannot be deleted: those attempts carry the frozen snapshot,
 * answers, integrity evidence and result history. Archive the exam instead so
 * that historical assessment data is preserved.
 */
class DeleteExamAction
{
    public function execute(Exam $exam): void
    {
        if (Competition::query()->where('exam_id', $exam->getKey())->exists()) {
            throw new ResourceDeletionBlockedException(
                'This exam is referenced by competitions and cannot be deleted. Archive it or remove those competitions first.'
            );
        }

        if ($exam->attempts()->exists()) {
            throw new ResourceDeletionBlockedException(
                'This exam has student attempts recorded and cannot be deleted. Archive it instead to preserve historical results.'
            );
        }

        $exam->delete();
    }
}
