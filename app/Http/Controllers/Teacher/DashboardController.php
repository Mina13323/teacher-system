<?php

namespace App\Http\Controllers\Teacher;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        // Staff scoping so an Assistant's dashboard reflects the Teacher's LMS.
        $ownerIds = $request->user()->staffOwnerIds();

        /** @return Builder<Course> */
        $courses = function () use ($ownerIds): Builder {
            $query = Course::query();

            if ($ownerIds !== null) {
                $query->whereIn('created_by', $ownerIds);
            }

            return $query;
        };

        $courseIds = $courses()->pluck('id');

        // Every figure is one aggregate query. This used to load every course the
        // teacher owns — with three withCount subqueries attached to each — and
        // then sum the results in PHP, purely to render five numbers.
        $examIds = \App\Models\Exam::query()->whereIn('course_id', $courseIds)->pluck('id');
        return $this->success([
            'courses_count' => $courses()->count(),
            'published_count' => $courses()->where('status', CourseStatus::Published->value)->count(),
            'draft_count' => $courses()->where('status', CourseStatus::Draft->value)->count(),
            'total_enrollments' => $courseIds->isEmpty()
                ? 0
                : Enrollment::query()->whereIn('course_id', $courseIds)->count(),
            'total_units' => $courseIds->isEmpty()
                ? 0
                : Unit::query()->whereIn('course_id', $courseIds)->count(),
            'total_lessons' => $courseIds->isEmpty()
                ? 0
                : Lesson::query()
                    ->whereHas('unit', fn ($q) => $q->whereIn('course_id', $courseIds))
                    ->count(),
            'recent_courses' => CourseResource::collection(
                $courses()
                    ->with('creator')
                    ->withCount(['units', 'lessons', 'enrollments'])
                    ->latest()
                    ->limit(5)
                    ->get()
            ),

            // §37 — the work queue: things that need the teacher's hand.
            'active_attempts_count' => \App\Models\ExamAttempt::query()
                ->whereIn('exam_id', $examIds)
                ->where('status', 'in_progress')
                ->count(),
            'pending_essay_grading' => \App\Models\ExamAttempt::query()
                ->whereIn('exam_id', $examIds)
                ->where('status', 'grading')
                ->whereHas('answers', fn ($a) => $a->whereNull('graded_at'))
                ->count(),
            'flagged_attempts' => \App\Models\ExamAttempt::query()
                ->whereIn('exam_id', $examIds)
                ->where('integrity_status', 'flagged')
                ->with(['student:id,name,student_code', 'exam:id,title'])
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'student' => $a->student?->name,
                    'exam' => $a->exam?->title,
                    'end_reason' => $a->end_reason,
                    'resumed_at' => $a->resumed_at?->toISOString(),
                    'url' => "/teacher/integrity/attempts/{$a->id}",
                ]),
            'upcoming_exams' => \App\Models\Exam::query()
                ->whereIn('course_id', $courseIds)
                ->where('status', 'published')
                ->where('starts_at', '>=', now())
                ->where('starts_at', '<=', now()->addDays(7))
                ->orderBy('starts_at')
                ->limit(5)
                ->get(['id', 'title', 'starts_at', 'ends_at'])
                ->map(fn ($e) => [
                    'id' => $e->id, 'title' => $e->title,
                    'starts_at' => $e->starts_at?->toISOString(),
                    'url' => "/teacher/exams/{$e->id}",
                ]),
            'assignments_needing_grading' => \App\Models\AssignmentSubmission::query()
                ->whereHas('assignment', fn ($a) => $a->whereIn('course_id', $courseIds))
                ->where('status', 'submitted')
                ->count(),
        ], 'Dashboard retrieved.');
    }
}
