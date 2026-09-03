<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * The initial permission catalogue. Extensible for later phases.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'courses.view', 'courses.create', 'courses.update', 'courses.delete',
        'lessons.view', 'lessons.create', 'lessons.update', 'lessons.delete',
        'exams.view', 'exams.create', 'exams.update', 'exams.delete',
        'students.view', 'students.manage',
        'reports.view',
        'competitions.manage',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->assignAdminPermissions();
        $this->assignTeacherPermissions();
        $this->assignStudentPermissions();
    }

    private function assignAdminPermissions(): void
    {
        Role::findByName(UserRole::Admin->value)->givePermissionTo(self::PERMISSIONS);
    }

    private function assignTeacherPermissions(): void
    {
        Role::findByName(UserRole::Teacher->value)->givePermissionTo([
            'courses.view', 'courses.create', 'courses.update', 'courses.delete',
            'lessons.view', 'lessons.create', 'lessons.update', 'lessons.delete',
            'students.view',
            'reports.view',
        ]);
    }

    private function assignStudentPermissions(): void
    {
        Role::findByName(UserRole::Student->value)->givePermissionTo([
            'courses.view',
            'lessons.view',
        ]);
    }
}
