<?php

namespace App\Actions\Progress;

use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Computes a student's aggregate progress for one or more courses from their
 * lesson progress records. Progress is never stored redundantly.
 */
class CalculateCourseProgressAction
{
    /**
     * @return array{total: int, completed: int, percentage: int}
     */
    public function forCourse(Course $course, User $student): array
    {
        $total = $this->publishedLessonCount($course);

        if ($total === 0) {
            return ['total' => 0, 'completed' => 0, 'percentage' => 0];
        }

        $completed = LessonProgress::query()
            ->where('student_id', $student->getKey())
            ->where('completed', true)
            ->whereHas('lesson', fn ($q) => $q->whereIn('id', $this->publishedLessonIds($course)))
            ->count();

        return $this->result($completed, $total);
    }

    /**
     * Efficiently compute progress for many courses in one query.
     *
     * @param  Collection<int, Course>|array<int, Course>  $courses
     * @return Collection<int, array{total: int, completed: int, percentage: int}>
     */
    public function forCourses(Collection|array $courses, User $student): Collection
    {
        $courses = $courses instanceof Collection ? $courses : collect($courses);

        // Count only published lessons, matching what a student can access.
        $courses->loadCount(['lessons' => fn ($q) => $q->where('is_published', true)]);

        $courseIds = $courses->pluck('id');

        $completedByCourse = LessonProgress::query()
            ->selectRaw('units.course_id as course_id, count(*) as total')
            ->where('lesson_progress.student_id', $student->getKey())
            ->where('lesson_progress.completed', true)
            ->whereIn('units.course_id', $courseIds)
            ->join('lessons', 'lesson_progress.lesson_id', '=', 'lessons.id')
            ->join('units', 'lessons.unit_id', '=', 'units.id')
            ->groupBy('units.course_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->course_id => (int) $row->total]);

        return $courses->mapWithKeys(function (Course $course) use ($completedByCourse) {
            $completed = (int) ($completedByCourse[$course->getKey()] ?? 0);

            return [$course->getKey() => $this->result($completed, (int) $course->lessons_count)];
        });
    }

    private function publishedLessonCount(Course $course): int
    {
        return $this->publishedLessonQuery($course)->count();
    }

    /**
     * @return array<int>
     */
    private function publishedLessonIds(Course $course): array
    {
        return $this->publishedLessonQuery($course)->pluck('id')->all();
    }

    private function publishedLessonQuery(Course $course)
    {
        return $course->lessons()->where('is_published', true);
    }

    /**
     * @return array{total: int, completed: int, percentage: int}
     */
    private function result(int $completed, int $total): array
    {
        return [
            'total' => $total,
            'completed' => $completed,
            'percentage' => $total === 0 ? 0 : (int) round($completed / $total * 100),
        ];
    }
}
