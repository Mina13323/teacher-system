<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Reads the per-request metric lines written by LogContextMiddleware
 * (api.request.completed / api.request.slow) and reports what the shared host
 * limits: requests that opened a MySQL connection, per second, and which
 * routes and traffic classes produce them.
 *
 * Read-only: it only reads log files. It never touches the database, so it
 * is safe to run on a server during an exam or a load test.
 *
 *   php artisan metrics:requests
 *   php artisan metrics:requests --since="2026-10-09 10:00:00" --until="2026-10-09 10:30:00"
 *   php artisan metrics:requests --file=storage/logs/laravel-2026-10-09.log --top=20
 */
class RequestMetricsSummaryCommand extends Command
{
    protected $signature = 'metrics:requests
        {--file=* : Log file(s) to read (default storage/logs/laravel.log)}
        {--since= : Only lines at or after this time (Y-m-d H:i:s, log time zone)}
        {--until= : Only lines before this time}
        {--top=15 : Routes to list}';

    protected $description = 'Summarize per-request DB connection, query and latency metrics from the application log.';

    private const LINE = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\]\s+\S+\.(?:INFO|WARNING):\s+api\.request\.(?:completed|slow)\s+(\{.*\})/';

    public function handle(): int
    {
        $files = $this->option('file') ?: [storage_path('logs/laravel.log')];
        $since = $this->option('since');
        $until = $this->option('until');

        $total = 0;
        $connected = 0;
        $perSecond = [];
        $groups = ['traffic' => [], 'area' => [], 'role' => []];
        $routes = [];
        $first = null;
        $last = null;

        foreach ($files as $file) {
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                $this->error("Cannot read {$file}");

                return self::FAILURE;
            }

            while (($line = fgets($handle)) !== false) {
                if (preg_match(self::LINE, $line, $m) !== 1) {
                    continue;
                }
                $time = str_replace('T', ' ', $m[1]);
                if (($since && $time < $since) || ($until && $time >= $until)) {
                    continue;
                }
                $metrics = json_decode($m[2], true);
                if (! is_array($metrics)) {
                    continue;
                }

                $total++;
                $first = $first === null || $time < $first ? $time : $first;
                $last = $last === null || $time > $last ? $time : $last;
                $isConnected = ($metrics['db_connected'] ?? null) === true;

                if ($isConnected) {
                    $connected++;
                    $perSecond[$time] = ($perSecond[$time] ?? 0) + 1;
                }

                foreach (array_keys($groups) as $key) {
                    $value = (string) ($metrics[$key] ?? 'unknown');
                    $groups[$key][$value]['requests'] = ($groups[$key][$value]['requests'] ?? 0) + 1;
                    $groups[$key][$value]['connected'] = ($groups[$key][$value]['connected'] ?? 0) + ($isConnected ? 1 : 0);
                }

                $route = ($metrics['method'] ?? '?').' '.($metrics['route_uri'] ?? $metrics['route'] ?? '?');
                $r = $routes[$route] ?? ['requests' => 0, 'connected' => 0, 'queries' => 0, 'writes' => 0, 'tx_ms' => 0.0, 'durations' => [], 'bytes' => 0, 'errors' => 0];
                $r['requests']++;
                $r['connected'] += $isConnected ? 1 : 0;
                $r['queries'] += (int) ($metrics['db_queries'] ?? 0);
                $r['writes'] += (int) ($metrics['db_writes'] ?? 0);
                $r['tx_ms'] = max($r['tx_ms'], (float) ($metrics['db_longest_transaction_ms'] ?? 0));
                $r['durations'][] = (float) ($metrics['duration_ms'] ?? 0);
                $r['bytes'] += (int) ($metrics['response_bytes'] ?? 0);
                $r['errors'] += ((int) ($metrics['status'] ?? 200)) >= 500 ? 1 : 0;
                $routes[$route] = $r;
            }
            fclose($handle);
        }

        if ($total === 0) {
            $this->warn('No request metric lines found in the selected range.');

            return self::SUCCESS;
        }

        $span = max(1, strtotime($last) - strtotime($first) + 1);
        $seconds = array_values($perSecond);
        sort($seconds);
        $perMinute = [];
        foreach ($perSecond as $time => $count) {
            $minute = substr($time, 0, 16);
            $perMinute[$minute] = ($perMinute[$minute] ?? 0) + $count;
        }

        $this->info("Range: {$first} .. {$last} ({$span} s)");
        $this->table(['Measure', 'Value'], [
            ['Requests', $total],
            ['Requests that opened a DB connection', $connected],
            ['DB connections/s, average over the range', round($connected / $span, 2)],
            ['DB connections/s, p95 of busy seconds', $this->percentile($seconds, 95)],
            ['DB connections/s, peak second', $seconds ? max($seconds) : 0],
            ['DB connections/min, peak minute', $perMinute ? max($perMinute) : 0],
            ['Seconds at or above 16 connections', count(array_filter($seconds, fn ($c) => $c >= 16))],
            ['Seconds at or above 20 connections', count(array_filter($seconds, fn ($c) => $c >= 20))],
        ]);

        foreach ($groups as $key => $rows) {
            ksort($rows);
            $this->table([ucfirst($key), 'Requests', 'DB connections', 'Share of connections'], collect($rows)->map(fn ($row, $name) => [
                $name, $row['requests'], $row['connected'], $connected ? round(100 * $row['connected'] / $connected, 1).'%' : '0%',
            ])->values()->all());
        }

        uasort($routes, fn ($a, $b) => $b['connected'] <=> $a['connected']);
        $this->table(
            ['Route', 'Requests', 'DB conns', 'Avg queries', 'Avg writes', 'Max tx ms', 'p95 ms', 'Avg bytes', '5xx'],
            collect(array_slice($routes, 0, max(1, (int) $this->option('top')), true))->map(function ($r, $route) {
                sort($r['durations']);

                return [
                    $route,
                    $r['requests'],
                    $r['connected'],
                    round($r['queries'] / $r['requests'], 1),
                    round($r['writes'] / $r['requests'], 1),
                    round($r['tx_ms'], 1),
                    $this->percentile($r['durations'], 95),
                    (int) round($r['bytes'] / $r['requests']),
                    $r['errors'],
                ];
            })->values()->all()
        );

        return self::SUCCESS;
    }

    /** @param  array<int, float|int>  $sorted  ascending */
    private function percentile(array $sorted, int $p): float|int
    {
        if ($sorted === []) {
            return 0;
        }

        return $sorted[(int) min(count($sorted) - 1, ceil($p / 100 * count($sorted)) - 1)];
    }
}
