<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's work on one assignment (the unique pair is enforced by the
 * database). Resubmission updates this row until it is graded — after grading
 * only staff may change it (regrade), so a grade can never be silently erased.
 *
 * status: draft | submitted | graded
 */
class AssignmentSubmission extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_GRADED = 'graded';

    protected $fillable = [
        'assignment_id',
        'student_id',
        'answer_text',
        'file_path',
        'file_name',
        'file_mime',
        'submitted_at',
        'status',
        'score',
        'feedback',
        'graded_by',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'score' => 'integer',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function isGraded(): bool
    {
        return $this->status === self::STATUS_GRADED;
    }

    /** Late relative to the assignment deadline at submission time. */
    public function isLate(): bool
    {
        $due = $this->assignment?->due_at;

        return $due !== null && $this->submitted_at !== null && $this->submitted_at->greaterThan($due);
    }
}
