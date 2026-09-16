<?php

namespace Database\Seeders;

use App\Enums\QuestionType;
use App\Models\ExamTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the built-in exam template catalog.
 *
 * Idempotent: rows are keyed on `preset_key`, so re-seeding updates the seeded
 * definitions without duplicating them and without touching any template a
 * teacher created themselves.
 *
 * The `name` and `description` columns hold the English copy. The UI renders
 * these through `examTemplates.presets.<preset_key>` so a built-in template
 * reads correctly in Arabic as well; a teacher-created template has no
 * preset_key and is shown from `name` verbatim.
 */
class ExamTemplateSeeder extends Seeder
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'preset_key' => 'standard_30_20',
                'name' => 'Standard Exam — 30 MCQ + 20 Essay',
                'description' => '30 multiple-choice questions worth 1 mark each, plus 20 essay questions worth 2 marks each. 70 marks in total.',
                'sections' => [
                    ['question_type' => QuestionType::SingleChoice->value, 'quantity' => 30, 'points' => 1],
                    ['question_type' => QuestionType::Essay->value, 'quantity' => 20, 'points' => 2],
                ],
            ],
            [
                'preset_key' => 'standard_20_10',
                'name' => 'Standard Exam — 20 MCQ + 10 Essay',
                'description' => 'A shorter standard paper: 20 multiple-choice questions at 1 mark and 10 essay questions at 2 marks. 40 marks in total.',
                'sections' => [
                    ['question_type' => QuestionType::SingleChoice->value, 'quantity' => 20, 'points' => 1],
                    ['question_type' => QuestionType::Essay->value, 'quantity' => 10, 'points' => 2],
                ],
            ],
            [
                'preset_key' => 'mcq_50',
                'name' => 'MCQ Paper — 50 questions',
                'description' => 'Fifty multiple-choice questions worth 1 mark each, auto-graded on submission. 50 marks in total.',
                'sections' => [
                    ['question_type' => QuestionType::SingleChoice->value, 'quantity' => 50, 'points' => 1],
                ],
            ],
            [
                'preset_key' => 'mcq_20',
                'name' => 'MCQ Paper — 20 questions',
                'description' => 'Twenty multiple-choice questions worth 2 marks each. 40 marks in total.',
                'sections' => [
                    ['question_type' => QuestionType::SingleChoice->value, 'quantity' => 20, 'points' => 2],
                ],
            ],
            [
                'preset_key' => 'balanced_15_5',
                'name' => 'Balanced — 15 MCQ + 5 Essay',
                'description' => '15 multiple-choice questions at 2 marks and 5 essay questions at 4 marks. 50 marks in total.',
                'sections' => [
                    ['question_type' => QuestionType::SingleChoice->value, 'quantity' => 15, 'points' => 2],
                    ['question_type' => QuestionType::Essay->value, 'quantity' => 5, 'points' => 4],
                ],
            ],
            [
                'preset_key' => 'essay_5',
                'name' => 'Essay Paper — 5 questions',
                'description' => 'Five long-form essay questions worth 4 marks each, all graded manually. 20 marks in total.',
                'sections' => [
                    ['question_type' => QuestionType::Essay->value, 'quantity' => 5, 'points' => 4],
                ],
            ],
            [
                'preset_key' => 'quick_quiz_10',
                'name' => 'Quick Quiz — 10 MCQ',
                'description' => 'Ten multiple-choice questions worth 1 mark each. Ideal for a short in-lesson check. 10 marks in total.',
                'sections' => [
                    ['question_type' => QuestionType::SingleChoice->value, 'quantity' => 10, 'points' => 1],
                ],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $definition) {
            $sections = $definition['sections'];

            $template = ExamTemplate::updateOrCreate(
                ['preset_key' => $definition['preset_key']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                    // Seeded content belongs to nobody, so no teacher can edit
                    // or delete it.
                    'created_by' => null,
                ]
            );

            // Re-seeding replaces the section list wholesale so an edited
            // definition takes effect instead of leaving stale rows behind.
            $template->sections()->delete();

            foreach ($sections as $position => $section) {
                $template->sections()->create([
                    'question_type' => $section['question_type'],
                    'quantity' => $section['quantity'],
                    'points' => $section['points'],
                    'position' => $position + 1,
                ]);
            }
        }
    }
}
