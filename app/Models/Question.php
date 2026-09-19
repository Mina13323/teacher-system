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
     */
    public function hasValidSingleCorrectOption(): bool
    {
        if ($this->isEssay()) {
            return true;
        }

        $correctCount = $this->options()->where('is_correct', true)->count();

        return $correctCount >= 1;
    }
}
