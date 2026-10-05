<?php

namespace App\Console\Commands;

use App\Services\LoadTest\LoadTestFixtureSeeder;
use App\Services\LoadTest\LoadTestSafetyException;
use Illuminate\Console\Command;

class LoadTestSeedCommand extends Command
{
    protected $signature = 'loadtest:seed
        {--students= : Number of fixture students (1..max, default from config)}
        {--window-days=7 : Days the exam window stays open from now}
        {--reset-attempts : Also delete existing fixture attempts so the exam can be retaken}';

    protected $description = 'STAGING ONLY: create/refresh the deterministic load-test teacher, course, exam and students';

    public function handle(LoadTestFixtureSeeder $seeder): int
    {
        $students = $this->option('students') === null
            ? (int) config('loadtest.default_students')
            : (int) $this->option('students');

        try {
            $result = $seeder->seed($students, max(1, (int) $this->option('window-days')), (bool) $this->option('reset-attempts'));
        } catch (LoadTestSafetyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Load-test fixtures ready.');
        $this->table(['Item', 'Value'], [
            ['Students (total in fixture)', $result['students']],
            ['Students newly created', $result['created']],
            ['Teacher', config('loadtest.teacher_email').' (id '.$result['teacher_id'].')'],
            ['Course', config('loadtest.course_slug').' (id '.$result['course_id'].')'],
            ['Exam', config('loadtest.exam_title').' (id '.$result['exam_id'].')'],
            ['Questions', $result['questions']],
            ['Student login', LoadTestFixtureSeeder::studentEmail(1).' .. '.LoadTestFixtureSeeder::studentEmail($students)],
        ]);
        $this->line('Password: see LOAD_TEST_FIXTURES.md (config loadtest.password / LOADTEST_PASSWORD).');

        return self::SUCCESS;
    }
}
