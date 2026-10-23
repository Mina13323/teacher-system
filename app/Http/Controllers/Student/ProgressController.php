<?php

namespace App\Http\Controllers\Student;

use App\Actions\Progress\UpdateLessonProgressAction;
use App\Enums\EnrollmentStatus;
use App\Exceptions\LessonNotAccessibleException;
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
    /** Most recent progress rows returned by index(). */
    private const INDEX_LIMIT = 200;

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
        // The canonical lesson-access rules (published lesson and course,
        // lesson capability and active access, enrollment, roadmap) first:
        // this response carries the lesson's content. The older checks below
        // stay, so nothing that was refused before is allowed now.
        $this->authorize('access', $lesson);
        $this->assertAccess($request);
        $this->assertLessonPublished($lesson);

        $progress = LessonProgress::forStudentLesson((int) $request->user()->getKey(), (int) $lesson->getKey());

        return $this->success(
            // load() (not whenLoaded-only): the lesson page reads the title,
            // description and content from this payload — without the relation
            // the page fell back to a generic "Lesson" heading.
            new LessonProgressResource($progress->load('lesson')),
            'Progress retrieved.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $enrolledCourseIds = Enrollment::query()
            ->where('student_id', $request->user()->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('course_id');

        // Joined (not whereHas) and capped: this used to hydrate every row the
        // student had ever touched, each with its lesson and full video list.
        $progress = LessonProgress::query()
            ->select('lesson_progress.*')
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->join('units', 'units.id', '=', 'lessons.unit_id')
            ->whereNull('lessons.deleted_at')
            ->whereNull('units.deleted_at')
            ->where('lesson_progress.student_id', $request->user()->getKey())
            ->whereIn('units.course_id', $enrolledCourseIds)
            ->with(['lesson' => fn ($q) => $q->with('videos')])
            ->orderByDesc('lesson_progress.updated_at')
            ->limit(self::INDEX_LIMIT)
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

        if (! $enrolled) {
            throw new LessonNotAccessibleException();
        }
    }

    private function assertLessonPublished(Lesson $lesson): void
    {
        abort_unless($lesson->isPublished(), 404, 'Lesson not found.');
    }
}
