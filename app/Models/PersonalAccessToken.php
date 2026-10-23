<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token that records `last_used_at` at most once per window.
 *
 * Sanctum writes `last_used_at` on EVERY authenticated request, so each
 * heartbeat, answer save and dashboard call carried an extra UPDATE on
 * `personal_access_tokens`. Nothing in this application reads the column
 * (tokens do not expire by idle time), so it is refreshed only when the stored
 * value is older than the window. Token lookup and validation are unchanged:
 * this class only skips the write.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** How stale `last_used_at` may get before it is written again. */
    public const LAST_USED_AT_WINDOW_SECONDS = 300;

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isOnlyFreshLastUsedAtTouch()) {
            // Keep the stored value; the model stays clean.
            $this->syncOriginalAttribute('last_used_at');

            return true;
        }

        return parent::save($options);
    }

    private function isOnlyFreshLastUsedAtTouch(): bool
    {
        if (array_keys($this->getDirty()) !== ['last_used_at']) {
            return false;
        }

        $stored = $this->getOriginal('last_used_at');

        return $stored !== null
            && $stored->gt(now()->subSeconds(self::LAST_USED_AT_WINDOW_SECONDS));
    }
}
