<?php

namespace Database\Factories;

use App\Models\Competition;
use App\Models\CompetitionParticipant;
use App\Models\ExamAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CompetitionResult>
 */
class CompetitionResultFactory extends Factory
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
            'participant_id' => CompetitionParticipant::factory(),
            'attempt_id' => ExamAttempt::factory(),
            'score' => random_int(1, 100),
            'percentage' => random_int(0, 100),
            'completion_time' => random_int(30, 3600),
            'completed_at' => now(),
            'rank' => null,
            'qualified' => true,
        ];
    }
}
