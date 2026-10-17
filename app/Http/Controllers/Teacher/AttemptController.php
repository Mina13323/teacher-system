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
        $this->authorize('viewStaff', $attempt);



        $attempt->load(['exam', 'student', 'answers.selectedOptions', 'attemptQuestions.attemptOptions']);

        return $this->success(new ExamAttemptDetailResource($attempt), 'Attempt retrieved.');
    }

    public function gradeEssay(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('grade', $attempt);

        $validated = $request->validate([
            'question_id' => ['required', 'integer'],
            'awarded_points' => ['required', 'integer', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:2000'],
            // The grade version the grader loaded (optimistic concurrency).
            'expected_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'compact_response' => ['sometimes', 'boolean'],
        ]);

        try {
            $graded = $this->gradeEssayAnswer->grade(
                $request->user(),
                $attempt,
                (int) $validated['question_id'],
                (int) $validated['awarded_points'],
                $validated['feedback'] ?? null,
                $validated['expected_version'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'awarded_points' => [$e->getMessage()],
            ]);
        }

        if ($request->boolean('compact_response')) {
            // The grading workspace: only the saved answer and the attempt
            // summary it changed, never the whole attempt.
            $updated = $graded['attempt'];

            return $this->success(array_merge([
                'attempt_id' => $updated->id,
                'question_id' => (int) $validated['question_id'],
                'saved' => $graded['changed'],
            ], GradeEssayAnswerAction::gradeState($graded['answer']), [
                'attempt' => [
                    'id' => $updated->id,
                    'status' => $updated->displayStatus(),
                    'score' => $updated->score,
                    'percentage' => $updated->percentage,
                    'grades_published' => $updated->grades_published_at !== null,
                    'ungraded_essays' => $graded['result']['ungraded_essays'],
                ],
            ]), 'Essay answer graded successfully.');
        }

        return $this->success(
            new ExamAttemptDetailResource($graded['attempt']->fresh()->load(['exam', 'student', 'answers.selectedOptions', 'attemptQuestions.attemptOptions'])),
            'Essay answer graded successfully.'
        );
    }

    public function publishGrades(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('publishGrades', $attempt);

        $publishedAttempt = $this->publishGradesAction->execute($request->user(), $attempt);

        return $this->success(
            new ExamAttemptDetailResource($publishedAttempt->load(['exam', 'student', 'answers.selectedOptions', 'attemptQuestions.attemptOptions'])),
            'Exam grades published successfully.'
        );
    }
}
