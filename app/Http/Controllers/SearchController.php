<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\Unit;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PHASE 4 §34 — Authorization-safe search across learning content.
 *
 * Results are strictly scoped by role:
 *  - students: published courses they are enrolled in + the published units /
 *    lessons / videos / exams inside those courses;
 *  - staff: their own courses' content + their own students.
 * Private teacher content of other teachers is never searchable.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', 'string', 'in:courses,lessons,videos,exams,students'],
        ]);

        $term = '%'.$data['q'].'%';
        $user = $request->user();
        $types = isset($data['type']) ? [$data['type']] : ['courses', 'lessons', 'videos', 'exams', 'students'];
        $results = [];

        if ($user->isStudent()) {
            $courseIds = $user->enrollments()->where('status', 'active')->pluck('course_id');

            if (in_array('courses', $types, true)) {
                $results['courses'] = Course::query()
                    ->whereIn('id', $courseIds)
                    ->where('status', 'published')
                    ->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term))
                    ->limit(10)->get(['id', 'title'])
                    ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'type' => 'course', 'url' => "/student/courses/{$c->id}"])
                    ->values();
            }
            if (in_array('lessons', $types, true)) {
                $results['lessons'] = Lesson::query()
                    ->where('is_published', true)
                    ->whereHas('unit', fn ($q) => $q->whereIn('course_id', $courseIds))
                    ->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term))
                    ->limit(10)->get(['id', 'title', 'unit_id'])
                    ->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'type' => 'lesson', 'url' => "/student/lessons/{$l->id}"])
                    ->values();
            }
            if (in_array('videos', $types, true)) {
                $results['videos'] = Video::query()
                    ->where('is_published', true)
                    ->whereHas('lesson', fn ($q) => $q->whereHas('unit', fn ($u) => $u->whereIn('course_id', $courseIds)))
                    ->where('title', 'like', $term)
                    ->limit(10)->get(['id', 'title', 'lesson_id'])
                    ->map(fn ($v) => ['id' => $v->id, 'title' => $v->title, 'type' => 'video', 'url' => "/student/lessons/{$v->lesson_id}"])
                    ->values();
            }
            if (in_array('exams', $types, true)) {
                $results['exams'] = Exam::query()
                    ->where('status', 'published')
                    ->whereIn('course_id', $courseIds)
                    ->where('title', 'like', $term)
                    ->limit(10)->get(['id', 'title'])
                    ->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'type' => 'exam', 'url' => "/student/exams/{$e->id}"])
                    ->values();
            }
        } else {
            $managedCourseIds = Course::query()
                ->where('created_by', $user->getKey())
                ->pluck('id');
            if ($user->hasRole('admin')) {
                $managedCourseIds = Course::query()->pluck('id');
            }

            if (in_array('courses', $types, true)) {
                $results['courses'] = Course::query()
                    ->whereIn('id', $managedCourseIds)
                    ->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term))
                    ->limit(10)->get(['id', 'title'])
                    ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'type' => 'course', 'url' => "/teacher/courses/{$c->id}"])
                    ->values();
            }
            if (in_array('lessons', $types, true)) {
                $results['lessons'] = Lesson::query()
                    ->whereHas('unit', fn ($q) => $q->whereIn('course_id', $managedCourseIds))
                    ->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term))
                    ->limit(10)->get(['id', 'title', 'unit_id'])
                    ->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'type' => 'lesson', 'url' => "/teacher/courses/{$l->unit?->course_id}"])
                    ->values();
            }
            if (in_array('videos', $types, true)) {
                $results['videos'] = Video::query()
                    ->whereHas('lesson', fn ($q) => $q->whereHas('unit', fn ($u) => $u->whereIn('course_id', $managedCourseIds)))
                    ->where('title', 'like', $term)
                    ->limit(10)->get(['id', 'title', 'lesson_id'])
                    ->map(fn ($v) => ['id' => $v->id, 'title' => $v->title, 'type' => 'video', 'url' => "/teacher/courses"])
                    ->values();
            }
            if (in_array('exams', $types, true)) {
                $results['exams'] = Exam::query()
                    ->whereIn('course_id', $managedCourseIds)
                    ->where('title', 'like', $term)
                    ->limit(10)->get(['id', 'title'])
                    ->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'type' => 'exam', 'url' => "/teacher/exams/{$e->id}"])
                    ->values();
            }
            if (in_array('students', $types, true)) {
                $results['students'] = User::query()
                    ->whereHas('roles', fn ($q) => $q->where('name', 'student'))
                    ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->when(! $user->hasRole('admin'), function ($q) use ($managedCourseIds) {
                        $q->whereHas('enrollments', fn ($e) => $e->whereIn('course_id', $managedCourseIds));
                    })
                    ->limit(10)->get(['id', 'name', 'email'])
                    ->map(fn ($s) => ['id' => $s->id, 'title' => $s->name, 'type' => 'student', 'url' => "/teacher/students/{$s->id}"])
                    ->values();
            }
        }

        return $this->success([
            'query' => $data['q'],
            'results' => $results,
            'total' => collect($results)->flatten(1)->count(),
        ], 'Search completed.');
    }
}
