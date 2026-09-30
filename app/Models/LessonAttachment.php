<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a lesson (PDF, slides, worksheet...). The file lives on
 * the PRIVATE disk and is only streamed through the authorized download
 * endpoint (staff of the course, or students who can access the lesson).
 */
class LessonAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'uploaded_by',
        'title',
        'file_path',
        'file_name',
        'file_mime',
        'file_size',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'position' => 'integer',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
