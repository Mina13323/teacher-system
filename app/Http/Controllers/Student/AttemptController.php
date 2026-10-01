<?php

namespace App\Http\Controllers\Student;

use App\Actions\Exam\ExpireExamAttemptAction;
use App\Actions\Exam\SaveExamAnswerAction;
use App\Actions\Exam\SubmitExamAttemptAction;
use App\Actions\Exam\TerminateExamAttemptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitExamAnswerRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Http\Resources\ExamResultResource;
use App\Models\ExamAttempt;
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

        $attempt = $this->expireAttempt->execute($attempt);

        $attempt->load(['exam', 'answers.selectedOptions', 'attemptQuestions.attemptOptions', 'integritySetting']);

        return $this->success(new ExamAttemptResource($attempt), 'Attempt retrieved.');
    }

    public function answer(SubmitExamAnswerRequest $request, ExamAttempt $attempt): JsonResponse
    {
        // Defence in depth. Ownership is already enforced by
        // SubmitExamAnswerRequest::authorize(), but this endpoint must not
        // depend on a FormRequest being present to stay safe.
        $this->authorize('update', $attempt);

        $attempt = $this->saveAnswer->execute(
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

        $attempt->load(['exam', 'answers.selectedOptions', 'attemptQuestions.attemptOptions', 'integritySetting']);

        return $this->success(new ExamAttemptResource($attempt), 'Answer saved.');
    }

    public function submit(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);

        $attempt = $this->submitAttempt->execute($attempt);

        $attempt->load('exam');

        return $this->success(new ExamResultResource($attempt), 'Exam submitted.');
    }

    public function heartbeat(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);

        $attempt = $this->expireAttempt->execute($attempt);

        if (! $attempt->status->isInProgress()) {
            return $this->error('Attempt is no longer in progress.', 422);
        }

        $attempt->last_heartbeat_at = now();
        $attempt->save();

        return $this->success([
            'last_heartbeat_at' => $attempt->last_heartbeat_at->toISOString(),
            'status' => $attempt->status->value,
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

        $threshold = app(\App\Services\Integrity\IntegrityRiskConfig::class)
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
