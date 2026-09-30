<?php

namespace App\Policies;

use App\Models\LessonAttachment;
use App\Models\User;

class LessonAttachmentPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function update(User $user, LessonAttachment $attachment): bool
    {
        return $attachment->lesson ? $user->can('update', $attachment->lesson) : false;
    }

    public function delete(User $user, LessonAttachment $attachment): bool
    {
        return $this->update($user, $attachment);
    }

    /**
     * Download: staff of the course, or a student who can access the lesson
     * (LessonPolicy::access checks enrollment, publication, active account).
     */
    public function viewFile(User $user, LessonAttachment $attachment): bool
    {
        if (! $attachment->lesson) {
            return false;
        }

        return $user->can('update', $attachment->lesson)
            || $user->can('access', $attachment->lesson);
    }
}
