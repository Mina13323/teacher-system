<?php

namespace App\Models;

use App\Enums\CompetitionParticipantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CompetitionParticipant extends Model
{
    /** @use HasFactory<\Database\Factories\CompetitionParticipantFactory> */
    use HasFactory;

    protected $fillable = [
        'competition_id',
        'student_id',
        'joined_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompetitionParticipantStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function result(): HasOne
    {
        return $this->hasOne(CompetitionResult::class, 'participant_id');
    }

    public function isDisqualified(): bool
    {
        return $this->status->isDisqualified();
    }
}
