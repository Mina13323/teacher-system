<?php

namespace Database\Factories;

use App\Enums\CompetitionRankingType;
use App\Enums\CompetitionScoringType;
use App\Enums\CompetitionStatus;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Competition>
 */
class CompetitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'exam_id' => Exam::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => CompetitionStatus::Draft->value,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(7),
            'max_participants' => null,
            'scoring_type' => CompetitionScoringType::HighestScore->value,
            'ranking_type' => CompetitionRankingType::ScoreDesc->value,
        ];
    }

    /**
     * Indicate that the competition is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompetitionStatus::Published->value,
        ]);
    }

    /**
     * Mark the competition as active within its current window.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompetitionStatus::Active->value,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ]);
    }

    /**
     * Mark the competition as ended.
     */
    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompetitionStatus::Ended->value,
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subHour(),
        ]);
    }

    /**
     * Mark the competition as archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompetitionStatus::Archived->value,
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->subDays(2),
        ]);
    }
}
