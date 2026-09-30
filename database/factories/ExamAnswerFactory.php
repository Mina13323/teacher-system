<?php

namespace Database\Factories;

use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamAnswer>
 */
class ExamAnswerFactory extends Factory
{
    protected $model = ExamAnswer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attempt_id' => ExamAttempt::factory(),
            'question_id' => Question::factory(),
            'option_id' => null,
            'is_correct' => null,
            'points_earned' => null,
            'answered_at' => now(),
        ];
    }
}
