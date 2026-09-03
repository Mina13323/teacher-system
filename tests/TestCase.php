<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure Spatie permission cache never leaks between tests.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
