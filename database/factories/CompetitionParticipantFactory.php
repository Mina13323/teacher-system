<?php

namespace Database\Factories;

use App\Enums\CompetitionParticipantStatus;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CompetitionParticipant>
 */
class CompetitionParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'student_id' => User::factory(),
            'joined_at' => now(),
            'status' => CompetitionParticipantStatus::Registered->value,
        ];
    }

    /**
     * Mark the participant as disqualified.
     */
    public function disqualified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompetitionParticipantStatus::Disqualified->value,
        ]);
    }

    /**
     * Mark the participant as completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompetitionParticipantStatus::Completed->value,
        ]);
    }
}
