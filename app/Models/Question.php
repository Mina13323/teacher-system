<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    /** @use HasFactory<\Database\Factories\QuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'question_text',
        'type',
        'points',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'points' => 'integer',
            'position' => 'integer',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(Option::class)
            ->orderBy('options.position');
    }

    /**
     * Whether the question has exactly one correct option (required before
     * an exam containing it may be published).
     */
    public function hasValidSingleCorrectOption(): bool
    {
        $correctCount = $this->options()->where('is_correct', true)->count();

        return $correctCount === 1;
    }
}
