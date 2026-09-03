<?php

namespace Database\Factories;

use App\Enums\ExamAttemptStatus;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExamAttempt>
 */
class ExamAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'student_id' => User::factory(),
            'attempt_number' => 1,
            'started_at' => now(),
            'submitted_at' => null,
            'expires_at' => now()->addMinutes(30),
            'score' => null,
            'percentage' => null,
            'status' => ExamAttemptStatus::InProgress->value,
        ];
    }

    /**
     * Mark the attempt as submitted.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ExamAttemptStatus::Submitted->value,
            'submitted_at' => now(),
        ]);
    }
}
