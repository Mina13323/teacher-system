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

        $attempt->load(['exam', 'answers', 'attemptQuestions.attemptOptions', 'integritySetting']);

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
            $request->input('answer_text')
        );

        $attempt->load(['exam', 'answers', 'attemptQuestions.attemptOptions']);

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

    public function terminate(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);

        $reason = (string) $request->input('reason', 'TERMINATED_BY_INTEGRITY');
        $terminated = $this->terminateAttempt->execute($attempt, $reason, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $terminated->load('exam');

        return $this->success(new ExamResultResource($terminated), 'Attempt terminated for policy violation.');
    }
}
