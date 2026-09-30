<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course-completion certificates with a unique verification code.
 *
 * PRODUCTION DATA SAFETY: new table only. `code` is a random public
 * verification handle (never derived from student data); the public
 * verification endpoint reveals only the minimum (student display name, course
 * title, issue date) and is rate limited.
 *
 * Rollback: drops issued certificates (none exist before this migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->string('code', 64)->unique();
            $table->timestamp('issued_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // One certificate per student per course.
            $table->unique(['student_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
