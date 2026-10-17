<?php

namespace Tests\Diagnostics;

use App\Enums\UserRole;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class LockSpyGrammar extends SQLiteGrammar
{
    public static ?string $pending = null;

    protected function compileLock(Builder $query, $value)
    {
        if ($value !== null && $value !== false && $value !== '') {
            self::$pending = $value === true ? 'FOR UPDATE' : (is_string($value) ? $value : 'SHARED');
        } elseif ($value === false) {
            self::$pending = 'SHARED';
        }

        return '';
    }
}

/**
 * Diagnostic, not a regression test: it is outside the Unit/Feature suites, so
 * CI and `php artisan test` never run it. It walks a student and a teacher
 * through the main endpoints on SQLite and writes, per request: statements by
 * verb and by source, SQL time, transactions (statements and duration), row
 * locks taken, duplicate statements, response size and status.
 *
 *   PROBE_Q=20 PROBE_CLASS=25 PROBE_LIMITER=database PROBE_PERMISSION_STORE=database \
 *   PROBE_OUT=/tmp/probe vendor/bin/phpunit tests/Diagnostics/DbBudgetProbeTest.php
 *
 * PROBE_LIMITER / PROBE_PERMISSION_STORE choose where the rate limiter and the
 * permission cache live ("database" reproduces CACHE_STORE=database before
 * this change, "file" is the new default). PROBE_COMPACT=1 measures the
 * compact answer acknowledgement; PROBE_ESSAY=1 adds an essay question (the
 * result then waits for manual grading). Output: PROBE_OUT.txt (table) and
 * PROBE_OUT.sql.txt (every statement with bindings).
 *
 * SQLite drops FOR UPDATE, so a grammar spy records where a lock was asked for.
 */
class DbBudgetProbeTest extends ApiTestCase
{
    use InteractsWithExams;

    private array $rows = [];
    private string $detail = '';
    private bool $recording = false;
    private array $cur = [];

    private function startRecording(): void
    {
        $this->cur = ['q' => [], 'tx' => [], 'txStack' => []];
        $this->recording = true;
    }

    private function boot(): void
    {
        $conn = DB::connection();
        $conn->setQueryGrammar(new LockSpyGrammar($conn));
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) {
            if (! $this->recording) {
                LockSpyGrammar::$pending = null;
                return;
            }
            $lock = LockSpyGrammar::$pending;
            LockSpyGrammar::$pending = null;
            $inTx = count($this->cur['txStack']) > 0;
            $this->cur['q'][] = ['sql' => $e->sql, 'ms' => $e->time, 'lock' => $lock, 'tx' => $inTx, 'b' => json_encode(array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 60) : $v, $e->bindings))];
            if ($inTx) {
                $this->cur['txStack'][count($this->cur['txStack']) - 1]['n']++;
            }
        });
        Event::listen(TransactionBeginning::class, function () {
            if (! $this->recording) return;
            $this->cur['txStack'][] = ['t' => microtime(true), 'n' => 0, 'firstq' => count($this->cur['q'])];
        });
        $end = function ($outcome) {
            return function () use ($outcome) {
                if (! $this->recording || ! $this->cur['txStack']) return;
                $tx = array_pop($this->cur['txStack']);
                $this->cur['tx'][] = [
                    'ms' => round((microtime(true) - $tx['t']) * 1000, 2),
                    'n' => $tx['n'],
                    'outcome' => $outcome,
                    'depth' => count($this->cur['txStack']),
                    'firstq' => $tx['firstq'],
                ];
            };
        };
        Event::listen(TransactionCommitted::class, $end('commit'));
        Event::listen(TransactionRolledBack::class, $end('rollback'));
    }

    private function classify(string $sql): string
    {
        $s = strtolower(ltrim($sql));
        if (str_contains($s, '"cache') ) return 'cache';
        if (str_contains($s, '"sessions"')) return 'session';
        if (str_contains($s, '"jobs"')) return 'queue';
        if (str_contains($s, '"personal_access_tokens"')) return 'auth';
        if (preg_match('/from "users"|update "users"/', $s)) return 'auth-user';
        if (preg_match('/"roles"|"permissions"|"model_has_roles"|"model_has_permissions"|"role_has_permissions"/', $s)) return 'permission';
        return 'app';
    }

    private function measure(string $label, callable $fn)
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');
        $this->travel(2)->seconds();
        $this->startRecording();
        $t0 = microtime(true);
        $res = $fn();
        $wall = round((microtime(true) - $t0) * 1000, 1);
        $this->recording = false;

        $c = ['select' => 0, 'insert' => 0, 'update' => 0, 'delete' => 0, 'other' => 0];
        $src = [];
        $ms = 0.0;
        $locks = 0;
        $norm = [];
        foreach ($this->cur['q'] as $q) {
            $s = strtolower(ltrim($q['sql']));
            $verb = preg_match('/^(select|insert|update|delete)/', $s, $m) ? $m[1] : 'other';
            $c[$verb]++;
            $cls = $this->classify($q['sql']);
            $src[$cls] = ($src[$cls] ?? 0) + 1;
            $ms += $q['ms'];
            if ($q['lock']) $locks++;
            $key = preg_replace('/\s+/', ' ', $q['sql']);
            $norm[$key] = ($norm[$key] ?? 0) + 1;
        }
        $total = count($this->cur['q']);
        $dups = array_filter($norm, fn ($n) => $n > 1);
        $txs = $this->cur['tx'];
        $txSummary = $txs ? implode(',', array_map(fn ($t) => $t['n'].'q/'.$t['ms'].'ms'.($t['depth'] ? '(nested)' : '').($t['outcome'] === 'rollback' ? '(rb)' : ''), $txs)) : '-';
        $srcStr = implode(' ', array_map(fn ($k, $v) => "$k=$v", array_keys($src), $src));
        $this->rows[] = [$label, $total, $c['select'], $c['insert'], $c['update'], $c['delete'], round($ms, 2), $wall, $locks, $txSummary, count($dups), $srcStr, $res?->getStatusCode(), $res ? strlen((string) $res->getContent()) : 0];

        $this->detail .= "\n=== $label (status ".$res?->getStatusCode().", $total queries) ===\n";
        foreach ($this->cur['q'] as $i => $q) {
            $this->detail .= sprintf("%02d %s%s %6.2fms [%s] %s\n", $i + 1, $q['tx'] ? 'T' : ' ', $q['lock'] ? 'L' : ' ', $q['ms'], $this->classify($q['sql']), preg_replace('/\s+/', ' ', $q['sql']).'  '.$q['b']);
        }
        foreach ($dups as $sql => $n) {
            $this->detail .= "  DUP x$n: $sql\n";
        }

        return $res;
    }

    public function test_probe(): void
    {
        $n = (int) (getenv('PROBE_Q') ?: 20);
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $limiterStore = getenv('PROBE_LIMITER') ?: 'file';
        $permissionStore = getenv('PROBE_PERMISSION_STORE') ?: 'file';
        config(['permission.cache.store' => $permissionStore]);
        app(\Spatie\Permission\PermissionRegistrar::class)->initializeCache();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $current = $this->app->make(RateLimiter::class);
        $limiters = (fn () => $this->limiters)->call($current);
        $r = new RateLimiter($this->app['cache']->store($limiterStore));
        $r->clear('probe');
        if ($limiterStore === 'file') {
            $this->app['cache']->store('file')->flush();
        }
        foreach ($limiters as $name => $cb) { $r->for($name, $cb); }
        $this->app->instance(RateLimiter::class, $r);
        $this->boot();

        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published', 'duration_minutes' => 30, 'max_attempts' => 5, 'show_result_immediately' => true]);
        $multi = (int) floor($n * 0.15);
        for ($i = 0; $i < $n - $multi; $i++) { $this->addSingleChoiceQuestion($exam); }
        for ($i = 0; $i < $multi; $i++) { $this->addMultipleChoiceQuestion($exam); }
        if (getenv('PROBE_ESSAY')) {
            // An essay sends the attempt to manual grading, so the result is not published.
            \App\Models\Question::factory()->create(['exam_id' => $exam->id, 'type' => 'essay', 'points' => 5, 'position' => $n + 1]);
        }
        $unit = $this->createUnit($course);
        $lesson = \App\Models\Lesson::factory()->published()->create(['unit_id' => $unit->id, 'position' => 1]);
        \App\Models\Video::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);
        $student = $this->createUserWithRole(UserRole::Student, ['email' => 'probe@example.com', 'password' => bcrypt('secret-pass-1')]);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        // A class of other students with submitted attempts, for the teacher pages.
        $others = (int) (getenv('PROBE_CLASS') ?: 25);
        for ($s = 0; $s < $others; $s++) {
            $u = $this->createUserWithRole(UserRole::Student);
            $this->app['auth']->forgetGuards();
            $this->actingAs($u, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
            $st = $this->actingAs($u, 'sanctum')->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true, 'compact_response' => true]);
            $this->actingAs($u, 'sanctum')->postJson('/api/v1/student/attempts/'.$st->json('data.id').'/integrity-events', ['event_type' => 'TAB_SWITCH']);
            $this->actingAs($u, 'sanctum')->postJson('/api/v1/student/attempts/'.$st->json('data.id').'/submit');
        }
        $this->app['auth']->forgetGuards();
        $teacherToken = $teacher->createToken('t')->plainTextToken;

        $login = $this->measure('login', fn () => $this->postJson('/api/v1/auth/login', ['email' => 'probe@example.com', 'password' => 'secret-pass-1']));
        $h = ['Authorization' => 'Bearer '.$login->json('data.token')];
        $this->measure('auth/me (first, writes last_used_at)', fn () => $this->getJson('/api/v1/auth/me', $h));
        $this->measure('auth/me (within 5 min)', fn () => $this->getJson('/api/v1/auth/me', $h));
        $this->measure('student dashboard', fn () => $this->getJson('/api/v1/student/dashboard', $h));
        $this->measure('notifications unread-count', fn () => $this->getJson('/api/v1/notifications/unread-count', $h));
        $this->measure('notifications list', fn () => $this->getJson('/api/v1/notifications', $h));
        $this->measure('student courses', fn () => $this->getJson('/api/v1/student/courses', $h));
        $this->measure('student course show', fn () => $this->getJson("/api/v1/student/courses/{$course->id}", $h));
        $this->measure('student lesson show', fn () => $this->getJson("/api/v1/student/lessons/{$lesson->id}", $h));
        $this->measure('student lesson videos', fn () => $this->getJson("/api/v1/student/lessons/{$lesson->id}/videos", $h));
        $this->measure('student progress', fn () => $this->getJson('/api/v1/student/progress', $h));
        $this->measure('student exams', fn () => $this->getJson('/api/v1/student/exams', $h));
        $this->measure('exam details', fn () => $this->getJson("/api/v1/student/exams/{$exam->id}", $h));
        $start = $this->measure('start attempt (full response)', fn () => $this->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true], $h));
        $aid = $start->json('data.id');
        $attempt = $this->measure('get attempt', fn () => $this->getJson("/api/v1/student/attempts/{$aid}", $h));
        $qs = $attempt->json('data.questions');
        $single = collect($qs)->first(fn ($q) => ($q['question_type'] ?? '') === 'single_choice');
        $multiQ = collect($qs)->first(fn ($q) => ($q['question_type'] ?? '') === 'multiple_choice');
        $compact = getenv('PROBE_COMPACT') ? ['compact_response' => true] : [];
        $essayQ = collect($qs)->first(fn ($q) => ($q['question_type'] ?? '') === 'essay');
        $this->measure('save answer (single, first)', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/answers", ['question_id' => $single['id'], 'option_ids' => [$single['options'][0]['id']]] + $compact, $h));
        $this->measure('save answer (single, change)', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/answers", ['question_id' => $single['id'], 'option_ids' => [$single['options'][1]['id']]] + $compact, $h));
        $this->measure('save answer (single, same)', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/answers", ['question_id' => $single['id'], 'option_ids' => [$single['options'][1]['id']]] + $compact, $h));
        if ($multiQ) {
            $this->measure('save answer (multiple)', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/answers", ['question_id' => $multiQ['id'], 'option_ids' => [$multiQ['options'][0]['id'], $multiQ['options'][1]['id']]] + $compact, $h));
        }
        if ($essayQ) {
            $this->measure('save answer (essay)', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/answers", ['question_id' => $essayQ['id'], 'answer_text' => 'An essay answer.'] + $compact, $h));
        }
        $this->measure('heartbeat (status check)', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/heartbeat", [], $h));
        $this->measure('time ping', fn () => $this->getJson('/api/v1/time', $h));
        $this->measure('integrity TAB_SWITCH (counted)', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/integrity-events", ['event_type' => 'TAB_SWITCH'], $h));
        $this->measure('integrity TAB_SWITCH (dup <5s)', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/integrity-events", ['event_type' => 'TAB_SWITCH'], $h));
        $this->measure('integrity WINDOW_FOCUS (0 risk)', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/integrity-events", ['event_type' => 'WINDOW_FOCUS'], $h));
        $this->measure('submit attempt', fn () => $this->postJson("/api/v1/student/attempts/{$aid}/submit", [], $h));
        $this->measure('result (get attempt after submit)', fn () => $this->getJson("/api/v1/student/attempts/{$aid}", $h));
        $this->measure('student exam attempts list', fn () => $this->getJson("/api/v1/student/exams/{$exam->id}/attempts", $h));

        $th = ['Authorization' => 'Bearer '.$teacherToken];
        $this->measure('teacher dashboard', fn () => $this->getJson('/api/v1/teacher/dashboard', $th));
        $this->measure('teacher exam show', fn () => $this->getJson("/api/v1/teacher/exams/{$exam->id}", $th));
        $this->measure('teacher exam attempts (page 15)', fn () => $this->getJson("/api/v1/teacher/exams/{$exam->id}/attempts?per_page=15", $th));
        $this->measure('teacher exam attempts grouped', fn () => $this->getJson("/api/v1/teacher/exams/{$exam->id}/attempts/grouped", $th));
        $this->measure('teacher exam attempts (search)', fn () => $this->getJson("/api/v1/teacher/exams/{$exam->id}/attempts?search=probe&per_page=15", $th));
        $this->measure('teacher attempt show (grading)', fn () => $this->getJson("/api/v1/teacher/attempts/{$aid}", $th));
        $this->measure('teacher attempt integrity', fn () => $this->getJson("/api/v1/teacher/attempts/{$aid}/integrity", $th));
        $this->measure('teacher attempt integrity events', fn () => $this->getJson("/api/v1/teacher/attempts/{$aid}/integrity-events", $th));
        $this->measure('teacher exam integrity settings', fn () => $this->getJson("/api/v1/teacher/exams/{$exam->id}/integrity", $th));
        $this->measure('teacher analytics overview', fn () => $this->getJson('/api/v1/teacher/analytics/overview', $th));
        $this->measure('teacher courses list', fn () => $this->getJson('/api/v1/teacher/courses?per_page=50', $th));
        $this->measure('teacher course exams', fn () => $this->getJson("/api/v1/teacher/courses/{$course->id}/exams?per_page=50", $th));

        $out = "endpoint|total|select|insert|update|delete|sql_ms|wall_ms|locks|transactions|dup_stmts|by_source|status|bytes\n";
        foreach ($this->rows as $row) { $out .= implode('|', $row)."\n"; }
        $base = getenv('PROBE_OUT') ?: '/tmp/dbprobe';
        file_put_contents($base.'.txt', $out);
        file_put_contents($base.'.sql.txt', $this->detail);
        $this->assertTrue(true);
    }
}
