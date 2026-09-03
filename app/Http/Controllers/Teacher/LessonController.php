<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Lesson\CreateLessonAction;
use App\Actions\Lesson\ReorderLessonsAction;
use App\Actions\Lesson\UpdateLessonAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLessonRequest;
use App\Http\Requests\ReorderLessonsRequest;
use App\Http\Requests\UpdateLessonRequest;
use App\Http\Resources\LessonDetailResource;
use App\Http\Resources\LessonResource;
use App\Models\Lesson;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;

class LessonController extends Controller
{
    public function __construct(
        private readonly CreateLessonAction $createLesson,
        private readonly UpdateLessonAction $updateLesson,
        private readonly ReorderLessonsAction $reorderLessons,
    ) {
    }

    public function index(Unit $unit): JsonResponse
    {
        $this->authorize('view', $unit);

        $lessons = $unit->lessons()
            ->withCount('videos')
            ->orderBy('position')
            ->get();

        return $this->success(LessonResource::collection($lessons), 'Lessons retrieved.');
    }

    public function store(CreateLessonRequest $request, Unit $unit): JsonResponse
    {
        $lesson = $this->createLesson->execute($unit, $request->validated());

        return $this->success(
            new LessonResource($lesson->loadCount('videos')),
            'Lesson created.',
            201
        );
    }

    public function show(Lesson $lesson): JsonResponse
    {
        $this->authorize('view', $lesson);

        $lesson->load(['unit', 'videos'])
            ->loadCount('videos');

        return $this->success(new LessonDetailResource($lesson), 'Lesson retrieved.');
    }

    public function update(UpdateLessonRequest $request, Lesson $lesson): JsonResponse
    {
        $lesson = $this->updateLesson->execute($lesson, $request->validated());

        return $this->success(
            new LessonDetailResource($lesson->load('videos')->loadCount('videos')),
            'Lesson updated.'
        );
    }

    public function publish(Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $lesson);

        $lesson->update(['is_published' => true]);

        return $this->success(new LessonResource($lesson), 'Lesson published.');
    }

    public function unpublish(Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $lesson);

        $lesson->update(['is_published' => false]);

        return $this->success(new LessonResource($lesson), 'Lesson unpublished.');
    }

    public function reorder(ReorderLessonsRequest $request, Unit $unit): JsonResponse
    {
        $this->reorderLessons->execute($unit, $request->validated('ordered_ids'));

        return $this->success(null, 'Lessons reordered.');
    }

    public function destroy(Lesson $lesson): JsonResponse
    {
        $this->authorize('delete', $lesson);

        $lesson->delete();

        return $this->success(null, 'Lesson deleted.');
    }
}
