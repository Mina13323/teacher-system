<?php

namespace Tests\Feature\Performance;

use App\Enums\UserRole;
use App\Services\CourseCatalogCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\ApiTestCase;

/**
 * The general cache, the rate limiter and the permission cache on the file
 * store: requests make no queries against the `cache` tables, a signed-in
 * user stays signed in, and catalog edits still show up at once.
 */
class FileCacheStoreTest extends ApiTestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePath = storage_path('framework/testing/cache-'.Str::random(8));
        config([
            'cache.stores.file.path' => $this->cachePath,
            'cache.stores.file.lock_path' => $this->cachePath,
            'cache.default' => 'file',
            'cache.limiter' => 'file',
            'permission.cache.store' => 'file',
        ]);
        Cache::forgetDriver('file');
        app()->forgetInstance('cache.store');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);
        parent::tearDown();
    }

    private function cacheTableQueries(callable $fn): array
    {
        $hits = [];
        DB::listen(function ($query) use (&$hits) {
            if (preg_match('/\b(from|into|update)\s+["`]?cache(_locks)?["`]?/i', $query->sql) === 1) {
                $hits[] = $query->sql;
            }
        });
        $fn();

        return $hits;
    }

    public function test_requests_make_no_cache_table_queries_and_keep_the_user_signed_in(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->createCourse($teacher, ['status' => 'published']);
        $student = $this->createUserWithRole(UserRole::Student);
        $token = $student->createToken('test')->plainTextToken;

        $hits = $this->cacheTableQueries(function () use ($token) {
            foreach (['/api/v1/auth/me', '/api/v1/courses', '/api/v1/courses', '/api/v1/student/dashboard'] as $path) {
                $this->app['auth']->forgetGuards();
                $this->withToken($token)->getJson($path)->assertOk();
            }
        });

        $this->assertSame([], $hits);
    }

    public function test_the_login_throttle_still_limits_on_the_file_store(): void
    {
        $user = $this->createUserWithRole(UserRole::Student);
        $limit = (int) config('api.rate_limit.auth', 10);

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_a_course_edit_shows_in_the_catalog_at_once(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createUserWithRole(UserRole::Student);
        $first = $this->createCourse($teacher, ['status' => 'published']);

        $titles = fn () => collect($this->actingAs($student, 'sanctum')->getJson('/api/v1/courses')->assertOk()->json('data'))
            ->pluck('title')->all();

        $this->assertSame([$first->title], $titles());

        // A second course is published, and the first one is renamed: both
        // saves flush the cached pages.
        $second = $this->createCourse($teacher, ['status' => 'published', 'created_at' => now()->addMinute()]);
        $first->update(['title' => 'Renamed course']);

        $this->assertEqualsCanonicalizing([$second->title, 'Renamed course'], $titles());
    }

    public function test_a_flush_drops_every_cached_page(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->createCourse($teacher, ['status' => 'published']);
        $catalog = app(CourseCatalogCache::class);

        $this->assertSame(1, $catalog->page(1, 15)->total());
        $this->assertSame(1, $catalog->page(1, 5)->total());

        \App\Models\Course::withoutEvents(fn () => $this->createCourse($teacher, ['status' => 'published']));
        $this->assertSame(1, $catalog->page(1, 15)->total(), 'Still the cached page before a flush.');

        CourseCatalogCache::flush();

        $this->assertSame(2, $catalog->page(1, 15)->total());
        $this->assertSame(2, $catalog->page(1, 5)->total());
    }
}
