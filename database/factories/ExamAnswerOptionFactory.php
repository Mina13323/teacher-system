<?php

namespace Database\Factories;

use App\Models\ExamAnswer;
use App\Models\ExamAnswerOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamAnswerOption>
 */
class ExamAnswerOptionFactory extends Factory
{
    protected $model = ExamAnswerOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'answer_id' => ExamAnswer::factory(),
            'option_id' => $this->faker->unique()->numberBetween(1, 999999),
        ];
    }
}
