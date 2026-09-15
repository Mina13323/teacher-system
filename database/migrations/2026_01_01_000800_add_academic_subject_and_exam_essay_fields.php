<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'academic_subject')) {
                $table->string('academic_subject', 32)->nullable()->default('general')->after('academic_year');
                $table->index('academic_subject');
            }
            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('is_active');
            }
        });

        // Seed existing secondary_3 users with 'both' if academic_subject was null or default
        DB::table('users')
            ->where('academic_year', 'secondary_3')
            ->where(function ($q) {
                $q->whereNull('academic_subject')->orWhere('academic_subject', 'general');
            })
            ->update(['academic_subject' => 'both']);

        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'academic_year')) {
                $table->string('academic_year', 32)->nullable()->after('status');
                $table->index('academic_year');
            }
            if (! Schema::hasColumn('courses', 'academic_subject')) {
                $table->string('academic_subject', 32)->nullable()->after('academic_year');
                $table->index('academic_subject');
            }
        });

        Schema::table('exams', function (Blueprint $table) {
            if (! Schema::hasColumn('exams', 'academic_year')) {
                $table->string('academic_year', 32)->nullable()->after('status');
                $table->index('academic_year');
            }
            if (! Schema::hasColumn('exams', 'academic_subject')) {
                $table->string('academic_subject', 32)->nullable()->after('academic_year');
                $table->index('academic_subject');
            }
        });

        Schema::table('questions', function (Blueprint $table) {
            if (! Schema::hasColumn('questions', 'reference_answer')) {
                $table->text('reference_answer')->nullable()->after('points');
            }
        });

        Schema::table('exam_attempt_questions', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_attempt_questions', 'question_type')) {
                $table->string('question_type', 32)->nullable()->after('question_text');
            }
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_answers', 'answer_text')) {
                $table->text('answer_text')->nullable()->after('option_id');
            }
            if (! Schema::hasColumn('exam_answers', 'feedback')) {
                $table->text('feedback')->nullable()->after('points_earned');
            }
            if (! Schema::hasColumn('exam_answers', 'graded_by')) {
                $table->foreignId('graded_by')->nullable()->after('feedback')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('exam_answers', 'graded_at')) {
                $table->timestamp('graded_at')->nullable()->after('graded_by');
            }
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_attempts', 'grades_published_at')) {
                $table->timestamp('grades_published_at')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('exam_attempts', 'graded_by')) {
                $table->foreignId('graded_by')->nullable()->after('grades_published_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('exam_attempts', 'graded_by')) {
                $table->dropForeign(['graded_by']);
                $table->dropColumn('graded_by');
            }
            if (Schema::hasColumn('exam_attempts', 'grades_published_at')) {
                $table->dropColumn('grades_published_at');
            }
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            if (Schema::hasColumn('exam_answers', 'graded_by')) {
                $table->dropForeign(['graded_by']);
                $table->dropColumn('graded_by');
            }
            $cols = array_filter(['answer_text', 'feedback', 'graded_at'], fn ($c) => Schema::hasColumn('exam_answers', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasColumn('questions', 'reference_answer')) {
                $table->dropColumn('reference_answer');
            }
        });

        Schema::table('exams', function (Blueprint $table) {
            $cols = array_filter(['academic_year', 'academic_subject'], fn ($c) => Schema::hasColumn('exams', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('courses', function (Blueprint $table) {
            $cols = array_filter(['academic_year', 'academic_subject'], fn ($c) => Schema::hasColumn('courses', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter(['academic_subject', 'must_change_password'], fn ($c) => Schema::hasColumn('users', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
