<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Exam\GradeEssayAnswerAction;
use App\Actions\Exam\PublishExamGradesAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamAttemptDetailResource;
use App\Models\ExamAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttemptController extends Controller
{
    public function __construct(
        private readonly GradeEssayAnswerAction $gradeEssayAnswer,
        private readonly PublishExamGradesAction $publishGradesAction,
    ) {
    }

    public function show(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);

        $attempt->load(['exam', 'student', 'answers', 'attemptQuestions']);

        return $this->success(new ExamAttemptDetailResource($attempt), 'Attempt retrieved.');
    }

    public function gradeEssay(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);

        $validated = $request->validate([
            'question_id' => ['required', 'integer'],
            'awarded_points' => ['required', 'integer', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $updatedAttempt = $this->gradeEssayAnswer->execute(
            $request->user(),
            $attempt,
            (int) $validated['question_id'],
            (int) $validated['awarded_points'],
            $validated['feedback'] ?? null
        );

        return $this->success(
            new ExamAttemptDetailResource($updatedAttempt->load(['exam', 'student', 'answers'])),
            'Essay answer graded successfully.'
        );
    }

    public function publishGrades(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);

        $publishedAttempt = $this->publishGradesAction->execute($request->user(), $attempt);

        return $this->success(
            new ExamAttemptDetailResource($publishedAttempt->load(['exam', 'student', 'answers'])),
            'Exam grades published successfully.'
        );
    }
}
