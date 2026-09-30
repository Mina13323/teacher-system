<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lesson Q&A entry: a question (parent_id null) or an answer (parent_id set).
 * Moderated deletion is soft (is_deleted) — threads are never destroyed.
 */
class LessonQuestion extends Model
{
    protected $fillable = [
        'lesson_id', 'user_id', 'parent_id', 'body',
        'is_deleted', 'deleted_by', 'deleted_at',
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
