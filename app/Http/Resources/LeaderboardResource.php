<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Leaderboard row resource. Exposes only safe, public, ranked fields; the
 * student identity is a public display name.
 *
 * Teacher/admin requests also receive internal identifiers and the
 * qualification flag so they can act on a row; student-facing responses never
 * receive those.
 *
 * @mixin \App\Models\CompetitionResult
 */
class LeaderboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'rank' => $this->rank,
            'student_display_name' => $this->whenLoaded(
                'participant.student',
                fn () => $this->participant->student->publicDisplayName()
            ),
            'score' => $this->score,
            'percentage' => $this->percentage,
            'completion_time' => $this->completion_time,
        ];

        if ($this->isTeacherView($request)) {
            $data['participant_id'] = $this->participant_id;
            $data['qualified'] = $this->qualified;
        }

        return $data;
    }

    private function isTeacherView(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        return $user->hasRole('teacher') || $user->hasRole('admin');
    }
}
