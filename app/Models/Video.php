<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends Model
{
    /** @use HasFactory<\Database\Factories\VideoFactory> */
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'title',
        'storage_path',
        'duration',
        'position',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'position' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function isPublished(): bool
    {
        return (bool) $this->is_published;
    }
}
