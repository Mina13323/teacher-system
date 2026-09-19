<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Exam\SyncExamQuestionsAction;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateQuestionRequest;
use App\Http\Requests\SyncExamQuestionsRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Requests\UploadQuestionImageRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Exam;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QuestionController extends Controller
{
    /**
     * Hard ceiling on questions per exam.
     *
     * The bulk editor already validates its payload at max:200
     * (SyncExamQuestionsRequest), so single-question creation was the only way
     * to grow past it. Enforcing the same ceiling here keeps
     * GET .../questions — an editor payload that must return every row so the
     * teacher can reorder the paper — genuinely bounded, rather than bounded by
     * silently truncating rows the teacher can no longer see.
     */
    public const MAX_QUESTIONS_PER_EXAM = 200;
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
        if ($exam->questions()->count() >= self::MAX_QUESTIONS_PER_EXAM) {
            return $this->error(
                'An exam cannot contain more than '.self::MAX_QUESTIONS_PER_EXAM.' questions.',
                422
            );
        }

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

    public function uploadImage(UploadQuestionImageRequest $request, Question $question): JsonResponse
    {
        $oldPath = $question->image_path;
        $question->image_path = $request->file('image')->store('exam-question-images', 'public');
        $question->save();

        if ($oldPath && $oldPath !== $question->image_path) {
            Storage::disk('public')->delete($oldPath);
        }

        return $this->success(new QuestionResource($question->fresh()->load('options')), 'Question image uploaded.');
    }

    public function removeImage(Request $request, Question $question): JsonResponse
    {
        $this->authorize('update', $question);

        if ($question->image_path) {
            Storage::disk('public')->delete($question->image_path);
            $question->image_path = null;
            $question->save();
        }

        return $this->success(new QuestionResource($question->fresh()->load('options')), 'Question image removed.');
    }

    public function destroy(Question $question): JsonResponse
    {
        $this->authorize('delete', $question);

        $question->delete();

        return $this->success(null, 'Question deleted.');
    }
}
