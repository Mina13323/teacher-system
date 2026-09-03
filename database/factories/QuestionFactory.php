<?php

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Question>
 */
class QuestionFactory extends Factory
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
            'question_text' => fake()->sentence(8).'?',
            'type' => QuestionType::SingleChoice->value,
            'points' => 1,
            'position' => 1,
        ];
    }
}
