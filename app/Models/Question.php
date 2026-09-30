<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Question extends Model
{
    /** @use HasFactory<\Database\Factories\QuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'question_text',
        'image_path',
        'type',
        'points',
        'position',
        'reference_answer',
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

    public function isEssay(): bool
    {
        return $this->type === QuestionType::Essay;
    }

    public function isMcq(): bool
    {
        return $this->type !== QuestionType::Essay;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    protected static function booted(): void
    {
        static::deleting(function (self $question): void {
            if ($question->image_path) Storage::disk('public')->delete($question->image_path);
        });
    }

    /**
     * Validation check before exam publication.
     *
     * Choice questions must carry a coherent answer key:
     *   single_choice   exactly 1 correct option,
     *   multiple_choice at least 2 correct options.
     * Essays never carry options or an answer key.
     */
    public function hasValidAnswerKey(): bool
    {
        if ($this->isEssay()) {
            return true;
        }

        $correctCount = $this->options()->where('is_correct', true)->count();

        if ($this->type === QuestionType::MultipleChoice) {
            return $correctCount >= 2;
        }

        return $correctCount === 1;
    }

    /**
     * @deprecated Use hasValidAnswerKey(); kept as a stable name for older callers.
     */
    public function hasValidSingleCorrectOption(): bool
    {
        return $this->hasValidAnswerKey();
    }
}
