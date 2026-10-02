<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\ApiTestCase;

/**
 * Covers the db:backup command.
 *
 * Runs against a throwaway sqlite file rather than :memory: so there is real
 * data to snapshot, and cleans up every artifact it produces.
 */
class BackupDatabaseTest extends ApiTestCase
{
    private const CONNECTION = 'backup_test';

    private string $sqliteFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqliteFile = tempnam(sys_get_temp_dir(), 'elm-bk-').'.sqlite';
        touch($this->sqliteFile);

        if (! is_dir($this->backupDir())) {
            mkdir($this->backupDir(), 0750, true);
        }

        config()->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => $this->sqliteFile,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        Schema::connection(self::CONNECTION)->create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('label')->nullable();
        });

        DB::connection(self::CONNECTION)->statement('PRAGMA journal_mode=WAL');
        DB::connection(self::CONNECTION)->table('widgets')->insert(['label' => 'spare part']);
    }

    protected function tearDown(): void
    {
        foreach ($this->backups() as $file) {
            @unlink($file);
        }

        DB::disconnect(self::CONNECTION);
        foreach ([$this->sqliteFile, $this->sqliteFile.'-wal', $this->sqliteFile.'-shm'] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function backupDir(): string
    {
        return storage_path('app/backups');
    }

    /** @return list<string> */
    private function backups(): array
    {
        return array_merge(
            glob($this->backupDir().'/backup-'.self::CONNECTION.'-*.sql') ?: [],
            glob($this->backupDir().'/backup-'.self::CONNECTION.'-*.sqlite') ?: [],
        );
    }

    public function test_it_writes_a_valid_standalone_sqlite_backup_with_schema_and_data(): void
    {
        $this->artisan('db:backup', ['--connection' => self::CONNECTION])
            ->assertSuccessful();

        $files = $this->backups();

        $this->assertNotEmpty($files, 'No backup file was written.');
        $this->assertStringEndsWith('.sqlite', $files[0], 'A binary SQLite snapshot must not use an .sql extension.');
        $this->assertSame("SQLite format 3\0", file_get_contents($files[0], false, null, 0, 16));

        $restorePrefix = tempnam(sys_get_temp_dir(), 'elm-restore-');
        $restoreFile = $restorePrefix.'.sqlite';
        @unlink($restorePrefix);
        $this->assertTrue(copy($files[0], $restoreFile));

        config()->set('database.connections.backup_restore_test', [
            'driver' => 'sqlite',
            'database' => $restoreFile,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('backup_restore_test');

        $this->assertTrue(Schema::connection('backup_restore_test')->hasTable('widgets'));
        $this->assertSame(
            'spare part',
            DB::connection('backup_restore_test')->table('widgets')->value('label'),
            'The standalone copy must restore representative table data.'
        );

        DB::disconnect('backup_restore_test');
        @unlink($restoreFile);
    }

    public function test_it_prunes_backups_beyond_the_retention_count(): void
    {
        // Older dumps with staggered mtimes so ordering is deterministic.
        for ($i = 0; $i < 4; $i++) {
            $old = $this->backupDir().'/backup-'.self::CONNECTION."-2020010{$i}-000000-old{$i}.sql";
            file_put_contents($old, '-- old backup');
            touch($old, time() - (1000 - $i));
        }

        $this->artisan('db:backup', ['--connection' => self::CONNECTION, '--keep' => 2])
            ->assertSuccessful();

        $this->assertCount(
            2,
            $this->backups(),
            'Retention must keep exactly --keep files, newest first.'
        );
    }

    public function test_it_rejects_an_unsupported_remote_disk_instead_of_writing_locally(): void
    {
        $this->artisan('db:backup', ['--connection' => self::CONNECTION, '--disk' => 's3'])
            ->assertFailed();

        $this->assertEmpty($this->backups());
    }

    public function test_it_fails_loudly_on_an_in_memory_database(): void
    {
        config()->set('database.connections.'.self::CONNECTION.'.database', ':memory:');

        $this->artisan('db:backup', ['--connection' => self::CONNECTION])
            ->assertFailed();

        $this->assertEmpty($this->backups(), 'A failed run must not leave a misleading backup behind.');
    }
}
