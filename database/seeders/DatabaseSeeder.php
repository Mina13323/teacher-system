<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            TeacherAccountSeeder::class,
        ]);

        if (filter_var(env('DEMO_CONTENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->call(DemoContentSeeder::class);
        }
    }
}
