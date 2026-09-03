<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

class QuestionPolicy
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

    public function view(User $user, Question $question): bool
    {
        return $this->canManage($user, $question);
    }

    public function update(User $user, Question $question): bool
    {
        return $this->canManage($user, $question);
    }

    public function delete(User $user, Question $question): bool
    {
        return $this->canManage($user, $question);
    }

    private function canManage(User $user, Question $question): bool
    {
        return $user->hasRole('admin') || $question->exam->isManagedBy($user);
    }
}
