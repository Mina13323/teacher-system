<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Actions\Audit\RecordAuditLogAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Soft-delete only the explicitly selected attempts; answers and enrollment
 * history are intentionally left untouched. Active attempts cannot be removed.
 */
class DeleteExamAttemptsAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    /**
     * @param  list<int>  $attemptIds
     * @return list<int>
     */
    public function execute(User $actor, Exam $exam, array $attemptIds, ?string $reason = null): array
    {
        return DB::transaction(function () use ($actor, $exam, $attemptIds, $reason): array {
            $ids = array_values(array_unique(array_map('intval', $attemptIds)));
            $attempts = $exam->attempts()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($attempts->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'attempt_ids' => ['One or more selected attempts do not belong to this exam or are already deleted.'],
                ]);
            }

            if ($attempts->contains(fn (ExamAttempt $attempt) =>
                $attempt->status === ExamAttemptStatus::InProgress || $attempt->active_key !== null
            )) {
                throw ValidationException::withMessages([
                    'attempt_ids' => ['Active attempts cannot be deleted. Wait until they are submitted or expired.'],
                ]);
            }

            foreach ($attempts as $attempt) {
                $attempt->deleted_by = $actor->getKey();
                $attempt->save();
                $attempt->delete();

                $this->audit->execute('exam.attempt.delete', $attempt, [
                    'exam_id' => (string) $exam->getKey(),
                    'attempt_id' => (string) $attempt->getKey(),
                    'student_id' => (string) $attempt->student_id,
                    'attempt_number' => (string) $attempt->attempt_number,
                    'reason' => (string) ($reason ?? ''),
                ], $actor);
            }

            return $attempts->modelKeys();
        });
    }
}
