<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A personal bookmark (lesson or video timestamp). Owner-only.
 */
class Bookmark extends Model
{
    protected $fillable = [
        'user_id', 'lesson_id', 'video_id', 'position_seconds', 'label',
    ];

    protected $casts = [
        'position_seconds' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
