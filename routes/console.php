<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('students:check-renewals')->daily();

// Nightly rotating database backup. withoutOverlapping() stops a slow dump from
// being stacked by the next run.
\Illuminate\Support\Facades\Schedule::command('db:backup')
    ->dailyAt('03:00')
    ->withoutOverlapping(30);

