<?php

namespace App\Policies;

use App\Models\StudentNote;
use App\Models\User;

/**
 * PHASE 4 §32 — Student notes are PRIVATE: every ability is owner-only.
 * Not even the course teacher can read a student's personal notes.
 */
class StudentNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('student');
    }

    public function view(User $user, StudentNote $note): bool
    {
        return $note->user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('student');
    }

    public function update(User $user, StudentNote $note): bool
    {
        return $note->user_id === $user->getKey();
    }

    public function delete(User $user, StudentNote $note): bool
    {
        return $note->user_id === $user->getKey();
    }
}
