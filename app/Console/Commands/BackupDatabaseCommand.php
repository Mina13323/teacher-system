<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Takes a point-in-time database dump and rotates old ones.
 *
 * Written without an extra dependency. It creates local rotating backups only;
 * off-host storage/encryption are deployment gaps. Exits non-zero on failures
 * so a cron or CI alert does not silently report a missing backup as success.
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
        $disk = (string) ($this->option('disk') ?: 'local');

        if ($disk !== 'local') {
            $this->error('Only local backup storage is supported; refusing to silently ignore --disk.');

            return self::FAILURE;
        }

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            $this->error("No backup strategy for the [{$driver}] driver.");

            return self::FAILURE;
        }

        $this->info("Backing up the [{$connection}] connection (driver: {$driver})...");

        $stamp = Carbon::now()->format('Ymd-His');
        $extension = $driver === 'sqlite' ? 'sqlite' : 'sql';
        $filename = "backup-{$connection}-{$stamp}-".Str::random(6).".{$extension}";
        $target = $this->targetDirectory();

        if (! is_dir($target) && ! @mkdir($target, 0750, true) && ! is_dir($target)) {
            $this->error("Unable to create the backup directory: {$target}");

            return self::FAILURE;
        }

        $path = rtrim($target, '/').'/'.$filename;

        try {
            if ($driver === 'sqlite') {
                $this->backupSqlite($connection, $path);
                $bytes = filesize($path);
                if ($bytes === false || $bytes === 0) {
                    throw new \RuntimeException('The SQLite backup is missing or empty.');
                }
            } else {
                $sql = $this->dumpMysql($connection);
                if (trim($sql) === '') {
                    throw new \RuntimeException('The dump came back empty; refusing to write a useless backup.');
                }
                if (file_put_contents($path, $sql, LOCK_EX) === false) {
                    throw new \RuntimeException("Unable to write the backup to {$path}");
                }
                $bytes = strlen($sql);
            }
        } catch (\Throwable $e) {
            if (is_file($path)) {
                @unlink($path);
            }
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        @chmod($path, 0640);
        $this->info('Wrote '.$path.' ('.number_format($bytes).' bytes)');

        $this->prune($target, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * Use SQLite's online backup operation rather than copying the main file.
     * A raw copy can omit committed WAL data and is not an SQL dump despite its
     * extension. VACUUM INTO produces a consistent, standalone SQLite database.
     */
    private function backupSqlite(string $connection, string $path): void
    {
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === ':memory:') {
            throw new \RuntimeException('An in-memory sqlite database has nothing to back up.');
        }

        if (! is_file($database)) {
            throw new \RuntimeException("The sqlite file was not found: {$database}");
        }

        $pdo = DB::connection($connection)->getPdo();
        if ($pdo->inTransaction()) {
            throw new \RuntimeException('SQLite backup cannot run inside an active transaction.');
        }

        $quotedPath = $pdo->quote($path);
        if ($quotedPath === false) {
            throw new \RuntimeException('Unable to safely quote the SQLite backup path.');
        }

        $pdo->exec('VACUUM INTO '.$quotedPath);

        $backup = new \PDO('sqlite:'.$path);
        $backup->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $integrity = $backup->query('PRAGMA quick_check')->fetchColumn();
        $backup = null;

        if ($integrity !== 'ok') {
            throw new \RuntimeException('SQLite quick_check rejected the created backup.');
        }
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

        $existingFiles = array_merge(
            glob(rtrim($target, '/').'/backup-*.sql') ?: [],
            glob(rtrim($target, '/').'/backup-*.sqlite') ?: [],
        );
        $existing = collect($existingFiles)
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
