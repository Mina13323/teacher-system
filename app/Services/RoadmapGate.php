<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;

/**
 * PHASE 4 §35 — Roadmap access gate.
 *
 * LOCKED semantics: lessons are ordered by (unit.position, lesson.position).
 * A lesson is unlocked when it is the first published lesson of the course or
 * when the immediately preceding published lesson is completed by the student.
 * The gate only bites when `courses.roadmap_enforced` is true; by default
 * roadmaps stay informational (current production behaviour). Staff (course
 * teacher, assistants, admins) always preview freely.
 */
final class RoadmapGate
{
    public function allows(User $user, Lesson $lesson): bool
    {
        $course = $lesson->unit?->course;
        if ($course === null) {
            return true; // relation missing — let policy checks decide
        }
        if (! $course->roadmap_enforced) {
            return true; // informational roadmap (default)
        }
        if (! $user->isStudent()) {
            return true; // staff preview
        }

        return $this->isUnlocked($course, $user, $lesson);
    }

    public function isUnlocked(Course $course, User $student, Lesson $lesson): bool
    {
        $ordered = $this->orderedLessons($course);
        $index = $ordered->search(fn (Lesson $l) => $l->getKey() === $lesson->getKey());
        if ($index === false || $index === 0) {
            return true; // unknown lesson (policy will 404) or first lesson
        }

        $previous = $ordered[$index - 1];
        $progress = \App\Models\LessonProgress::query()
            ->where('lesson_id', $previous->getKey())
            ->where('student_id', $student->getKey())
            ->first();

        return $progress !== null && (bool) $progress->completed;
    }

    /** @return \Illuminate\Support\Collection<int, Lesson> */
    private function orderedLessons(Course $course)
    {
        return Lesson::query()
            ->whereHas('unit', fn ($q) => $q->where('course_id', $course->getKey()))
            ->where('is_published', true)
            ->orderBy('position')
            ->get()
            ->sortBy(fn (Lesson $l) => sprintf('%05d.%05d', $l->unit?->position ?? 0, $l->position))
            ->values();
    }
}
