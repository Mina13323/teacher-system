<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamMakeUpAssignmentStatus;
use App\Models\Exam;
use App\Models\ExamMakeUpAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevokeExamMakeUpAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    public function execute(User $actor, Exam $exam, ExamMakeUpAssignment $assignment): ExamMakeUpAssignment
    {
        return DB::transaction(function () use ($actor, $exam, $assignment): ExamMakeUpAssignment {
            $locked = ExamMakeUpAssignment::query()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->exam_id !== (int) $exam->getKey()) {
                throw ValidationException::withMessages([
                    'assignment' => ['This make-up assignment does not belong to the selected exam.'],
                ]);
            }

            if (! $locked->isAvailable()) {
                throw ValidationException::withMessages([
                    'assignment' => ['Only an unused make-up assignment can be revoked.'],
                ]);
            }

            $locked->status = ExamMakeUpAssignmentStatus::Revoked;
            $locked->active_key = null;
            $locked->save();

            $this->audit->execute('exam.makeup.revoke', $locked, [
                'exam_id' => (string) $exam->getKey(),
                'student_id' => (string) $locked->student_id,
            ], $actor);

            return $locked->load('student');
        });
    }
}
