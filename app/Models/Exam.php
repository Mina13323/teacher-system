<?php

namespace App\Models;

use App\Enums\ExamStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    /** @use HasFactory<\Database\Factories\ExamFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'duration_minutes',
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

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->getKey();
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
