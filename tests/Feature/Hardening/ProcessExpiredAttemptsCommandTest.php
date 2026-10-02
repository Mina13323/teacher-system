<?php

namespace Tests\Feature\Hardening;

use App\Actions\Exam\FinalizeExpiredAttemptAction;
use App\Models\ExamAttempt;
use Illuminate\Console\Command;
use Tests\Feature\ApiTestCase;

class ProcessExpiredAttemptsCommandTest extends ApiTestCase
{
    public function test_command_returns_failure_when_any_expired_attempt_fails_but_continues_processing(): void
    {
        $attempts = ExamAttempt::factory()->count(2)->create([
            'expires_at' => now()->subMinute(),
        ]);
        $failedId = (int) $attempts->first()->getKey();
        $finalizer = new class($failedId) extends FinalizeExpiredAttemptAction
        {
            public array $seen = [];

            public function __construct(private readonly int $failedId)
            {
            }

            public function execute(ExamAttempt $attempt): ExamAttempt
            {
                $this->seen[] = $attempt->getKey();
                if ((int) $attempt->getKey() === $this->failedId) {
                    throw new \RuntimeException('simulated finalization failure');
                }

                return $attempt;
            }
        };
        $this->app->instance(FinalizeExpiredAttemptAction::class, $finalizer);

        $this->artisan('attempts:process-expired')
            ->expectsOutputToContain('1 failed')
            ->assertExitCode(Command::FAILURE);

        $this->assertCount(2, $finalizer->seen, 'one failed row must not stop later rows from processing');
    }
}
