<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's private note (course/lesson/video timestamp). Owner-only.
 */
class StudentNote extends Model
{
    protected $fillable = [
        'user_id', 'course_id', 'lesson_id', 'video_id',
        'position_seconds', 'body',
    ];

    protected $casts = [
        'position_seconds' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
