<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoPlaybackEvent extends Model
{
    /** @use HasFactory<\Database\Factories\VideoPlaybackEventFactory> */
    use HasFactory;

    protected $fillable = [
        'video_id',
        'student_id',
        'session_id',
        'event_type',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(VideoPlaybackSession::class, 'session_id');
    }
}
