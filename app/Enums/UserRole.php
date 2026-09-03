<?php

namespace App\Enums;

/**
 * Canonical role names used across the application.
 *
 * These values map to the Spatie `roles` table storing role names by guard.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Student = 'student';
}
