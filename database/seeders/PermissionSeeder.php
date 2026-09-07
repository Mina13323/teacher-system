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
        'students.view', 'students.create', 'students.manage',
        'teachers.view', 'teachers.create', 'teachers.manage',
        'assistants.view', 'assistants.create', 'assistants.manage',
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
        $this->assignAssistantPermissions();
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
            'exams.view', 'exams.create', 'exams.update', 'exams.delete',
            'students.view', 'students.create', 'students.manage',
            'assistants.view', 'assistants.create', 'assistants.manage',
            'reports.view',
            'competitions.manage',
        ]);
    }

    /**
     * An Assistant is operational staff that works for the (single main)
     * Teacher. Their role is limited to student operations: viewing/creating/
     * managing student accounts and enrolling them in courses. They are
     * deliberately NOT given any content (course/unit/lesson/video/exam),
     * competition, analytics/integrity, or system-management permission.
     */
    private function assignAssistantPermissions(): void
    {
        Role::findByName(UserRole::Assistant->value)->givePermissionTo([
            'courses.view',
            'students.view', 'students.create', 'students.manage',
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
