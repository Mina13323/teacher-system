<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A background export run (queued generation of a result sheet / report).
 */
class Export extends Model
{
    protected $fillable = [
        'requested_by',
        'kind',
        'format',
        'subject_type',
        'subject_id',
        'status',
        'file_path',
        'row_count',
        'error',
        'finished_at',
    ];

    protected $casts = [
        'finished_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
