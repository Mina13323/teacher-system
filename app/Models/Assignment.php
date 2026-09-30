<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A homework assignment attached to a course (optionally to a unit/lesson).
 * Student work lives in AssignmentSubmission (one row per student; resubmits
 * update it — the migration enforces the unique pair).
 */
class Assignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'unit_id',
        'lesson_id',
        'created_by',
        'title',
        'description',
        'points',
        'due_at',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'due_at' => 'datetime',
            'is_published' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class)->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    /** Whether the operational staff of the owning course may manage this. */
    public function isManagedBy(User $user): bool
    {
        return $this->course?->isManagedBy($user) ?? false;
    }

    /** Whether the due date has passed (late submissions are still accepted). */
    public function isPastDue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast();
    }
}
