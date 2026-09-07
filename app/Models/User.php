<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'phone',
        'bio',
        'is_active',
        'profile_completed_at',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'profile_completed_at' => 'datetime',
        ];
    }

    /**
     * The teacher/admin account that created this student account, if any.
     */
    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the user has completed their profile. A profile is considered
     * complete once the user has supplied a name, avatar or phone, or the
     * account was created with enough detail to be usable.
     */
    public function isProfileComplete(): bool
    {
        return $this->profile_completed_at !== null
            || $this->avatar !== null
            || $this->phone !== null
            || trim((string) $this->name) !== '';
    }

    /**
     * Courses created by the user (typically a teacher).
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'created_by');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'created_by');
    }

    public function examAttempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class, 'student_id');
    }

    /**
     * Enrollments owned by the user (a student).
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    /**
     * Lesson progress records owned by the user (a student).
     */
    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class, 'student_id');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::Admin->value);
    }

    public function isTeacher(): bool
    {
        return $this->hasRole(UserRole::Teacher->value);
    }

    public function isAssistant(): bool
    {
        return $this->hasRole(UserRole::Assistant->value);
    }

    public function isStudent(): bool
    {
        return $this->hasRole(UserRole::Student->value);
    }

    /**
     * Whether the account is active and may be used for authentication.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * A safe, public-facing display name used on leaderboards and anywhere a
     * user's identity is shown to peers. Never exposes an email address or an
     * internal identifier.
     *
     * Examples:
     *  - "Mina W." from "Mina Walid"
     *  - "Mina" from a single-word name
     */
    public function publicDisplayName(): string
    {
        $name = trim((string) $this->name);

        if ($name === '') {
            return 'Participant';
        }

        $parts = preg_split('/\s+/', $name);

        if (count($parts) === 1) {
            return $parts[0];
        }

        $first = $parts[0];
        $initial = mb_substr($parts[1], 0, 1);

        return $initial !== ''
            ? $first.' '.mb_strtoupper($initial).'.'
            : $first;
    }
}
