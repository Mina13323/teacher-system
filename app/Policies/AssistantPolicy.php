<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for assistant account management in a teacher-owned LMS.
 *
 * An assistant is operational staff created by (and reporting to) the main
 * Teacher. A teacher may manage an assistant account they created; an admin may
 * manage any account. This keeps assistant management inside the teacher/user
 * with the same "who created it" scoping used elsewhere, and never grants an
 * assistant independent teacher powers.
 */
class AssistantPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('teacher')
            || $user->hasRole('admin')
            || $user->hasPermissionTo('assistants.view');
    }

    public function view(User $user, User $assistant): bool
    {
        return $this->managesAssistant($user, $assistant);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('assistants.create');
    }

    public function update(User $user, User $assistant): bool
    {
        return $this->managesAssistant($user, $assistant)
            && $user->hasPermissionTo('assistants.manage');
    }

    public function manage(User $user, User $assistant): bool
    {
        return $this->managesAssistant($user, $assistant)
            && $user->hasPermissionTo('assistants.manage');
    }

    public function delete(User $user, User $assistant): bool
    {
        // Hard deletion of any account is admin-only; deactivation is preferred.
        return $user->hasRole('admin');
    }

    /**
     * A user may manage an assistant account only if they created it (or admin).
     */
    private function managesAssistant(User $user, User $assistant): bool
    {
        return $user->hasRole('admin')
            || ((int) $assistant->created_by === (int) $user->getKey());
    }
}
