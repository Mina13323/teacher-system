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
     * The canonical OPERATIONAL LMS permission set.
     *
     * Teacher and Assistant receive exactly this set. An Assistant is not a
     * restricted staff role: for normal LMS work (content, students, exams,
     * competitions, analytics) the two are equivalent.
     *
     * A permission names a capability, never a job title — there is deliberately
     * no `assistant.courses.view` style duplication.
     *
     * Note that units, videos, questions and options have no permissions of
     * their own; their policies derive authorization from the owning course or
     * exam, so courses.* and lessons.* already cover them.
     *
     * @var list<string>
     */
    public const OPERATIONAL_PERMISSIONS = [
        'courses.view', 'courses.create', 'courses.update', 'courses.delete',
        'lessons.view', 'lessons.create', 'lessons.update', 'lessons.delete',
        'exams.view', 'exams.create', 'exams.update', 'exams.delete',
        'students.view', 'students.create', 'students.manage',
        'reports.view',
        'competitions.manage',
    ];

    /**
     * Staff identity administration: creating, editing, deactivating and
     * resetting the credentials of Teacher and Assistant accounts.
     *
     * These are deliberately NOT part of the operational set. Minting or
     * resetting another staff identity is account/identity administration
     * rather than LMS work, and an Assistant must not be able to create new
     * staff accounts or reset their passwords. Held by Teacher (who employs
     * their own assistants) and Admin.
     *
     * @var list<string>
     */
    public const STAFF_ADMINISTRATION_PERMISSIONS = [
        'teachers.view', 'teachers.create', 'teachers.manage',
        'assistants.view', 'assistants.create', 'assistants.manage',
    ];

    /**
     * The full catalogue: every permission that exists in the system. Written
     * out explicitly rather than spread from the two sets above, because array
     * unpacking is not reliably permitted in a constant expression.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'courses.view', 'courses.create', 'courses.update', 'courses.delete',
        'lessons.view', 'lessons.create', 'lessons.update', 'lessons.delete',
        'exams.view', 'exams.create', 'exams.update', 'exams.delete',
        'students.view', 'students.create', 'students.manage',
        'reports.view',
        'competitions.manage',
        'teachers.view', 'teachers.create', 'teachers.manage',
        'assistants.view', 'assistants.create', 'assistants.manage',
    ];

    /**
     * Student permissions. Students hold read-only course/lesson permissions;
     * they never receive an operational or staff permission.
     *
     * @var list<string>
     */
    public const STUDENT_PERMISSIONS = [
        'courses.view',
        'lessons.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->sync(UserRole::Admin, self::PERMISSIONS);
        $this->sync(UserRole::Teacher, [...self::OPERATIONAL_PERMISSIONS, ...self::STAFF_ADMINISTRATION_PERMISSIONS]);
        $this->sync(UserRole::Assistant, self::OPERATIONAL_PERMISSIONS);
        $this->sync(UserRole::Student, self::STUDENT_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Assign a role's canonical permission set.
     *
     * syncPermissions() is used rather than givePermissionTo() so that running
     * the seeder repeatedly converges on exactly this set instead of
     * accumulating or duplicating grants, and so that a permission removed from
     * the canonical set is actually revoked.
     */
    private function sync(UserRole $role, array $permissions): void
    {
        Role::findOrCreate($role->value)->syncPermissions($permissions);
    }
}
