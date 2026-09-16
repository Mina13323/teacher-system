<?php

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\ExamTemplate;
use App\Models\ExamTemplateSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamTemplateSection>
 */
class ExamTemplateSectionFactory extends Factory
{
    protected $model = ExamTemplateSection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_template_id' => ExamTemplate::factory(),
            'question_type' => QuestionType::SingleChoice->value,
            'quantity' => 1,
            'points' => 1,
            'position' => 1,
        ];
    }

    /**
     * An essay section — free-text questions carry no options.
     */
    public function essay(int $quantity = 5, int $points = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'question_type' => QuestionType::Essay->value,
            'quantity' => $quantity,
            'points' => $points,
        ]);
    }
}
