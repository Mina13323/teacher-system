<?php

namespace App\Actions\Progress;

use App\Enums\RoadmapLessonStatus;
use App\Enums\RoadmapStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;

/**
 * Builds the interactive roadmap representation for a student's enrolled course.
 *
 * Lesson locking is intentionally simple: lessons are considered in position
 * order; a lesson is "completed" if its progress is finished, "in_progress" if
 * progress exists but is unfinished, "available" if it is the first unfinished
 * lesson, and "locked" for anything after the first unfinished lesson.
 */
class BuildCourseRoadmapAction
{
    public function execute(Course $course, User $student): Course
    {
        // Only published lessons are visible on a student roadmap.
        $course->load([
            'units' => fn ($q) => $q->orderBy('position'),
            'units.lessons' => fn ($q) => $q->where('is_published', true)->orderBy('position'),
        ]);

        /** @var array<int, LessonProgress> $progressByLesson */
        $progressByLesson = LessonProgress::query()
            ->where('student_id', $student->getKey())
            ->whereIn('lesson_id', $course->units->pluck('lessons')->flatten()->pluck('id'))
            ->get()
            ->keyBy('lesson_id')
            ->all();

        $statusByLesson = $this->computeLessonStatuses($course, $progressByLesson);
        $course->roadmap = $this->buildUnits($course, $statusByLesson, $progressByLesson);
        $course->roadmap_progress = $this->courseProgress($course, $statusByLesson);

        return $course;
    }

    /**
     * @param  array<int, LessonProgress>  $progressByLesson
     * @return array<int, string>
     */
    private function computeLessonStatuses(Course $course, array $progressByLesson): array
    {
        $statuses = [];
        $hasUnfinishedBefore = false;

        foreach ($course->units as $unit) {
            foreach ($unit->lessons as $lesson) {
                $progress = $progressByLesson[$lesson->getKey()] ?? null;

                if ($progress !== null && $progress->completed) {
                    $statuses[$lesson->getKey()] = RoadmapLessonStatus::Completed->value;
                    continue;
                }

                if ($progress !== null && $progress->progress_percentage > 0) {
                    $statuses[$lesson->getKey()] = RoadmapLessonStatus::InProgress->value;
                    $hasUnfinishedBefore = true;
                    continue;
                }

                $statuses[$lesson->getKey()] = $hasUnfinishedBefore
                    ? RoadmapLessonStatus::Locked->value
                    : RoadmapLessonStatus::Available->value;

                $hasUnfinishedBefore = true;
            }
        }

        return $statuses;
    }

    /**
     * @param  array<int, string>  $statusByLesson
     * @param  array<int, LessonProgress>  $progressByLesson
     * @return array<int, array<string, mixed>>
     */
    private function buildUnits(Course $course, array $statusByLesson, array $progressByLesson): array
    {
        return $course->units->map(function ($unit) use ($statusByLesson, $progressByLesson) {
            $lessons = $unit->lessons->map(function (Lesson $lesson) use ($statusByLesson, $progressByLesson) {
                $progress = $progressByLesson[$lesson->getKey()] ?? null;

                return [
                    'id' => $lesson->getKey(),
                    'title' => $lesson->title,
                    'status' => $statusByLesson[$lesson->getKey()],
                    'completed' => $progress !== null && $progress->completed,
                    'progress_percentage' => $progress->progress_percentage ?? 0,
                ];
            });

            $completed = $lessons->where('completed', true)->count();
            $total = $lessons->count();

            return [
                'id' => $unit->getKey(),
                'title' => $unit->title,
                'position' => $unit->position,
                'progress' => $total === 0 ? 0 : (int) round($completed / $total * 100),
                'status' => $this->unitStatus($completed, $total),
                'lessons' => $lessons->values(),
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, string>  $statusByLesson
     */
    private function courseProgress(Course $course, array $statusByLesson): int
    {
        $lessons = $course->units->flatMap(fn ($unit) => $unit->lessons);
        $total = $lessons->count();

        if ($total === 0) {
            return 0;
        }

        $completed = $lessons->filter(
            fn (Lesson $lesson) => $statusByLesson[$lesson->getKey()] === RoadmapLessonStatus::Completed->value
        )->count();

        return (int) round($completed / $total * 100);
    }

    private function unitStatus(int $completed, int $total): string
    {
        if ($total > 0 && $completed === $total) {
            return RoadmapStatus::Completed->value;
        }

        if ($completed > 0) {
            return RoadmapStatus::InProgress->value;
        }

        return RoadmapStatus::NotStarted->value;
    }
}
