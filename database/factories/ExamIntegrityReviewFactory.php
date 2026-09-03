<?php

namespace Database\Factories;

use App\Enums\IntegrityReviewDecision;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExamIntegrityReview>
 */
class ExamIntegrityReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attempt_id' => ExamAttempt::factory(),
            'reviewed_by' => User::factory(),
            'decision' => IntegrityReviewDecision::Cleared->value,
            'note' => null,
            'reviewed_at' => now(),
        ];
    }
}
