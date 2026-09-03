<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Exam\ArchiveExamAction;
use App\Actions\Exam\CreateExamAction;
use App\Actions\Exam\DeleteExamAction;
use App\Actions\Exam\PublishExamAction;
use App\Actions\Exam\UpdateExamAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateExamRequest;
use App\Http\Requests\UpdateExamRequest;
use App\Http\Resources\ExamAttemptDetailResource;
use App\Http\Resources\ExamDetailResource;
use App\Http\Resources\ExamResource;
use App\Models\Course;
use App\Models\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function __construct(
        private readonly CreateExamAction $createExam,
        private readonly UpdateExamAction $updateExam,
        private readonly DeleteExamAction $deleteExam,
        private readonly PublishExamAction $publishExam,
        private readonly ArchiveExamAction $archiveExam,
    ) {
    }

    public function index(Request $request, Course $course): JsonResponse
    {
        $this->authorize('viewAny', Exam::class);
        $this->authorize('view', $course);

        $exams = $course->exams()
            ->with(['course', 'creator'])
            ->withCount(['questions', 'attempts'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(ExamResource::collection($exams), 'Exams retrieved.');
    }

    public function store(CreateExamRequest $request, Course $course): JsonResponse
    {
        $exam = $this->createExam->execute($course, $request->user(), $request->validated());

        return $this->success(
            new ExamResource($exam->load(['course', 'creator'])->loadCount(['questions', 'attempts'])),
            'Exam created.',
            201
        );
    }

    public function show(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        $exam->load(['course', 'creator', 'questions.options'])
            ->loadCount(['questions', 'attempts']);

        return $this->success(new ExamDetailResource($exam), 'Exam retrieved.');
    }

    public function update(UpdateExamRequest $request, Exam $exam): JsonResponse
    {
        $exam = $this->updateExam->execute($exam, $request->validated());

        return $this->success(
            new ExamResource($exam->load(['course', 'creator'])->loadCount(['questions', 'attempts'])),
            'Exam updated.'
        );
    }

    public function publish(Exam $exam): JsonResponse
    {
        $this->authorize('update', $exam);

        $exam = $this->publishExam->execute($exam);

        return $this->success(
            new ExamResource($exam->load(['course', 'creator'])->loadCount(['questions', 'attempts'])),
            'Exam published.'
        );
    }

    public function archive(Exam $exam): JsonResponse
    {
        $this->authorize('update', $exam);

        $exam = $this->archiveExam->execute($exam);

        return $this->success(
            new ExamResource($exam->load(['course', 'creator'])->loadCount(['questions', 'attempts'])),
            'Exam archived.'
        );
    }

    public function destroy(Exam $exam): JsonResponse
    {
        $this->authorize('delete', $exam);

        $this->deleteExam->execute($exam);

        return $this->success(null, 'Exam deleted.');
    }

    public function attempts(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('viewAttempts', $exam);

        $attempts = $exam->attempts()
            ->with(['student', 'exam'])
            ->with('answers')
            ->latest('started_at')
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            ExamAttemptDetailResource::collection($attempts),
            'Attempts retrieved.'
        );
    }
}
