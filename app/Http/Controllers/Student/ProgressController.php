<?php

namespace App\Http\Controllers\Student;

use App\Actions\Progress\UpdateLessonProgressAction;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLessonProgressRequest;
use App\Http\Resources\LessonProgressResource;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function __construct(
        private readonly UpdateLessonProgressAction $updateProgress,
    ) {
    }

    public function store(UpdateLessonProgressRequest $request, Lesson $lesson): JsonResponse
    {
        $progress = $this->updateProgress->execute($request->user(), $lesson, $request->validated());

        return $this->success(
            new LessonProgressResource($progress->load('lesson')),
            'Progress updated.'
        );
    }

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $this->assertAccess($request);
        $this->assertLessonPublished($lesson);

        $progress = LessonProgress::query()
            ->where('student_id', $request->user()->getKey())
            ->where('lesson_id', $lesson->getKey())
            ->first();

        $progress ??= new LessonProgress([
            'student_id' => $request->user()->getKey(),
            'lesson_id' => $lesson->getKey(),
            'completed' => false,
            'progress_percentage' => 0,
            'last_position_seconds' => 0,
        ]);

        return $this->success(
            new LessonProgressResource($progress),
            'Progress retrieved.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $enrolledCourseIds = Enrollment::query()
            ->where('student_id', $request->user()->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('course_id');

        $progress = LessonProgress::query()
            ->where('student_id', $request->user()->getKey())
            ->whereHas('lesson.unit', fn ($q) => $q->whereIn('course_id', $enrolledCourseIds))
            ->with(['lesson' => fn ($q) => $q->with('videos')])
            ->orderByDesc('updated_at')
            ->get();

        return $this->success(LessonProgressResource::collection($progress), 'Progress retrieved.');
    }

    /**
     * A student may only track progress for lessons in an active enrolled course.
     */
    private function assertAccess(Request $request): void
    {
        $lesson = $request->route('lesson');

        $enrolled = Enrollment::query()
            ->where('student_id', $request->user()->getKey())
            ->where('course_id', $lesson->unit->course_id)
            ->where('status', EnrollmentStatus::Active->value)
            ->exists();

        abort_unless($enrolled, 403, 'You do not have access to this lesson.');
    }

    private function assertLessonPublished(Lesson $lesson): void
    {
        abort_unless($lesson->isPublished(), 404, 'Lesson not found.');
    }
}
