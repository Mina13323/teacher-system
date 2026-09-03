<?php

namespace App\Http\Controllers\Teacher;

use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Exam;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
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
        ]);

        return $this->success(
            new QuestionResource($question->load('options')),
            'Question created.',
            201
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
