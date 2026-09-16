<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Exam\SyncExamQuestionsAction;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateQuestionRequest;
use App\Http\Requests\SyncExamQuestionsRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Exam;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    public function __construct(private readonly SyncExamQuestionsAction $syncQuestions)
    {
    }

    public function index(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        $questions = $exam->questions()->with('options')->get();

        return $this->success(QuestionResource::collection($questions), 'Questions retrieved.');
    }

    public function store(CreateQuestionRequest $request, Exam $exam): JsonResponse
    {
        $question = Question::create([
            'exam_id' => $exam->getKey(),
            'question_text' => $request->validated('question_text'),
            'type' => $request->validated('type', QuestionType::SingleChoice->value),
            'points' => $request->validated('points', 1),
            'position' => $request->validated('position', (int) $exam->questions()->max('position') + 1),
            'reference_answer' => $request->validated('reference_answer'),
        ]);

        return $this->success(
            new QuestionResource($question->load('options')),
            'Question created.',
            201
        );
    }

    /**
     * Saves an entire question paper in one request.
     *
     * A template hands the teacher dozens of blank questions at once; authoring
     * them through the single-question endpoint would mean one round trip per
     * row. Rows carrying an `id` are updated, the rest are created, so the
     * authoring screen can be saved repeatedly without duplicating questions.
     */
    public function bulk(SyncExamQuestionsRequest $request, Exam $exam): JsonResponse
    {
        $questions = $this->syncQuestions->execute($exam, $request->validated('questions'));

        return $this->success(
            QuestionResource::collection($questions),
            'Questions saved.'
        );
    }

    public function show(Request $request, Question $question): JsonResponse
    {
        $this->authorize('view', $question);

        return $this->success(
            new QuestionResource($question->load('options')),
            'Question retrieved.'
        );
    }

    public function update(UpdateQuestionRequest $request, Question $question): JsonResponse
    {
        $question->fill($request->validated());
        $question->save();

        return $this->success(
            new QuestionResource($question->fresh()->load('options')),
            'Question updated.'
        );
    }

    public function destroy(Question $question): JsonResponse
    {
        $this->authorize('delete', $question);

        $question->delete();

        return $this->success(null, 'Question deleted.');
    }
}
