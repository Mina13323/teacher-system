<?php

namespace App\Models;

use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamAttempt extends Model
{
    /** @use HasFactory<\Database\Factories\ExamAttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'student_id',
        'attempt_number',
        'started_at',
        'submitted_at',
        'expires_at',
        'score',
        'percentage',
        'status',
        // The exam's pass threshold frozen at attempt start, so later edits to
        // the live exam do not retroactively change this attempt's pass/fail.
        'pass_percentage',
        // Internal single-active-attempt guard: `{student_id}:{exam_id}` while
        // in progress, null otherwise. Only set within actions, never from input.
        'active_key',
        // Server-controlled integrity signals; never writable from client input.
        'integrity_status',
        'risk_score',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExamAttemptStatus::class,
            'integrity_status' => IntegrityStatus::class,
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'expires_at' => 'datetime',
            'score' => 'integer',
            'percentage' => 'integer',
            'pass_percentage' => 'integer',
            'risk_score' => 'integer',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(ExamAttemptQuestion::class)
            ->orderBy('position');
    }

    public function integritySetting(): HasOne
    {
        return $this->hasOne(ExamAttemptIntegritySetting::class, 'attempt_id');
    }

    public function integrityEvents(): HasMany
    {
        return $this->hasMany(ExamIntegrityEvent::class);
    }

    public function integrityReviews(): HasMany
    {
        return $this->hasMany(ExamIntegrityReview::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->student_id === $user->getKey();
    }

    /**
     * The backend is the source of truth for expiration.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
