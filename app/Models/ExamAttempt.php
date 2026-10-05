<?php

namespace App\Models;

use App\Enums\AttemptOutcome;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityReviewDecision;
use App\Enums\IntegrityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamAttempt extends Model
{
    /** @use HasFactory<\Database\Factories\ExamAttemptFactory> */
    use HasFactory, SoftDeletes;

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
        'last_heartbeat_at',
        // Server-controlled integrity signals; never writable from client input.
        'integrity_status',
        'risk_score',
        // Full-precision percentage (pass/fail decisions); display `percentage`
        // stays a rounded integer. Server-set at grading time only.
        'raw_percentage',
        // Interruption warning counter (server-derived from integrity events).
        'violation_warnings',
        // How the attempt ended (submitted_by_student, auto_submit_at_deadline,
        // expired, integrity_threshold, ...). Server-set only.
        'end_reason',
        'resumed_at',
        'resumed_by',
        'resume_note',
        'rules_acknowledged_at',
        'previous_end_reason',
        'previous_expires_at',
        'time_restored_seconds',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExamAttemptStatus::class,
            'integrity_status' => IntegrityStatus::class,
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'scored_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'rules_acknowledged_at' => 'datetime',
            'grades_published_at' => 'datetime',
            'score' => 'integer',
            'percentage' => 'integer',
            'raw_percentage' => 'float',
            'pass_percentage' => 'integer',
            'risk_score' => 'integer',
            'violation_warnings' => 'integer',
        'resumed_at' => 'datetime',
        'previous_expires_at' => 'datetime',
        'time_restored_seconds' => 'integer',
        ];
    }

    public function exam(): BelongsTo
    {
        // withTrashed: historical attempts must keep showing their exam even
        // after the exam is archived/soft-deleted.
        return $this->belongsTo(Exam::class)->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);
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

    public function makeUpAssignment(): HasOne
    {
        return $this->hasOne(ExamMakeUpAssignment::class, 'attempt_id');
    }

    /** Duration actually spent according to server timestamps (in seconds). */
    public function durationSeconds(): int
    {
        if ($this->started_at === null) {
            return 0;
        }

        $endedAt = $this->submitted_at ?? $this->expires_at ?? now();

        return max(0, (int) $endedAt->getTimestamp() - (int) $this->started_at->getTimestamp());
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
     * Virtual/display status derived from authoritative timestamps.
     * Allows GET requests to render expired/auto-submitting state accurately
     * without performing synchronous database mutations or grading.
     */
    public function displayStatus(): string
    {
        if ($this->status === ExamAttemptStatus::InProgress && $this->isExpired()) {
            $exam = $this->relationLoaded('exam') ? $this->exam : null;
            $autoSubmit = $exam === null || $exam->autoSubmitsAtDeadline();

            return $autoSubmit ? ExamAttemptStatus::Submitted->value : ExamAttemptStatus::Expired->value;
        }

        return $this->status?->value ?? ExamAttemptStatus::InProgress->value;
    }

    /**
     * The backend is the source of truth for expiration.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return true;
        }

        if ($this->exam?->ends_at !== null && $this->exam->ends_at->isPast()) {
            return true;
        }

        return false;
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

    // ------------------------------------------------------------------
    // Official outcome — the ONE source of truth for pass/fail semantics.
    // Every screen (student result, teacher lists, analytics, exports) must
    // read the outcome from here so the same attempt can never be "Passed"
    // on one screen and "Failed" on another. See AttemptOutcome for the
    // documented resolution order.
    // ------------------------------------------------------------------

    public function outcome(): AttemptOutcome
    {
        // 1. Never-graded, time-expired attempt (strict 'expire' policy).
        if ($this->status === ExamAttemptStatus::Expired || ($this->status === ExamAttemptStatus::InProgress && $this->isExpired() && ! ($this->exam?->autoSubmitsAtDeadline() ?? true))) {
            return AttemptOutcome::Expired;
        }

        // 2/3. Integrity: a teacher-confirmed violation disqualifies; an
        // unresolved automatic flag means pending review — never a silent
        // pass AND never an automatic fail.
        if ($this->integrity_status === IntegrityStatus::Flagged) {
            return $this->hasConfirmedIntegrityViolation()
                ? AttemptOutcome::Disqualified
                : AttemptOutcome::PendingReview;
        }

        // 4. Grades not published yet (awaiting essay grading/publication).
        if ($this->grades_published_at === null) {
            return AttemptOutcome::PendingReview;
        }

        // 5. Definitive academic result on full-precision percentage.
        return $this->meetsPassThreshold()
            ? AttemptOutcome::Passed
            : AttemptOutcome::Failed;
    }

    public function isPassed(): bool
    {
        return $this->outcome() === AttemptOutcome::Passed;
    }

    /**
     * Whether a teacher review explicitly confirmed the integrity violation
     * (review decision FLAGGED), which turns a flag into a disqualification.
     */
    public function hasConfirmedIntegrityViolation(): bool
    {
        if ($this->relationLoaded('integrityReviews')) {
            return $this->integrityReviews
                ->contains(fn ($review) => $review->decision === IntegrityReviewDecision::Flagged->value);
        }

        return $this->integrityReviews()
            ->where('decision', IntegrityReviewDecision::Flagged->value)
            ->exists();
    }

    /**
     * Pass threshold comparison on RAW precision. Rounded display values never
     * decide the academic outcome. Legacy rows (raw_percentage NULL) compare
     * the stored rounded percentage — exactly the historical rule — so no
     * past result changes.
     */
    public function meetsPassThreshold(): bool
    {
        if ($this->pass_percentage === null || $this->percentage === null) {
            return false;
        }

        if ($this->raw_percentage !== null) {
            return (float) $this->raw_percentage >= (float) $this->pass_percentage;
        }

        return (int) $this->percentage >= (int) $this->pass_percentage;
    }
}
