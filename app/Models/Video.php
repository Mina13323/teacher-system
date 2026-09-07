<?php

namespace App\Models;

use App\Enums\VideoProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    /** @use HasFactory<\Database\Factories\VideoFactory> */
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'title',
        'storage_path',
        'provider',
        'provider_video_id',
        'duration',
        'position',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'provider' => VideoProvider::class,
            'duration' => 'integer',
            'position' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function playbackSessions(): HasMany
    {
        return $this->hasMany(VideoPlaybackSession::class);
    }

    public function isPublished(): bool
    {
        return (bool) $this->is_published;
    }

    /**
     * The provider-agnostic reference the chosen provider needs to play this
     * video (e.g. a YouTube video id, or a storage path). It is an internal
     * implementation detail and is never returned through normal student-facing
     * resources; it only appears in an authorized, short-lived playback payload.
     */
    public function providerReference(): ?string
    {
        return $this->provider_video_id ?: $this->storage_path;
    }
}
