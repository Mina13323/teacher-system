<?php

namespace App\Http\Controllers\Student;

use App\Actions\Exam\ExpireExamAttemptAction;
use App\Actions\Exam\SaveExamAnswerAction;
use App\Actions\Exam\SubmitExamAttemptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitExamAnswerRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Http\Resources\ExamResultResource;
use App\Models\ExamAttempt;
use Illuminate\Http\JsonResponse;

class AttemptController extends Controller
{
    public function __construct(
        private readonly SaveExamAnswerAction $saveAnswer,
        private readonly SubmitExamAttemptAction $submitAttempt,
        private readonly ExpireExamAttemptAction $expireAttempt,
    ) {
    }

    public function show(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);

        $this->expireAttempt->execute($attempt);

        $attempt->load(['exam', 'answers', 'attemptQuestions.attemptOptions']);

        return $this->success(new ExamAttemptResource($attempt), 'Attempt retrieved.');
    }

    public function answer(SubmitExamAnswerRequest $request, ExamAttempt $attempt): JsonResponse
    {
        // The action validates question_id/option_id against the attempt's
        // frozen snapshot, never the live questions/options tables.
        $attempt = $this->saveAnswer->execute(
            $attempt,
            $request->integer('question_id'),
            $request->integer('option_id')
        );

        $attempt->load(['exam', 'answers', 'attemptQuestions.attemptOptions']);

        return $this->success(new ExamAttemptResource($attempt), 'Answer saved.');
    }

    public function submit(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);

        $attempt = $this->submitAttempt->execute($attempt);

        $attempt->load('exam');

        if ($attempt->exam->show_result_immediately) {
            return $this->success(new ExamResultResource($attempt), 'Exam submitted.');
        }

        return $this->success([
            'attempt_id' => $attempt->id,
            'status' => $attempt->status?->value,
        ], 'Exam submitted.');
    }
}
