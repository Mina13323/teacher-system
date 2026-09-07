<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\EnrollmentService;

class LessonPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function view(User $user, Lesson $lesson): bool
    {
        return $this->canManageCourse($user, $lesson->unit->course);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('lessons.create');
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->canManageCourse($user, $lesson->unit->course)
            && $user->hasPermissionTo('lessons.update');
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->canManageCourse($user, $lesson->unit->course)
            && $user->hasPermissionTo('lessons.delete');
    }

    /**
     * Student lesson-content access. A student may browse a lesson's content
     * (e.g. its published video list) only if the account is active, the lesson
     * and its course are published, and the student is enrolled in the course.
     * This is the server-side boundary for the student lesson content endpoints.
     */
    public function access(User $user, Lesson $lesson): bool
    {
        if (! $user->hasRole('student') && ! $user->hasRole('admin')) {
            return false;
        }

        if (! $user->isActive()) {
            return false;
        }

        if (! $lesson->isPublished()) {
            return false;
        }

        $course = $lesson->unit->course;

        if (! $course->status->isPublished()) {
            return false;
        }

        return app(EnrollmentService::class)->isEnrolled($user, $course->getKey());
    }

    private function canManageCourse(User $user, Course $course): bool
    {
        return $user->hasRole('admin') || $course->isOwnedBy($user);
    }
}
