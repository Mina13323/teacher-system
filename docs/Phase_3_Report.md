# Phase 3 — Examination System: Final Report

## PHASE 3.1 — Examination Hardening (Amendment)

This hardening pass fixes a critical snapshot-integrity defect in the Phase 3
Examination System and makes the immutable historical behavior explicit and
database-enforced. No Phase 4 features were added.

> **Exam attempts are immutable historical representations of the exam
> configuration/content at the time the attempt was created.** Teacher changes
> (edit, reorder, delete, change correct answer, change points, change pass
> percentage) to the live exam never affect an existing attempt.

### Critical issue fixed: snapshot deletion integrity

Previously, `exam_attempt_questions.question_id` and
`exam_attempt_options.option_id` had `cascadeOnDelete()` foreign keys back to the
live `questions`/`options` tables, and `exam_answers` also cascaded on
`question_id`. Deleting a live question or option therefore destroyed the
historical snapshot and answer records of any attempt that referenced it.

**Fix:** the snapshot tables and `exam_answers` now treat `question_id` /
`option_id` as weak, denormalized references (the FK constraints are dropped).
The snapshot already stores its own copies of `question_text`, `points`,
`position`, `option_text`, `is_correct`, `position`, so it is fully
self-contained. Deleting a live question/option now leaves the attempt's frozen
snapshot, answers, and grading intact, exactly as required.

### Deletion strategy

- **Live question/option deletion is a hard delete** of the live record (it is
  removed from future exam usage), but it **never** cascades into
  `exam_attempt_questions`, `exam_attempt_options`, or `exam_answers`.
- A **new** attempt uses the **current** (post-deletion) exam version; an
  **existing** attempt keeps its frozen historical representation.
- Parent-record cascades are retained where they are semantically correct:
  deleting an exam/attempt still removes its own snapshots/answers (an attempt's
  full lifecycle is one unit); only the live question/option → historical
  attempt bridge is removed.

### Scoring integrity

Grading is computed entirely from the attempt's frozen snapshot
(`attemptQuestions.attemptOptions`), so the final score, percentage, and pass/fail
are stable. Changing a question's points, the correct option, deleting an option
or question, or changing the exam's settings cannot retroactively change a
submitted attempt. The `pass_percentage` used for pass/fail is frozen onto the
attempt at start (new `exam_attempts.pass_percentage` column), so
`score` / `percentage` / `passed` remain stable regardless of later exam edits.

## PHASE

Examination System for the Laravel 11 Teacher LMS, building on Phases 1 (auth +
course structure) and 2 (enrollment + lesson progress) without rebuilding them or
changing the established auth / authorization / API versioning / course /
enrollment / progress architecture. Everything is mounted under `/api/v1` and
reuses the existing envelope, thin-controller + Action pattern, Form Requests,
Policies, and API Resources.

## IMPLEMENTED

- **Exam domain model** with lifecycle `draft` → `published` → `archived` and
  settings `duration_minutes`, `pass_percentage`, `max_attempts`,
  `shuffle_questions`, `shuffle_options`, `show_result_immediately`. Belongs to
  a course and a teacher creator.
- **Questions** (initial type `single_choice`, extensible enum) with `points`,
  `position`; **options** with `is_correct`, `position`.
- **Exam attempts** with `attempt_number` (unique per student+exam), timing,
  `score`, `percentage`, and status `in_progress` | `submitted` | `expired`.
- **Exam answers** (safe per-attempt storage; no duplicate answers per question).
- **Snapshot strategy** (`exam_attempt_questions` + `exam_attempt_options`)
  freezes question/option structure, order, text, points and correctness at
  attempt start; randomization occurs **once** there. Teacher edits to an exam
  do not mutate an in-progress attempt.
- **Teacher management** of exams, questions and options + publish validation.
- **Student discovery** (published + enrolled only, no answer key), **start
  attempt** (reuse active attempt, attempt-limit enforcement, snapshot build,
  server-side `expires_at`), **attempt view**, **answering**, **submit** with
  server-side grading, idempotency, and configurable immediate result.
- **Attempt history** for students and **attempt visibility** for teachers.
- **Backend-enforced timing** (`expires_at = started_at + duration_minutes`),
  with expired attempts rejected for answers/submission and transitioned to
  `expired`.
- **Focused Actions** (Create/Update/Delete/Publish/ArchiveExamAction,
  BuildAttemptSnapshotAction, StartExamAttemptAction, SaveExamAnswerAction,
  SubmitExamAttemptAction, GradeExamAttemptAction, CalculateExamResultAction,
  ExpireExamAttemptAction), **thin controllers**, **Form Requests**, and
  **Policies**.
- **Permission gap fixed**: teachers are now granted `exams.create/update/delete`
  (and already had `exams.view`) in `PermissionSeeder`.

## SECURITY

- **Backend is the single source of truth** for correct answers, timing, attempt
  ownership, status, grading, score, and pass/fail. Client-supplied
  `score` / `percentage` / `passed` / `is_correct` are never trusted.
- **Answer key is never leaked to students.** Student-facing resources
  (`StudentExamResource`, `StudentExamDetailResource`, `ExamAttemptResource`,
  `ExamResultResource`) exclude `is_correct` / `correct_option` / answer key.
  `is_correct` appears only in teacher-facing resources (`OptionResource`,
  `ExamAttemptDetailResource`).
- **Ownership + enrollment checks** gate every student route; teacher routes are
  gated by exam/course ownership and `exams.*` permissions.
- **Server-side timing wins** over any frontend timer; expired attempts are
  rejected and transitioned.
- **Idempotent submission** — re-submitting a submitted attempt returns the
  existing result without re-grading or mutation.
- **Attempt state machine** enforced: `in_progress` → `submitted`/`expired`;
  invalid transitions (e.g. submitting/answering an expired attempt) are refused.

## AUTHORIZATION

- New policies registered in `AppServiceProvider`: `ExamPolicy`,
  `QuestionPolicy`, `OptionPolicy`, `ExamAttemptPolicy`. Admins are allowed
  every capability via the existing `before` hook.
- Teachers may manage exams/questions/options they own (or whose course they
  own); students may start/answer/submit only their own attempts.
- `PermissionSeeder`: teacher role now receives `exams.create`, `exams.update`,
  `exams.delete` (and already `exams.view`).
- Each controller authorizes via `$this->authorize(...)` and each Form Request
  authorizes in `authorize()`.

## DATABASE CHANGES

New migrations (cascade foreign keys where appropriate):

- `exams` (course_id, created_by, title, description, duration_minutes,
  pass_percentage, max_attempts, status, shuffle_questions, shuffle_options,
  show_result_immediately, timestamps).
- `questions` (exam_id, question_text, type, points, position).
- `options` (question_id, option_text, is_correct, position).
- `exam_attempts` (exam_id, student_id, attempt_number, started_at,
  submitted_at, expires_at, score, percentage, status; unique
  `student_id,exam_id,attempt_number`).
- `exam_attempt_questions` (attempt_id, question_id, frozen question_text,
  points, position; unique `attempt_id,question_id`).
- `exam_attempt_options` (attempt_question_id, option_id, frozen option_text,
  is_correct, position; unique `attempt_question_id,option_id`).
- `exam_answers` (attempt_id, question_id, option_id nullable, is_correct null,
  points_earned null, answered_at; unique `attempt_id,question_id`).

Phase 3.1 hardening migrations:

- `2026_01_01_000200_add_frozen_fields_to_exam_attempts.php` — adds
  `exam_attempts.pass_percentage` (frozen pass threshold) and
  `exam_attempts.active_key` (nullable, unique; set to `{student_id}:{exam_id}`
  while in progress) to guarantee at most one in-progress attempt per student +
  exam at the database level. Backfills existing rows.
- `2026_01_01_000201_protect_exam_snapshot_from_cascade_deletes.php` — drops the
  `cascadeOnDelete` FKs on `exam_attempt_questions.question_id`,
  `exam_attempt_options.option_id`, `exam_answers.question_id` and the
  `nullOnDelete` on `exam_answers.option_id`, so live question/option deletion no
  longer destroys historical snapshot/answer records.

## API ENDPOINTS

Full method/URL/auth/request/response/validation documentation is in
[`docs/API.md`](./API.md). Summary:

- **Teacher exams**: `GET/POST /teacher/courses/{course}/exams`,
  `GET/PUT/DELETE /teacher/exams/{exam}`, `POST /teacher/exams/{exam}/publish`,
  `POST /teacher/exams/{exam}/archive`,
  `GET /teacher/exams/{exam}/attempts`, `GET /teacher/attempts/{attempt}`.
- **Teacher questions/options**: `GET/POST /teacher/exams/{exam}/questions`,
  `GET/PUT/DELETE /teacher/questions/{question}`,
  `GET/POST /teacher/questions/{question}/options`,
  `PUT/DELETE /teacher/options/{option}`.
- **Student**: `GET /student/exams`, `GET /student/exams/{exam}`,
  `GET /student/exams/{exam}/attempts`, `POST /student/exams/{exam}/start`,
  `GET /student/attempts/{attempt}`,
  `POST /student/attempts/{attempt}/answers`,
  `POST /student/attempts/{attempt}/submit`.

## TESTS

Feature tests (following the existing `ApiTestCase` + `RefreshDatabase` base) are
provided under `tests/Feature/Exam/`:

- `TeacherExamManagementTest` — create/list/update/view/publish/archive/delete,
  publish validation, ownership + role gating.
- `QuestionManagementTest` — question/option CRUD, ownership gating, answer-key
  exposure in teacher resource.
- `StudentExamAccessTest` — published-only + enrollment-only discovery, no
  answer-key leakage, student cannot manage exams.
- `ExamAttemptTest` — start, snapshot freezing, active-attempt reuse, attempt
  limit, answering (+ foreign-option rejection), grading + idempotent submit,
  suppressed/immediate result, no answer-key in attempt view, cross-student
  access block, and expired-attempt rejection.
- `ExamAttemptVisibilityTest` — teacher attempt list/detail, ownership gating.
- `ExamSnapshotIntegrityTest` (Phase 3.1) — snapshot survives live question and
  option deletion, snapshot retains original question/option payload after live
  edits, grading uses the snapshot not the live exam after teacher changes,
  frozen pass-percentage semantics, reorder immunity, submitted/expired attempt
  immutability, active-attempt lifecycle, and active-key behavior.
- `ExamAttemptAuthorizationTest` (Phase 3.1) — cross-student IDOR rejection for
  viewing/answering/submitting another student's attempt and for the exam attempt
  history.

> **Verification note:** PHP/Composer are not installed in this environment, so
> `migrate:fresh --seed`, `php artisan test`, and `route:list` could not be run
> locally. Files were reviewed statically; the previous `.editorconfig`/coding
> conventions were followed. Tests should be run in a PHP-enabled environment to
> confirm green.

## PERFORMANCE

- Snapshot is built in a single transaction at attempt start (one query to load
  the exam graph, then batched inserts).
- `questions()`, `options()`, `attemptQuestions()`, `attemptOptions()` are
  ordered in the database to avoid repeated sorting.
- Indexes on common filters (`exams(course_id,status)`, `questions(exam_id,
  position)`, `options(question_id,position)`, `exam_attempts(exam_id,status)`,
  `exam_attempts(student_id)`, unique `(student_id,exam_id,attempt_number)`,
  unique `(attempt_id,question_id)`).
- Attempt-list endpoints paginate with `per_page`.
- Transient concern: an option/question live-text edit has no impact on an
  in-progress attempt thanks to the frozen snapshot.

## KNOWN LIMITATIONS

- **Attempt expiry is enforced lazily** (on access/answer/submit) rather than by
  a scheduled job; a fully idle attempt only transitions once a student (or a
  start/submit attempt) hits an endpoint that checks it. An optional scheduled
  task could sweep expired attempts.
- Only `single_choice` questions are implemented (type enum is extensible).
- `max_attempts` counts submitted + expired attempts (a past-deadline
  in-progress attempt is transitioned to expired on start, so it is counted).
- **Live question/option hard delete leaves the snapshot intact**, but the
  `question_id` / `option_id` on snapshot and answer rows become weak references
  (no FK) to records that may no longer exist. Snapshot content is authoritative;
  the live record is not required for correctness. This is intentionally a hard
  delete rather than a soft delete — a soft-delete layer was not required to
  preserve historical integrity and would have added unneeded complexity.
- `show_result_immediately` is still read from the live exam at submit time (it
  controls the submit response shape only, not grading). It is not frozen.
- The environment lacks PHP/Composer, so `migrate:fresh --seed`, `php artisan
  test`, and `route:list` were not executed here. SQLite `dropForeign` migrates
  via a table rebuild; migrations should be verified on a real clean database.

## NOT IMPLEMENTED

Per the stated scope, Phase 4 and beyond are intentionally **not** implemented:
Anti-Cheat, Competitions, Leaderboard, Subscriptions, Payments, Advanced
Analytics, AI Features, and Video Streaming.

## NEXT PHASE

Phase 4 (Anti-Cheat) — not started, per instructions. Future hardening could
include: scheduled task to expire idle attempts, snapshot-safe question/option
deletion (soft-snapshot or archive instead of cascade), additional question
types, and per-attempt result analytics for teachers.
