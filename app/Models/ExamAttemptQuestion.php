<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAttemptQuestion extends Model
{
    /** @use HasFactory<\Database\Factories\ExamAttemptQuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'question_id',
        'question_text',
        'question_type',
        'points',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'position' => 'integer',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function attemptOptions(): HasMany
    {
        return $this->hasMany(ExamAttemptOption::class, 'attempt_question_id')
            ->orderBy('position');
    }
}
