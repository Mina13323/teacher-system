<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionResult extends Model
{
    /** @use HasFactory<\Database\Factories\CompetitionResultFactory> */
    use HasFactory;

    protected $fillable = [
        'competition_id',
        'participant_id',
        'attempt_id',
        'score',
        'percentage',
        'completion_time',
        'completed_at',
        'rank',
        'qualified',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'percentage' => 'integer',
            'completion_time' => 'integer',
            'rank' => 'integer',
            'qualified' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(CompetitionParticipant::class, 'participant_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }
}
