<?php

namespace App\Console\Commands;

use App\Services\LoadTest\LoadTestCleaner;
use App\Services\LoadTest\LoadTestSafetyException;
use Illuminate\Console\Command;

class LoadTestCleanCommand extends Command
{
    protected $signature = 'loadtest:clean
        {--dry-run : Report what would be deleted and roll everything back}';

    protected $description = 'STAGING ONLY: delete every load-test fixture record (and nothing else)';

    public function handle(LoadTestCleaner $cleaner): int
    {
        try {
            $result = $cleaner->clean((bool) $this->option('dry-run'));
        } catch (LoadTestSafetyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') ? 'Dry run (nothing deleted):' : 'Load-test fixtures removed:');
        $this->table(['Table', 'Rows'], collect($result['deleted'])->map(fn ($n, $t) => [$t, $n])->values()->all());
        foreach ($result['skipped'] as $note) {
            $this->warn($note);
        }

        return self::SUCCESS;
    }
}
