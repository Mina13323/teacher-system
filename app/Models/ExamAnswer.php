<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAnswer extends Model
{
    /** @use HasFactory<\Database\Factories\ExamAnswerFactory> */
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'question_id',
        'option_id',
        'is_correct',
        'points_earned',
        'answered_at',
        // Essay answer body. Written through updateOrCreate() in
        // SaveExamAnswerAction, so it must be mass assignable or the student's
        // essay text is silently dropped and the teacher grades a blank.
        'answer_text',
        // Grading metadata. Only ever set server-side by GradeEssayAnswerAction
        // from the authenticated staff user; never taken from request input.
        'feedback',
        'graded_by',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'points_earned' => 'integer',
            'answered_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    /**
     * Normalized selection set (multi-select support). Empty for legacy rows
     * written before the exam_answer_options table existed — those are graded
     * from the single `option_id` column instead.
     */
    public function selectedOptions(): HasMany
    {
        return $this->hasMany(ExamAnswerOption::class, 'answer_id');
    }

    /**
     * The selected option ids, from the normalized set when present, otherwise
     * the legacy single `option_id`. Returns a sorted list for deterministic
     * set comparisons.
     *
     * @return list<int>
     */
    public function selectedOptionIds(): array
    {
        $this->loadMissing('selectedOptions');

        if ($this->selectedOptions->isNotEmpty()) {
            return $this->selectedOptions
                ->pluck('option_id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
        }

        return $this->option_id !== null ? [(int) $this->option_id] : [];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }
}
