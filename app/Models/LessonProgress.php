<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    /** @use HasFactory<\Database\Factories\LessonProgressFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id',
        'lesson_id',
        'completed',
        'progress_percentage',
        'last_position_seconds',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'progress_percentage' => 'integer',
            'last_position_seconds' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The student's progress row for a lesson, or an unsaved zero-progress
     * row when they have not started it.
     */
    public static function forStudentLesson(int $studentId, int $lessonId): self
    {
        return static::query()
            ->where('student_id', $studentId)
            ->where('lesson_id', $lessonId)
            ->first()
            ?? new static([
                'student_id' => $studentId,
                'lesson_id' => $lessonId,
                'completed' => false,
                'progress_percentage' => 0,
                'last_position_seconds' => 0,
            ]);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
