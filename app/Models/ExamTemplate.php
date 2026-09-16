<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable exam structure: how many questions of each type, and what each is
 * worth. It carries no content — applying a template appends blank questions
 * for the teacher to fill in.
 */
class ExamTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\ExamTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'created_by',
        'is_system',
        'preset_key',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamTemplateSection::class)
            ->orderBy('exam_template_sections.position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * How many questions this template adds to an exam.
     */
    public function totalQuestions(): int
    {
        return (int) $this->sections->sum('quantity');
    }

    /**
     * The total marks an exam built from this template carries.
     */
    public function totalPoints(): int
    {
        return (int) $this->sections->sum(
            fn (ExamTemplateSection $section) => $section->quantity * $section->points
        );
    }

    /**
     * System templates ship with the app and are read-only for everyone. Of the
     * teacher-created ones, a teacher may edit only their own; an admin may
     * clear up any of them.
     */
    public function isEditableBy(User $user): bool
    {
        if ($this->is_system) {
            return false;
        }

        return $this->created_by === $user->getKey() || $user->isAdmin();
    }
}
