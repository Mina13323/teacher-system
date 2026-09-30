<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One selected option inside a student's answer to a choice question.
 *
 * `exam_answers.option_id` remains the historical single-select column; these
 * rows are the normalized selection set used for `multiple_choice` questions
 * (and mirrored for `single_choice` going forward). Grading treats the set as
 * authoritative when present and falls back to `option_id` for legacy rows.
 */
class ExamAnswerOption extends Model
{
    /** @use HasFactory<\Database\Factories\ExamAnswerOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'answer_id',
        'option_id',
    ];

    protected $casts = [
        'option_id' => 'integer',
    ];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(ExamAnswer::class, 'answer_id');
    }
}
