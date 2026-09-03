<?php

namespace App\Models;

use App\Enums\IntegrityReviewDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamIntegrityReview extends Model
{
    /** @use HasFactory<\Database\Factories\ExamIntegrityReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'reviewed_by',
        'decision',
        'note',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => IntegrityReviewDecision::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
