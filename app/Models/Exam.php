<?php

namespace App\Models;

use App\Enums\ExamStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Exam extends Model
{
    /** @use HasFactory<\Database\Factories\ExamFactory> */
    use HasFactory, \Illuminate\Database\Eloquent\SoftDeletes;

    /**
     * NOTE on dormant schema: the `academic_year` and `academic_subject` columns
     * exist on this table (added by 2026_01_01_000800) but are deliberately NOT
     * part of the active exam domain. Nothing validates, persists, serializes,
     * filters, or renders them — the same is true for the identically named
     * columns on `courses`. Academic year/subject are only implemented on
     * `users` (student lifecycle). They are kept out of $fillable on purpose so
     * they can never be written by accident; if the product later wants
     * year/subject-aware exams, wire them end to end (request validation,
     * actions, resources, teacher UI, filtering, tests) rather than adding them
     * here alone.
     */
    protected $fillable = [
        'course_id',
        'lesson_id',
        'unit_ids',
        'title',
        'description',
        'duration_minutes',
        // Optional official window. Both null => legacy duration-per-attempt.
        'starts_at',
        'ends_at',
        'pass_percentage',
        'max_attempts',
        'status',
        'shuffle_questions',
        'shuffle_options',
        'show_result_immediately',
        // How a passed-deadline attempt is finalized: 'auto_submit' (grade the
        // saved answers) or 'expire' (legacy strict mode, never graded).
        'expiry_mode',
        // Whether students may review answers + answer key + feedback after
        // grades are published.
        'allow_answer_review',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExamStatus::class,
            'duration_minutes' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'pass_percentage' => 'integer',
            'max_attempts' => 'integer',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'show_result_immediately' => 'boolean',
            'allow_answer_review' => 'boolean',
            'unit_ids' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class)->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)
            ->orderBy('questions.position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function makeUpAssignments(): HasMany
    {
        return $this->hasMany(ExamMakeUpAssignment::class, 'exam_id');
    }

    public function integritySetting(): HasOne
    {
        return $this->hasOne(ExamIntegritySetting::class, 'exam_id');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->getKey();
    }

    /**
     * Whether this exam has an official start and/or end window.
     */
    public function isWindowed(): bool
    {
        return $this->starts_at !== null || $this->ends_at !== null;
    }

    /**
     * Whether a passed-deadline attempt is finalized by submitting and grading
     * the saved answers (the fair default) instead of being discarded.
     * NULL/legacy rows behave as 'auto_submit' — the documented phase policy.
     */
    public function autoSubmitsAtDeadline(): bool
    {
        return ($this->expiry_mode ?? 'auto_submit') !== 'expire';
    }

    /**
     * Whether students may review their answers (with correctness + feedback)
     * once grades are published.
     */
    public function answerReviewEnabled(): bool
    {
        return (bool) ($this->allow_answer_review ?? true);
    }

    /**
     * The student's actual deadline must always be the earlier of:
     *   started_at + duration_minutes
     *   exam_window_end (ends_at)
     *
     * In other words:
     *   effective_deadline = min(started_at + duration, ends_at)
     *
     * The exam window end is a hard deadline that active attempts cannot exceed.
     */
    public function calculateAttemptExpiry(Carbon $startedAt): ?Carbon
    {
        $durationMinutes = (int) $this->duration_minutes;
        $candidate = $durationMinutes > 0
            ? $startedAt->copy()->addMinutes($durationMinutes)
            : null;

        if ($this->ends_at !== null) {
            if ($candidate === null) {
                return $this->ends_at->copy();
            }

            return $candidate->lessThan($this->ends_at)
                ? $candidate
                : $this->ends_at->copy();
        }

        return $candidate;
    }

    /**
     * The authoritative deadline that governs an attempt or the exam window.
     *
     * For an attempt with a start time:
     *   effective_deadline = min(started_at + duration_minutes, ends_at)
     *
     * For the exam as a whole:
     *   ends_at (the hard cutoff of the window, or null if no end window configured)
     */
    public function effectiveDeadline(?Carbon $startedAt = null): ?Carbon
    {
        if ($startedAt !== null) {
            return $this->calculateAttemptExpiry($startedAt);
        }

        return $this->ends_at?->copy();
    }

    /**
     * Whether the authenticated user may manage this exam. A teacher owns an
     * exam if they created it or own the course it belongs to.
     */
    public function isManagedBy(User $user): bool
    {
        return $this->isOwnedBy($user) || $this->course->isManagedBy($user);
    }

    public function scopePublished($query)
    {
        return $query->where('status', ExamStatus::Published->value);
    }
}
