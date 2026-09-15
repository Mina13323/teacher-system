<?php

namespace App\Models;

use App\Enums\ExamStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Exam extends Model
{
    /** @use HasFactory<\Database\Factories\ExamFactory> */
    use HasFactory;

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
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
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

    public function integritySetting(): HasOne
    {
        return $this->hasOne(ExamIntegritySetting::class, 'exam_id');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->getKey();
    }

    /**
     * Whether this exam runs on an official global-clock window.
     *
     * A partial window (only one of the two timestamps) is treated as NOT
     * windowed here, but such a record cannot be created: the exam form
     * requests reject it with a 422.
     */
    public function isWindowed(): bool
    {
        return $this->starts_at !== null && $this->ends_at !== null;
    }

    /**
     * The authoritative deadline that governs every attempt on this exam.
     *
     * Windowed exam:  min(starts_at + duration_minutes, ends_at)
     * Legacy exam:    null — the caller falls back to started_at + duration_minutes
     *
     * This is deliberately independent of when any individual student enters,
     * so a late entry receives only the time that is actually left.
     */
    public function effectiveDeadline(): ?Carbon
    {
        if (! $this->isWindowed()) {
            return null;
        }

        $globalDeadline = $this->starts_at->copy()->addMinutes($this->duration_minutes);

        return $globalDeadline->lessThan($this->ends_at)
            ? $globalDeadline
            : $this->ends_at->copy();
    }

    /**
     * Whether the authenticated user may manage this exam. A teacher owns an
     * exam if they created it or own the course it belongs to.
     */
    public function isManagedBy(User $user): bool
    {
        return $this->isOwnedBy($user) || $this->course->isOwnedBy($user);
    }

    public function scopePublished($query)
    {
        return $query->where('status', ExamStatus::Published->value);
    }
}
