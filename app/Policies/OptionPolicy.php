<?php

namespace App\Policies;

use App\Models\Option;
use App\Models\User;

class OptionPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('teacher') || $user->hasRole('admin');
    }

    public function update(User $user, Option $option): bool
    {
        return $this->canManage($user, $option);
    }

    public function delete(User $user, Option $option): bool
    {
        return $this->canManage($user, $option);
    }

    private function canManage(User $user, Option $option): bool
    {
        return $user->hasRole('admin') || $option->question->exam->isManagedBy($user);
    }
}
