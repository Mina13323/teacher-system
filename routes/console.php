<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled commands run in-process through Artisan::call rather than
// Schedule::command, which shells out via proc_open. Hostinger's shared PHP
// disables proc_open, so every Schedule::command event failed with a
// LogicException each minute and none of these jobs ever ran there.
$inProcess = fn (string $command) => Schedule::call(fn () => Artisan::call($command))
    ->name($command);

$inProcess('students:check-renewals')->daily();

// Exam lifecycle: finalize attempts whose deadline passed (auto-submit + grade
// the saved answers per each exam's expiry policy). Every minute so an attempt
// never dangles waiting for "the next student request". Idempotent.
$inProcess('attempts:process-expired')
    ->everyMinute()
    ->withoutOverlapping(5);

// Scheduled reminders (exam open/close, assignment due, competition ending):
// hourly; idempotent per recipient via dedupe keys; quiet hours retry later.
$inProcess('reminders:dispatch')
    ->hourlyAt(7)
    ->withoutOverlapping(10);

// Nightly rotating database backup. withoutOverlapping() stops a slow dump from
// being stacked by the next run.
$inProcess('db:backup')
    ->dailyAt('03:00')
    ->withoutOverlapping(30);
