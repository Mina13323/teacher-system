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
            'grades_published_at' => 'datetime',
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
        return $this->hasMany(ExamAnswer::class, 'attempt_id');
    }

    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(ExamAttemptQuestion::class, 'attempt_id')
            ->orderBy('position');
    }

    public function integritySetting(): HasOne
    {
        return $this->hasOne(ExamAttemptIntegritySetting::class, 'attempt_id');
    }

    public function integrityEvents(): HasMany
    {
        return $this->hasMany(ExamIntegrityEvent::class, 'attempt_id');
    }

    public function integrityReviews(): HasMany
    {
        return $this->hasMany(ExamIntegrityReview::class, 'attempt_id');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->student_id === $user->getKey();
    }

    /**
     * Whether this attempt counts towards "how many times students sat the
     * exam" in analytics.
     *
     * `in_progress` was never handed in and `expired` never completed, so both
     * are excluded. `grading` and `published` are handed-in attempts and MUST
     * count — filtering on `submitted` alone silently dropped every attempt
     * that had been through essay grading, which understated every figure on
     * every analytics screen.
     */
    public function countsAsAttempt(): bool
    {
        return in_array($this->status?->value, ExamAttemptStatus::submittedValues(), true);
    }

    /**
     * Whether this attempt's score is final and therefore safe to average.
     *
     * While an attempt is still `grading`, CalculateExamResultAction counts
     * ungraded essay points as zero against the full point total, so its
     * percentage is a partial number that would drag every average down. It
     * counts as an attempt but never as a score.
     */
    public function hasFinalScore(): bool
    {
        return in_array($this->status?->value, ExamAttemptStatus::scoredValues(), true)
            && $this->percentage !== null;
    }

    /**
     * Query-builder counterpart to countsAsAttempt().
     */
    public function scopeSubmittedForReporting($query)
    {
        return $query->whereIn('status', ExamAttemptStatus::submittedValues());
    }

    /**
     * Query-builder counterpart to hasFinalScore().
     */
    public function scopeWithFinalScore($query)
    {
        return $query
            ->whereIn('status', ExamAttemptStatus::scoredValues())
            ->whereNotNull('percentage');
    }

    /**
     * Whether this attempt's result has been released to the student.
     *
     * The score is written at submit time, but a student must not see it until
     * the teacher publishes grades — the same gate ExamResultResource and
     * ExamAttemptResource apply.
     */
    public function resultIsPublished(): bool
    {
        return $this->grades_published_at !== null;
    }

    /**
     * The backend is the source of truth for expiration.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Server-derived "multiple suspicious events" condition. This requires the
     * integrity events relation to be loaded and adds NO synthetic risk; it is
     * informational only.
     */
    public function isMultipleSuspicious(): bool
    {
        return app(\App\Services\Integrity\IntegrityRiskConfig::class)
            ->isMultipleSuspicious($this->integrityEvents);
    }
}
