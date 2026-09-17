<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Takes a point-in-time database dump and rotates old ones.
 *
 * Written without spatie/laravel-backup so the project gains an off-box safety
 * net without a new dependency. Exits non-zero on any failure so a cron or CI
 * alert actually fires instead of silently producing no backup.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup
                            {--disk= : Filesystem disk to write to (defaults to local storage)}
                            {--keep=14 : How many backups to retain before pruning}
                            {--connection= : Database connection to dump (defaults to the default)}';

    protected $description = 'Create a rotating backup of the application database.';

    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?: config('database.default'));
        $driver = (string) config("database.connections.{$connection}.driver");

        $this->info("Backing up the [{$connection}] connection (driver: {$driver})...");

        $stamp = Carbon::now()->format('Ymd-His');
        $filename = "backup-{$connection}-{$stamp}-".Str::random(6).'.sql';
        $target = $this->targetDirectory();

        if (! is_dir($target) && ! @mkdir($target, 0750, true) && ! is_dir($target)) {
            $this->error("Unable to create the backup directory: {$target}");

            return self::FAILURE;
        }

        $path = rtrim($target, '/').'/'.$filename;

        try {
            $sql = match ($driver) {
                'sqlite' => $this->dumpSqlite($connection),
                'mysql', 'mariadb' => $this->dumpMysql($connection),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($sql === null) {
            $this->error("No backup strategy for the [{$driver}] driver.");

            return self::FAILURE;
        }

        if (trim($sql) === '') {
            $this->error('The dump came back empty; refusing to write a useless backup.');

            return self::FAILURE;
        }

        if (file_put_contents($path, $sql) === false) {
            $this->error("Unable to write the backup to {$path}");

            return self::FAILURE;
        }

        @chmod($path, 0640);

        $this->info('Wrote '.$path.' ('.number_format(strlen($sql)).' bytes)');

        $this->prune($target, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * SQLite is a single file, so copying it is a valid snapshot.
     */
    private function dumpSqlite(string $connection): ?string
    {
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === ':memory:') {
            throw new \RuntimeException('An in-memory sqlite database has nothing to back up.');
        }

        if (! is_file($database)) {
            throw new \RuntimeException("The sqlite file was not found: {$database}");
        }

        $contents = file_get_contents($database);

        if ($contents === false) {
            throw new \RuntimeException("The sqlite file could not be read: {$database}");
        }

        return $contents;
    }

    /**
     * Dump via mysqldump.
     *
     * The password is handed to the client through a 0600 defaults-extra-file
     * rather than a --password= flag, so it never appears in the process list
     * where any local user could read it. The file is removed afterwards.
     */
    private function dumpMysql(string $connection): string
    {
        $config = config("database.connections.{$connection}");
        $database = (string) ($config['database'] ?? '');

        if ($database === '') {
            throw new \RuntimeException('No database name is configured for this connection.');
        }

        $defaultsFile = @tempnam(sys_get_temp_dir(), 'elm-dump-');

        if ($defaultsFile === false) {
            throw new \RuntimeException('Unable to create a temporary credentials file.');
        }

        try {
            @chmod($defaultsFile, 0600);

            $lines = ['[client]'];
            foreach ([
                'host' => $config['host'] ?? null,
                'port' => $config['port'] ?? null,
                'user' => $config['username'] ?? null,
                'password' => $config['password'] ?? null,
                'unix_socket' => $config['unix_socket'] ?? null,
            ] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $lines[] = $key.'='.$this->optionFileValue((string) $value);
                }
            }

            if (file_put_contents($defaultsFile, implode("\n", $lines)."\n") === false) {
                throw new \RuntimeException('Unable to write the temporary credentials file.');
            }

            // --single-transaction keeps the dump consistent on InnoDB without
            // locking the tables for the duration of the copy.
            $result = Process::run([
                'mysqldump',
                '--defaults-extra-file='.$defaultsFile,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--default-character-set=utf8mb4',
                $database,
            ]);

            if (! $result->successful()) {
                // stderr from mysqldump can echo connection details; surface only
                // the first line so the log stays free of anything sensitive.
                $reason = trim((string) (explode("\n", $result->errorOutput())[0] ?? 'unknown error'));

                throw new \RuntimeException("mysqldump failed: {$reason}");
            }

            return (string) $result->output();
        } finally {
            if (is_file($defaultsFile)) {
                @unlink($defaultsFile);
            }
        }
    }

    /**
     * Quote a value for a MySQL option file.
     *
     * Not escapeshellarg(): option files are parsed by libmysqlclient, which
     * treats # and ; as comment starters and understands \" and \\ escapes
     * inside double quotes. Shell quoting would hand the server literal quote
     * characters and break authentication.
     */
    private function optionFileValue(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * Remove the oldest backups once the retention count is exceeded.
     */
    private function prune(string $target, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $existing = collect(glob(rtrim($target, '/').'/backup-*.sql') ?: [])
            ->sortByDesc(fn (string $file): int => (int) filemtime($file))
            ->values();

        if ($existing->count() <= $keep) {
            return;
        }

        $existing->slice($keep)->each(function (string $file): void {
            if (@unlink($file)) {
                $this->line('Pruned '.basename($file));
            }
        });
    }

    private function targetDirectory(): string
    {
        $configured = (string) (config('database.backup_path') ?: 'backups');

        return storage_path('app/'.ltrim($configured, '/'));
    }
}
