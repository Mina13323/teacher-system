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
 * Invalidation: Course saved/deleted events flush the catalog (see the
 * model's boot hooks), and the TTL (60s) bounds staleness for direct DB
 * edits. Pages are stored under a version number, and a flush moves to the
 * next version, so every cached page is dropped at once on any cache store
 * (the file store has no prefix delete).
 */
final class CourseCatalogCache
{
    private const KEY = 'catalog:published_courses:v1';

    private const TTL_SECONDS = 60;

    private const VERSION_KEY = 'catalog:published_courses:version';

    /**
     * Cached page of the published catalog (identical for every role).
     */
    public function page(int $page, int $perPage): mixed
    {
        $version = (int) Cache::get(self::VERSION_KEY, 0);

        return Cache::remember(self::KEY.":g{$version}:p{$page}:n{$perPage}", self::TTL_SECONDS, function () use ($page, $perPage) {
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
        // A new version makes every cached page unreachable; the old entries
        // expire on their TTL. Not atomic, but two flushes racing still both
        // leave a version no cached page was written under before the edit.
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }
}
