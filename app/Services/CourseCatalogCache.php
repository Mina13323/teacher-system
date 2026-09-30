<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\Cache;

/**
 * PHASE 5 §44 — Safe caching for the public course catalog only.
 *
 * WHAT is cached: published-course listing metadata (id, title, slug, price
 * fields — whatever the public list already exposes). WHAT IS NEVER CACHED:
 * attempt status, timers, scores, integrity state — the database stays
 * authoritative for all academic state (locked rule).
 *
 * Invalidation: Course saved/deleted events flush the key (see the model's
 * boot hooks), and the TTL (60s) bounds staleness for direct DB edits.
 */
final class CourseCatalogCache
{
    private const KEY = 'catalog:published_courses:v1';

    private const TTL_SECONDS = 60;

    /**
     * Cached page of the published catalog (identical for every role).
     */
    public function page(int $page, int $perPage): mixed
    {
        return Cache::remember(self::KEY.":p{$page}:n{$perPage}", self::TTL_SECONDS, function () use ($page, $perPage) {
            return Course::query()
                ->published()
                ->with('creator')
                ->withCount(['units', 'lessons'])
                ->latest()
                ->paginate($perPage, ['*'], 'page', $page);
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::KEY);
    }
}
