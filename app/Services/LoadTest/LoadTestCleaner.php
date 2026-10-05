<?php

namespace App\Services\LoadTest;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Removes ONLY load-test fixture records, identified by deterministic markers:
 *   - users:   email loadtest.student.NNNN@<domain>, or the teacher email
 *   - course:  slug load-test-course owned by the fixture teacher
 *   - exam:    belongs to that course
 *   - access:  periods of fixture students
 *
 * Rows are deleted children-first so foreign keys are always respected. If
 * foreign (non-fixture) data hangs off the fixture course/exam, that course and
 * exam are left in place and reported instead of being deleted.
 */
class LoadTestCleaner
{
    public function __construct(private readonly LoadTestGuard $guard)
    {
    }

    private function studentIds(): Builder
    {
        return DB::table('users')
            ->select('id')
            ->where('email', 'like', config('loadtest.student_email_prefix').'%@'.config('loadtest.email_domain'))
            ->where('student_code', 'like', config('loadtest.student_code_prefix').'%');
    }

    private function teacherIds(): Builder
    {
        return DB::table('users')->select('id')->where('email', config('loadtest.teacher_email'));
    }

    private function courseIds(): Builder
    {
        return DB::table('courses')->select('id')
            ->where('slug', config('loadtest.course_slug'))
            ->whereIn('created_by', $this->teacherIds());
    }

    private function examIds(): Builder
    {
        return DB::table('exams')->select('id')->whereIn('course_id', $this->courseIds());
    }

    /** Fixture students' attempts on fixture exams. */
    private function attemptIds(): Builder
    {
        return DB::table('exam_attempts')->select('id')
            ->whereIn('exam_id', $this->examIds())
            ->whereIn('student_id', $this->studentIds());
    }

    /**
     * Delete every attempt (and its snapshot/answers/integrity rows) made by
     * fixture students on the fixture exam, so the exam can be retaken.
     *
     * @return int attempts removed
     */
    public function deleteAttempts(): int
    {
        $this->guard->assertStaging();

        return DB::transaction(fn () => $this->purgeAttempts());
    }

    /**
     * @return array<string,int> rows deleted per table (plus "skipped" notes)
     */
    public function clean(bool $dryRun = false): array
    {
        $this->guard->assertStaging();

        $counts = [];
        $skipped = [];

        DB::beginTransaction();
        try {
            $counts['exam_attempts'] = $this->purgeAttempts();

            // Foreign (non-fixture) data on the fixture course/exam: keep them.
            $foreignAttempts = DB::table('exam_attempts')->whereIn('exam_id', $this->examIds())->count();
            $foreignEnrollments = DB::table('enrollments')
                ->whereIn('course_id', $this->courseIds())
                ->whereNotIn('student_id', $this->studentIds())
                ->count();
            $competitions = DB::table('competitions')->whereIn('exam_id', $this->examIds())->count();
            $keepContent = $foreignAttempts > 0 || $foreignEnrollments > 0 || $competitions > 0;
            if ($keepContent) {
                $skipped[] = "course/exam kept: non-fixture data present (attempts={$foreignAttempts}, "
                    ."enrollments={$foreignEnrollments}, competitions={$competitions})";
            }

            $counts['exam_make_up_assignments'] = DB::table('exam_make_up_assignments')
                ->whereIn('student_id', $this->studentIds())
                ->whereIn('exam_id', $this->examIds())->delete();
            $counts['enrollments'] = DB::table('enrollments')
                ->whereIn('course_id', $this->courseIds())
                ->whereIn('student_id', $this->studentIds())->delete();
            $counts['student_access_periods'] = DB::table('student_access_periods')
                ->whereIn('student_id', $this->studentIds())->delete();

            if (! $keepContent) {
                $counts['exam_integrity_settings'] = DB::table('exam_integrity_settings')
                    ->whereIn('exam_id', $this->examIds())->delete();
                $counts['options'] = DB::table('options')->whereIn('question_id',
                    DB::table('questions')->select('id')->whereIn('exam_id', $this->examIds())
                )->delete();
                $counts['questions'] = DB::table('questions')->whereIn('exam_id', $this->examIds())->delete();
                $counts['exams'] = DB::table('exams')->whereIn('course_id', $this->courseIds())->delete();
                $counts['courses'] = DB::table('courses')
                    ->where('slug', config('loadtest.course_slug'))
                    ->whereIn('created_by', $this->teacherIds())->delete();
            }

            // Per-user residue created by logging in / using the API.
            foreach ([$this->studentIds(), $this->teacherIds()] as $ids) {
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $ids)->delete();
                DB::table('notifications')->where('notifiable_type', User::class)
                    ->whereIn('notifiable_id', $ids)->delete();
                DB::table('sessions')->whereIn('user_id', $ids)->delete();
                DB::table('audit_logs')->whereIn('actor_id', $ids)->delete();
                DB::table(config('permission.table_names.model_has_roles'))
                    ->where('model_type', User::class)->whereIn('model_id', $ids)->delete();
                DB::table(config('permission.table_names.model_has_permissions'))
                    ->where('model_type', User::class)->whereIn('model_id', $ids)->delete();
            }

            // Students first (they may reference the teacher via created_by).
            $counts['users_students'] = DB::table('users')->whereIn('id', $this->studentIds())->delete();

            // The teacher goes only when nothing but the fixture still points at them.
            $teacherHasOtherData = DB::table('courses')->whereIn('created_by', $this->teacherIds())->exists()
                || DB::table('exams')->whereIn('created_by', $this->teacherIds())->exists();
            if (! $teacherHasOtherData) {
                $counts['users_teacher'] = DB::table('users')->whereIn('id', $this->teacherIds())->delete();
            } else {
                $skipped[] = 'teacher kept: still owns content';
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if (! $dryRun) {
            \App\Services\CourseCatalogCache::flush();
        }

        return ['deleted' => $counts, 'skipped' => $skipped];
    }

    /**
     * Children-first removal of fixture attempts. Must run inside a transaction.
     */
    private function purgeAttempts(): int
    {
        $attempts = fn () => $this->attemptIds();

        DB::table('competition_results')->whereIn('attempt_id', $attempts())->delete();
        DB::table('exam_make_up_assignments')->whereIn('attempt_id', $attempts())->update(['attempt_id' => null]);
        DB::table('exam_integrity_reviews')->whereIn('attempt_id', $attempts())->delete();
        DB::table('exam_integrity_events')->whereIn('attempt_id', $attempts())->delete();
        DB::table('exam_attempt_integrity_settings')->whereIn('attempt_id', $attempts())->delete();

        $answers = fn () => DB::table('exam_answers')->select('id')->whereIn('attempt_id', $attempts());
        DB::table('exam_answer_options')->whereIn('answer_id', $answers())->delete();
        DB::table('exam_answers')->whereIn('attempt_id', $attempts())->delete();

        $attemptQuestions = fn () => DB::table('exam_attempt_questions')->select('id')->whereIn('attempt_id', $attempts());
        DB::table('exam_attempt_options')->whereIn('attempt_question_id', $attemptQuestions())->delete();
        DB::table('exam_attempt_questions')->whereIn('attempt_id', $attempts())->delete();

        // Hard delete (the model soft-deletes); includes already soft-deleted rows.
        return DB::table('exam_attempts')->whereIn('id', $attempts()->pluck('id')->all() ?: [0])->delete();
    }
}
