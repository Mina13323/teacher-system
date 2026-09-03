<?php

namespace App\Models;

use App\Enums\IntegrityEventType;
use App\Enums\IntegritySeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamIntegrityEvent extends Model
{
    /** @use HasFactory<\Database\Factories\ExamIntegrityEventFactory> */
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'event_type',
        'occurred_at',
        'metadata',
        'severity',
        'risk_points',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => IntegrityEventType::class,
            'severity' => IntegritySeverity::class,
            'occurred_at' => 'datetime',
            'risk_points' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }
}
