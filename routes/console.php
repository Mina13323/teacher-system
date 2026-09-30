<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('students:check-renewals')->daily();

// Exam lifecycle: finalize attempts whose deadline passed (auto-submit + grade
// the saved answers per each exam's expiry policy). Every minute so an attempt
// never dangles waiting for "the next student request". Idempotent.
\Illuminate\Support\Facades\Schedule::command('attempts:process-expired')
    ->everyMinute()
    ->withoutOverlapping(5);

// Scheduled reminders (exam open/close, assignment due, competition ending):
// hourly; idempotent per recipient via dedupe keys; quiet hours retry later.
\Illuminate\Support\Facades\Schedule::command('reminders:dispatch')
    ->hourlyAt(7)
    ->withoutOverlapping(10);

// Nightly rotating database backup. withoutOverlapping() stops a slow dump from
// being stacked by the next run.
\Illuminate\Support\Facades\Schedule::command('db:backup')
    ->dailyAt('03:00')
    ->withoutOverlapping(30);

