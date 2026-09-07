<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use App\Models\Video;
use App\Services\EnrollmentService;

class VideoPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    /**
     * Staff view (teacher/admin) of a video's management metadata.
     */
    public function view(User $user, Video $video): bool
    {
        return $this->canManageCourse($user, $video->lesson->unit->course);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('lessons.create');
    }

    public function update(User $user, Video $video): bool
    {
        return $this->canManageCourse($user, $video->lesson->unit->course)
            && $user->hasPermissionTo('lessons.update');
    }

    public function delete(User $user, Video $video): bool
    {
        return $this->canManageCourse($user, $video->lesson->unit->course)
            && $user->hasPermissionTo('lessons.delete');
    }

    /**
     * Student video playback authorization. A student may obtain a playback
     * session for a video only when EVERY condition holds:
     *
     *   - the account is active,
     *   - the student is enrolled in the video's course,
     *   - the course is published,
     *   - the lesson is published,
     *   - the video is published,
     *   - the video belongs to that lesson (and therefore that course).
     *
     * This is the server-side security boundary. It is re-checked on every
     * playback request, so deactivation / unenrollment / unpublishing never
     * leaks into a stale grant.
     */
    public function play(User $user, Video $video): bool
    {
        if (! $user->hasRole('student') && ! $user->hasRole('admin')) {
            return false;
        }

        if (! $user->isActive()) {
            return false;
        }

        if (! $video->isPublished()) {
            return false;
        }

        $lesson = $video->lesson;

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
