<?php

namespace App\Http\Controllers\Student;

use App\Actions\Exam\ExpireExamAttemptAction;
use App\Actions\Exam\SaveExamAnswerAction;
use App\Actions\Exam\SubmitExamAttemptAction;
use App\Actions\Exam\TerminateExamAttemptAction;
use App\Enums\ExamAttemptStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitExamAnswerRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Http\Resources\ExamResultResource;
use App\Models\ExamAttempt;
use App\Services\Integrity\IntegrityRiskConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttemptController extends Controller
{
    public function __construct(
        private readonly SaveExamAnswerAction $saveAnswer,
        private readonly SubmitExamAttemptAction $submitAttempt,
        private readonly ExpireExamAttemptAction $expireAttempt,
        private readonly TerminateExamAttemptAction $terminateAttempt,
    ) {
    }

    public function show(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);

        // Route binding read the attempt in this request: no second read.
        $attempt = $this->expireAttempt->execute($attempt, justRetrieved: true);

        $attempt->loadMissing('exam');

        $relations = ['answers.selectedOptions', 'attemptQuestions.attemptOptions', 'integritySetting'];

        // The post-publication review shows each question's reference answer;
        // load those in one query instead of one per question.
        if ($attempt->grades_published_at !== null && ($attempt->exam === null || $attempt->exam->answerReviewEnabled())) {
            $relations[] = 'attemptQuestions.question:id,reference_answer';
        }

        $attempt->load($relations);

        return $this->success(new ExamAttemptResource($attempt), 'Attempt retrieved.');
    }

    public function answer(SubmitExamAnswerRequest $request, ExamAttempt $attempt): JsonResponse
    {
        // Defence in depth. Ownership is already enforced by
        // SubmitExamAnswerRequest::authorize(), but this endpoint must not
        // depend on a FormRequest being present to stay safe.
        $this->authorize('update', $attempt);

        $answer = $this->saveAnswer->save(
            $attempt,
            $request->integer('question_id'),
            $request->filled('option_id') ? $request->integer('option_id') : null,
            $request->input('answer_text'),
            // `has()` (not `filled()`): an explicitly empty set means "clear the
            // selection", while an absent key keeps the legacy single-select path.
            $request->has('option_ids') ? array_map('intval', (array) $request->input('option_ids')) : null,
            $request->input('explanation'),
            $request->exists('explanation')
        );

        if ($request->boolean('compact_response')) {
            // Acknowledge only the saved question, from what was just stored
            // under the attempt lock. Saves no re-read of the attempt and a
            // 15-37 KB snapshot per answer; the client already holds the rest.
            return $this->success([
                'id' => $attempt->id,
                'status' => ExamAttemptStatus::InProgress->value,
                'expires_at' => $attempt->expires_at?->toISOString(),
                'question' => [
                    'id' => $request->integer('question_id'),
                    'selected_option_id' => $answer?->option_id,
                    'selected_option_ids' => $answer ? $answer->selectedOptionIds() : [],
                    'answer_text' => $answer?->answer_text,
                    'explanation' => $answer?->explanation,
                ],
            ], 'Answer saved.');
        }

        $attempt->loadMissing('exam');
        $attempt->load(['answers.selectedOptions', 'attemptQuestions.attemptOptions', 'integritySetting']);

        return $this->success(new ExamAttemptResource($attempt), 'Answer saved.');
    }

    public function submit(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);

        // The owner is the signed-in user: hand the loaded model over so the
        // result notification does not read the user row again. (Only for the
        // owner; an admin acting on the attempt is not its student.)
        if ((int) $attempt->student_id === (int) $request->user()->getKey()) {
            $attempt->setRelation('student', $request->user());
        }

        $attempt = $this->submitAttempt->execute($attempt);

        $attempt->loadMissing('exam');

        return $this->success(new ExamResultResource($attempt), 'Exam submitted.');
    }

    public function heartbeat(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);

        // Fast path: one conditional UPDATE records the heartbeat only while
        // the attempt is in progress and clearly before both deadlines (its
        // own expires_at and the exam window end). Whole seconds are compared
        // strictly, so the fast path never accepts an attempt that
        // ExamAttempt::isExpired() would call expired. It cannot revive a
        // finalized attempt: the status is re-checked by the UPDATE itself.
        // Soft-deleted exams count too, as in the attempt's exam relation.
        $now = now();
        $touched = ExamAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', ExamAttemptStatus::InProgress->value)
            ->where('expires_at', '>', $now)
            ->whereNotExists(function ($query) use ($now) {
                $query->selectRaw('1')
                    ->from('exams')
                    ->whereColumn('exams.id', 'exam_attempts.exam_id')
                    ->whereNotNull('exams.ends_at')
                    ->where('exams.ends_at', '<=', $now);
            })
            ->update(['last_heartbeat_at' => $now, 'updated_at' => $now]);

        if ($touched === 1) {
            $attempt->setRawAttributes(array_merge($attempt->getAttributes(), [
                'last_heartbeat_at' => $now,
                'updated_at' => $now,
            ]), true);
        } else {
            // Anything else (expired, finalized, at the deadline second):
            // the full path, which finalizes an expired attempt.
            $attempt = $this->expireAttempt->execute($attempt);

            if (! $attempt->status->isInProgress()) {
                return $this->error('Attempt is no longer in progress.', 422);
            }

            $attempt->last_heartbeat_at = now();
            $attempt->save();
        }

        $now = now();

        return $this->success([
            'last_heartbeat_at' => $attempt->last_heartbeat_at->toISOString(),
            'status' => $attempt->status->value,
            // Lets the client keep its countdown on the server's clock and
            // pick up a deadline the server changed (for example on resume).
            'expires_at' => $attempt->expires_at?->toISOString(),
            'server_time' => $now->toISOString(),
            'server_time_ms' => (int) $now->getTimestampMs(),
        ], 'Heartbeat received.');
    }

    /**
     * Threshold-gated integrity termination (P0.5).
     *
     * A single violation never ends the attempt: this endpoint only finalizes
     * an attempt whose server-side warning count has EXCEEDED the frozen
     * threshold (repeated confirmed violations). Below the threshold — or for
     * any interruption like a network drop or closed tab — it reports the
     * current warning state and the attempt continues untouched. No integrity
     * event is fabricated here; the termination itself records a single honest
     * THRESHOLD_TERMINATION event.
     */
    public function terminate(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);

        $attempt->loadMissing('integritySetting');

        $threshold = app(IntegrityRiskConfig::class)
            ->resolveWarningThreshold($attempt->integritySetting?->violation_warning_threshold);

        $warningCount = (int) $attempt->violation_warnings;
        $terminateOnViolation = (bool) ($attempt->integritySetting?->terminate_on_violation ?? true);
        $thresholdExceeded = $warningCount > $threshold && $terminateOnViolation;

        if ($attempt->status->isInProgress() && $thresholdExceeded) {
            $terminated = $this->terminateAttempt->execute($attempt, 'THRESHOLD_TERMINATION', [
                'warning_count' => $warningCount,
                'warning_threshold' => $threshold,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $terminated->load('exam');

            return $this->success(new ExamResultResource($terminated), 'Attempt terminated after exceeding the violation warning threshold.');
        }

        // Not terminated: report the warning state so the client can warn the
        // student and keep the attempt going (recoverable interruption).
        return $this->success([
            'terminated' => false,
            'status' => $attempt->status?->value,
            'warning_count' => $warningCount,
            'warning_threshold' => $threshold,
        ], 'Attempt not terminated: warning threshold not reached.');
    }
}
