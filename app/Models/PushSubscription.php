<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser push subscription (RFC 8030 endpoint + RFC 8291 keys).
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'keys',
        'user_agent',
    ];

    protected $casts = [
        'keys' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function p256dh(): ?string
    {
        return $this->keys['p256dh'] ?? null;
    }

    public function authKey(): ?string
    {
        return $this->keys['auth'] ?? null;
    }
}
