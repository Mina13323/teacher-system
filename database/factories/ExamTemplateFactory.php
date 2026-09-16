<?php

namespace Database\Factories;

use App\Models\ExamTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamTemplate>
 */
class ExamTemplateFactory extends Factory
{
    protected $model = ExamTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Template '.fake()->unique()->numberBetween(1, 999999),
            'description' => null,
            'created_by' => User::factory(),
            'is_system' => false,
            'preset_key' => null,
        ];
    }

    /**
     * A seeded, read-only template belonging to nobody.
     */
    public function system(string $presetKey = 'test_preset'): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'System Template',
            'created_by' => null,
            'is_system' => true,
            'preset_key' => $presetKey,
        ]);
    }

    /**
     * Attach sections, e.g. [
     *   ['question_type' => 'single_choice', 'quantity' => 30, 'points' => 1],
     *   ['question_type' => 'essay', 'quantity' => 20, 'points' => 2],
     * ].
     *
     * @param  list<array{question_type: string, quantity: int, points: int}>  $sections
     */
    public function withSections(array $sections): static
    {
        return $this->afterCreating(function (ExamTemplate $template) use ($sections) {
            foreach ($sections as $position => $section) {
                $template->sections()->create([
                    'question_type' => $section['question_type'],
                    'quantity' => $section['quantity'],
                    'points' => $section['points'],
                    'position' => $position + 1,
                ]);
            }
        });
    }
}
