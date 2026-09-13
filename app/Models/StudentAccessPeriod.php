<?php

namespace App\Models;

use App\Enums\StudentAccessStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAccessPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'status',
        'starts_at',
        'expires_at',
        'amount',
        'notes',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StudentAccessStatus::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'amount' => 'float',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StudentAccessStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('status', StudentAccessStatus::Due->value)
            ->orWhere('status', StudentAccessStatus::Expired->value)
            ->orWhere(fn (Builder $sub) => $sub->where('status', StudentAccessStatus::Active->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
            )
        );
    }
}
