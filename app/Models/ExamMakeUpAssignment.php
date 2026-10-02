<?php

namespace App\Models;

use App\Enums\ExamMakeUpAssignmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamMakeUpAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'student_id',
        'assigned_by',
        'attempt_id',
        'assigned_at',
        'used_at',
        'reason',
        'status',
        'active_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExamMakeUpAssignmentStatus::class,
            'assigned_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class)->withTrashed();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id')->withTrashed();
    }

    public function isAvailable(): bool
    {
        return $this->status === ExamMakeUpAssignmentStatus::Assigned
            && $this->active_key !== null;
    }
}
