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
        $grading = $this->gradingWork($examIds);

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
            // Essay ANSWERS still without a grade in handed-in attempts (blank
            // essays included). It used to count attempts with any answer
            // lacking graded_at, which choice answers never set.
            'pending_essay_grading' => $grading['ungraded'],
            'essays_graded_zero' => $grading['graded_zero'],
            'attempts_ready_to_publish' => $grading['ready_to_publish'],
            'attempts_published' => $grading['published'],
            // Exams with essays left to grade, linking to the grading workspace.
            'grading_queue' => $grading['exams'],
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

    /**
     * Essay-grading work across the given exams, in two aggregate queries
     * (never one per exam, attempt or student). Only handed-in attempts count.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $examIds
     * @return array{ungraded: int, graded_zero: int, ready_to_publish: int, published: int, exams: list<array<string, mixed>>}
     */
    private function gradingWork(\Illuminate\Support\Collection $examIds): array
    {
        $handedIn = \App\Enums\ExamAttemptStatus::submittedValues();

        $perExam = \Illuminate\Support\Facades\DB::table('exam_attempt_questions as aq')
            ->join('exam_attempts as a', 'a.id', '=', 'aq.attempt_id')
            ->join('exams as e', 'e.id', '=', 'a.exam_id')
            ->leftJoin('exam_answers as ans', function ($join) {
                $join->on('ans.attempt_id', '=', 'aq.attempt_id')->on('ans.question_id', '=', 'aq.question_id');
            })
            ->whereIn('a.exam_id', $examIds)
            ->whereNull('a.deleted_at')
            ->whereIn('a.status', $handedIn)
            ->where('aq.question_type', \App\Enums\QuestionType::Essay->value)
            ->groupBy('a.exam_id', 'e.title')
            ->selectRaw('a.exam_id, e.title,
                SUM(CASE WHEN ans.points_earned IS NULL THEN 1 ELSE 0 END) as ungraded,
                SUM(CASE WHEN ans.points_earned = 0 THEN 1 ELSE 0 END) as graded_zero')
            ->get();

        $attempts = \Illuminate\Support\Facades\DB::table('exam_attempts')
            ->whereIn('exam_id', $examIds)
            ->whereNull('deleted_at')
            ->whereIn('status', $handedIn)
            ->selectRaw("
                SUM(CASE WHEN grades_published_at IS NULL AND status = 'submitted' THEN 1 ELSE 0 END) as ready_to_publish,
                SUM(CASE WHEN grades_published_at IS NOT NULL THEN 1 ELSE 0 END) as published")
            ->first();

        return [
            'ungraded' => (int) $perExam->sum('ungraded'),
            'graded_zero' => (int) $perExam->sum('graded_zero'),
            'ready_to_publish' => (int) ($attempts->ready_to_publish ?? 0),
            'published' => (int) ($attempts->published ?? 0),
            'exams' => $perExam
                ->filter(fn ($row) => (int) $row->ungraded > 0)
                ->sortByDesc(fn ($row) => (int) $row->ungraded)
                ->take(5)
                ->map(fn ($row) => [
                    'exam_id' => (int) $row->exam_id,
                    'title' => $row->title,
                    'ungraded' => (int) $row->ungraded,
                    'url' => "/teacher/exams/{$row->exam_id}/grading?status=ungraded",
                ])
                ->values()
                ->all(),
        ];
    }
}
