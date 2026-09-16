<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an exam template: `quantity` questions of `question_type`, each
 * worth `points`. A template is the ordered list of its sections.
 */
class ExamTemplateSection extends Model
{
    /** @use HasFactory<\Database\Factories\ExamTemplateSectionFactory> */
    use HasFactory;

    protected $fillable = [
        'exam_template_id',
        'question_type',
        'quantity',
        'points',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'question_type' => QuestionType::class,
            'quantity' => 'integer',
            'points' => 'integer',
            'position' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ExamTemplate::class, 'exam_template_id');
    }

    /**
     * Whether the questions this section produces are free-text.
     *
     * MCQ questions are scaffolded with blank options so the teacher only has
     * to type the choices and mark the right one; an essay question gets no
     * options at all, only an optional reference answer.
     */
    public function isEssay(): bool
    {
        return $this->question_type === QuestionType::Essay;
    }
}
