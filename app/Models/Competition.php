<?php

namespace App\Models;

use App\Actions\Competition\RecalculateCompetitionLeaderboardAction;
use App\Enums\CompetitionRankingType;
use App\Enums\CompetitionScoringType;
use App\Enums\CompetitionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competition extends Model
{
    /** @use HasFactory<\Database\Factories\CompetitionFactory> */
    use HasFactory;

    protected $fillable = [
        'created_by',
        'exam_id',
        'title',
        'description',
        'status',
        'starts_at',
        'ends_at',
        'max_participants',
        'scoring_type',
        'ranking_type',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompetitionStatus::class,
            'scoring_type' => CompetitionScoringType::class,
            'ranking_type' => CompetitionRankingType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_participants' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CompetitionParticipant::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(CompetitionResult::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->getKey();
    }

    /**
     * Advance the lifecycle to match the competition's scheduling window.
     *
     * This is the lazy scheduling trigger: a PUBLISHED competition is promoted
     * to ACTIVE once its window opens, and an ACTIVE competition is finalized to
     * ENDED (persisting a frozen leaderboard) once its window closes. It is
     * idempotent — once ENDED/ARCHIVED it never recomputes ranks again.
     */
    public function lazyFinalize(): self
    {
        $now = now();

        if ($this->status->isDraft() || $this->status->isEnded() || $this->status->isArchived()) {
            return $this;
        }

        $windowOpen = $this->starts_at === null || $now->greaterThanOrEqualTo($this->starts_at);

        if ($this->status->isPublished() && $windowOpen && $this->canStart()) {
            $this->status = CompetitionStatus::Active->value;
            $this->save();
        }

        $windowClosed = $this->ends_at !== null && $now->greaterThanOrEqualTo($this->ends_at);

        if ($this->status->isActive() && $windowClosed) {
            $this->status = CompetitionStatus::Ended->value;
            $this->save();

            app(RecalculateCompetitionLeaderboardAction::class)->execute($this);
        }

        return $this;
    }

    /**
     * Whether a PUBLISHED competition may be promoted to ACTIVE. Published
     * competitions can only start when they have a valid schedule.
     */
    private function canStart(): bool
    {
        return $this->starts_at !== null;
    }

    /**
     * Whether students may currently participate. Participation is allowed only
     * while the competition is active and the server-side window is open.
     */
    public function areParticipantsAccepted(): bool
    {
        if (! $this->status->isActive()) {
            return false;
        }

        $now = now();

        if ($this->starts_at !== null && $now->lessThan($this->starts_at)) {
            return false;
        }

        if ($this->ends_at !== null && $now->greaterThanOrEqualTo($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function scopePublished($query)
    {
        return $query->where('status', CompetitionStatus::Published->value);
    }
}
