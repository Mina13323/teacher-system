<?php

namespace App\Models;

use App\Enums\CourseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Course extends Model
{
    /** @use HasFactory<\Database\Factories\CourseFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'thumbnail',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)
            ->orderBy('units.position');
    }

    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(Lesson::class, Unit::class)
            ->orderBy('lessons.position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->getKey();
    }

    public function publish(): void
    {
        $this->status = CourseStatus::Published;
        $this->save();
    }

    public function unpublish(): void
    {
        $this->status = CourseStatus::Draft;
        $this->save();
    }

    /**
     * Scope to only published courses (used by public, unauthenticated endpoints).
     */
    public function scopePublished($query)
    {
        return $query->where('status', CourseStatus::Published->value);
    }
}
