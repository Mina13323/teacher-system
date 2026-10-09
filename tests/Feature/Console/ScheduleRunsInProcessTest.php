<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Hostinger's shared PHP disables proc_open, so a Schedule::command event (which
 * shells out to `php artisan ...`) throws on every tick there. Every scheduled
 * job must run in-process.
 */
class ScheduleRunsInProcessTest extends TestCase
{
    public function test_every_scheduled_event_runs_in_process(): void
    {
        $events = $this->app->make(Schedule::class)->events();

        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertInstanceOf(CallbackEvent::class, $event, "{$event->description} would need proc_open");
        }
    }

    public function test_the_expired_attempts_job_is_scheduled_every_minute(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->firstWhere('description', 'attempts:process-expired');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }
}
