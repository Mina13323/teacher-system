<?php

/*
|--------------------------------------------------------------------------
| Load-test fixtures (STAGING ONLY)
|--------------------------------------------------------------------------
|
| Consumed exclusively by `php artisan loadtest:seed` / `loadtest:clean`.
| Every command refuses to run unless ALL safety conditions below hold.
| See LOAD_TEST_FIXTURES.md.
|
*/

return [

    // Safety guard: every one of these must match or the commands abort.
    'required_env' => 'staging',
    'required_host' => 'staging.maherelmasry.com',
    'required_database' => 'u481922752_staging',

    // Identity of the fixture records (the markers cleanup relies on).
    'email_domain' => 'staging.maherelmasry.com',
    'student_email_prefix' => 'loadtest.student.',
    'student_code_prefix' => 'LT-',
    'teacher_email' => 'loadtest.teacher@staging.maherelmasry.com',
    'course_slug' => 'load-test-course',
    'course_title' => 'Load Test Course',
    'exam_title' => 'Load Test Exam (load-test-exam)',

    // Dedicated, documented, staging-only password for every fixture account.
    'password' => env('LOADTEST_PASSWORD') ?: 'LoadTest#Staging-2026',

    'max_students' => 5000,
    'default_students' => 10,
];
