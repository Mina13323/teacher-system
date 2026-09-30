<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\LessonQuestion;
use App\Models\User;

/**
 * PHASE 4 §31 — Lesson Q&A authorization.
 *
 * Reading threads requires lesson access (enrolled student / admin) or course
 * staff. Questions and answers come from enrolled students; staff answer and
 * moderate. Deletion is moderation (soft) by the author or course staff —
 * never a silent destroy (is_deleted + deleted_by are kept).
 */
class LessonQuestionPolicy
{
    public function viewAny(User $user, Lesson $lesson): bool
    {
        return $this->canSeeLesson($user, $lesson);
    }

    public function create(User $user, Lesson $lesson): bool
    {
        return $user->hasRole('student') && $user->can('access', $lesson);
    }

    public function reply(User $user, Lesson $lesson): bool
    {
        return $this->canSeeLesson($user, $lesson);
    }

    public function delete(User $user, LessonQuestion $question): bool
    {
        $lesson = $question->lesson;
        if ($lesson === null) {
            return false;
        }

        // Author may remove their own post; course staff may moderate.
        return $question->user_id === $user->getKey() || $this->canManage($user, $lesson);
    }

    private function canSeeLesson(User $user, Lesson $lesson): bool
    {
        return $user->can('access', $lesson) || $this->canManage($user, $lesson);
    }

    private function canManage(User $user, Lesson $lesson): bool
    {
        $course = $lesson->unit?->course;

        return $course !== null && $course->isManagedBy($user);
    }
}
