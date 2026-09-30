<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only staff audit trail row (actor / action / target / timestamp /
 * metadata). Rows are never updated or deleted by application code.
 *
 * Metadata is JSON and must never hold secrets (passwords, tokens, full answer
 * keys) — writers log identifiers and outcomes only.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'target_type',
        'target_id',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
