# Backend Finalization Report

**Date:** 2026-09-07
**Scope:** Final static finalization pass over the Teacher-owned LMS backend.
**Status:** `BACKEND FEATURE COMPLETE — STATIC VERIFICATION`

The backend feature scope is now **FROZEN**. No new domains, no feature expansion, no
subscriptions/plans/entitlements/payments/billing/marketplace/seller/monetization.
Future work on this backend is limited to **bug fixing, runtime verification, security
hardening, performance optimization, and deployment preparation.**

---

## 1. Final architecture summary

A **Teacher-owned LMS** built on Laravel 11 + Sanctum + Spatie Roles/Permissions. Thin
controllers delegate business logic to **Actions**; validation and authorization live in
**Form Requests** and **Policies**; API shape is defined by **Resources**; shared
behavior lives in a small number of **Services**; exceptions map to consistent JSON
status codes in `bootstrap/app.php`.

```
Client ──> /api/v1/* (auth:sanctum) ──> Controller (thin) ──> FormRequest (validate+authorize)
                                              │
                                              ├── Policy (ownership/role checks)
                                              ├── Action (business rules, transactions)
                                              │     └── Models / Repositories (Services)
                                              └── Resource (response shape, field hiding)
```

- **Auth:** Sanctum tokens (personal access tokens); `role`/`permission` middleware
  aliases available.
- **RBAC:** three roles — `teacher`, `student`, `admin` — with a permission catalogue in
  `PermissionSeeder`.
- **Response envelope:** `{ success, message, data }` via the `ApiResponse` trait on the
  base `Controller`; errors are `{ success, message, errors? }`.
- **Errors:** every business exception is rendered as JSON with a sensible status code
  (400/401/403/404/409/422/500) in `bootstrap/app.php`; `ForceJsonResponse` guarantees
  JSON.

## 2. Completed domains

| Domain | Status |
|---|---|
| Authentication & profile | ✅ |
| Teacher / Admin account management | ✅ |
| Student account lifecycle | ✅ |
| Course / Unit / Lesson / Video content | ✅ |
| Enrollment & lesson progress | ✅ |
| Exams (CRUD, publish, snapshot, attempts, grading) | ✅ |
| Anti-cheat / integrity (settings, events, risk, review) | ✅ |
| Competitions (lifecycle, join, scoring, ranking, disqualification) | ✅ |
| Analytics (teacher / student / admin) | ✅ |
| In-app notifications | ✅ |
| Permissions & policies | ✅ |

## 3. Authentication & authorization model

- **Login/register/logout/me** via `AuthController` + `LoginUserAction`/`RegisterUserAction`.
  `AccountDisabledException` → 403; `InvalidCredentialsException` → 401.
- **Sanctum** token auth; `auth:sanctum` middleware on all protected route groups.
- **Role & permission** checks via Spatie (`hasRole`, `hasPermissionTo`, `can`).
- **Policies** gate every model: Course, Unit, Lesson, Video, Question, Option, Exam,
  ExamAttempt, Enrollment, LessonProgress, Competition, and `User` (StudentPolicy).
- **`before`** hooks grant admins full access on the relevant policies; all `view`/`update`
  abilities require ownership.

## 4. Teacher lifecycle

- Create/manage **courses**, **units**, **lessons**, **videos** (ownership-scoped;
  `CoursePolicy`/`UnitPolicy`/`LessonPolicy`/`VideoPolicy`).
- Create/manage **exams**, **questions**, **options** (`ExamPolicy`, `QuestionPolicy`,
  `OptionPolicy` → `isManagedBy`).
- **Publish/unpublish** content; **archive/delete** with ownership + history guards.
- **Recommend** exams (publish validation), review attempts, review integrity, manage
  competitions, review performance/analytics.
- **Manage students** they created or who are enrolled in their courses
  (`StudentPolicy::managesStudent`).

## 5. Student lifecycle

- **Teacher creates** student (name/email/password + optional phone/bio/course_ids).
- **Student logs in** with teacher-provided credentials; a deactivated account cannot log in.
- **Student completes/updates own profile** (name/avatar/phone/bio) via
  `GET`/`PUT /api/v1/auth/profile`; **cannot edit email** (no email field in
  `UpdateProfileRequest`).
- **Password:** self change (requires `current_password` + `different:current_password`,
  revokes other tokens); manager reset (revokes all tokens); deactivation revokes tokens.
- **Ownership/IDOR:** students only ever see their own enrollments, progress, attempts,
  results, and analytics.

## 6. LMS content lifecycle

Course/Unit/Lesson/Video form a nested tree with `position` ordering and `reorder`
endpoints; lesson/video carry `is_published`. Exams nest questions/options. Publication
is teacher-controlled; student-facing access is limited to published content in an
enrolled course. Deleting an exam/course with recorded attempts is **blocked** (409) to
preserve history (see §14/§15).

## 7. Enrollment / progress

- **Teacher** enrolls/unenrolls (idempotent; reactivates a cancelled enrollment).
- **Student** self-enrolls on published courses only (`EnrollStudentAction`,
  `DuplicateEnrollmentException` → 409).
- Enrollment is enforced as a precondition to exam access, progress tracking, and
  competition participation (`EnrollmentService`).
- **Progress:** create-or-update with a `completed`/`completed_at` invariant; scoped to
  the student's enrolled courses.

## 8. Examination system

- **Lifecycle:** draft → published → archived. Publish validation requires ≥1 question,
  ≥2 options/question, exactly one correct option, `duration>0`, `0≤pass≤100`,
  `max_attempts≥1`.
- **Snapshot:** `BuildAttemptSnapshotAction` freezes question/option text, points,
  positions and the answer key at attempt start (with configurable shuffle). Later
  teacher edits never affect an in-progress attempt.
- **Attempts:** `StartExamAttemptAction` enforces published + enrolled + attempt limit,
  resumes/expires stale attempts, and returns the existing in-progress attempt under
  concurrency (unique `active_key` index + `lockForUpdate`).
- **Answering:** `SaveExamAnswerAction` validates against the frozen snapshot only and
  rejects answers to questions/options not in the snapshot.
- **Grading:** server-side against the snapshot; `pass_percentage` is the frozen value;
  `score`/`percentage`/`submitted_at` are server-computed and never client-supplied.
- **Idempotent submission & expiry:** submitting an already-submitted attempt returns the
  prior result; expired attempts cannot be submitted.
- **Answer-key isolation:** student attempt resources never expose `is_correct`.

## 9. Anti-cheat / integrity

- **Frozen settings** per attempt (`ExamAttemptIntegritySetting`).
- **Client reportable** events are only browser-observable (tab switch, blur/focus,
  fullscreen enter/exit, copy/paste/cut/context-menu, keyboard-shortcut). The aggregate
  `MULTIPLE_SUSPICIOUS_EVENTS` is server-derived and rejected from the client.
- **Metadata capped** at ≤20 string values; no clipboard contents, keystroke streams, or
  arbitrary payloads.
- **Server-computed severity/risk points; deduplication window; re-evaluation of risk and
  status** after each event. Risk conclusions are never client-supplied.
- **Privacy-conscious:** no webcam, microphone, screen capture, key logging, GPS, or
  browsing history data is collected.
- **Teacher review** surfaces risk/severity/events through dedicated teacher endpoints.

## 10. Competitions / leaderboards

- **Lifecycle:** draft → published → active → ended, with lazy (read-triggered)
  finalization that freezes the leaderboard in a transaction.
- **Eligibility:** active window + enrolled in the linked exam's course; capacity enforced
  under a row lock; duplicate join rejected by a unique index.
- **Scoring:** `CalculateCompetitionScoreAction` picks the best submitted attempt by
  scoring type; **an attempt only counts if `submitted_at < ends_at`** (no `ends_at` ⇒
  any submitted attempt counts).
- **Ranking:** deterministic, standard competition ranking (equal scores share a rank);
  `qualified` = ranking eligibility, **never** exam pass/fail.
- **Disqualification:** preserves historical result but drops rank (re-ranks others);
  nothing is deleted.
- **Privacy:** leaderboards expose only a `publicDisplayName()` to students.

## 11. Analytics

- **Teacher:** overview, per-course, and per-student analytics, all scoped to teacher-owned
  data.
- **Student:** `/api/v1/student/analytics/me` is strictly self-scoped.
- **Admin:** platform-wide dashboard.
- Summaries include progress, completion, scores, pass/fail (via frozen threshold),
  averages, and integrity/suspicion counts.

## 12. Notifications

- **In-app** via Laravel's database channel (`notifications` table) — no third-party
  messaging infrastructure.
- **Events:** exam published, competition published, result available (contains **no
  score/answer key**), and teacher→student message.
- **Scoped inbox:** index/unread-count/read/read-all; cross-user read is guarded.

## 13. Admin capabilities

- **System overview** dashboard (platform totals).
- **Teacher management:** list/create/update/activate/deactivate/reset-password.
- **Student management:** list/show/update/activate/deactivate/reset-password/analytics.
- All guarded by `authorizeAdmin()` (403 unless admin); privileged operations are
  explicit and policy-protected; admin does not silently bypass business rules.

## 14. Security guarantees

- **IDOR:** every content/student/exam/attempt/competition access is ownership-scoped via
  a Policy; no cross-teacher access to another teacher's students or content.
- **Mass assignment:** no `request()->all()`; controllers use validated Form-Request data
  and Actions whitelist safe fields.
- **Client-controlled values:** score, percentage, rank, completion_time, qualification,
  result status, integrity risk conclusions, and `is_correct` are all **server-derived**.
- **Answer key leakage:** `is_correct` is only in teacher-facing resources
  (`ExamAttemptDetailResource`, `OptionResource`); never in student resources.
- **Sensitive-field exposure:** `password`/`remember_token` are `$hidden` and absent from
  all resources; integrity risk/severity are teacher-only.
- **Privilege escalation:** student/teacher cannot reach admin surfaces (403), and a
  student cannot manage exams/content (Policy/403).

## 15. Database integrity guarantees

- **FKs:** `users.created_by` → users (`nullOnDelete`); content/exam/competition FKs wired
  with `cascadeOnDelete`; exam attempts and their snapshots are protected (see below).
- **Unique indexes:** `users.email`; `courses.slug`; `lessons(unit_id,slug)`;
  `enrollments(student_id,course_id)`; `lesson_progress(student_id,lesson_id)`;
  `exam_attempts(student_id,exam_id,attempt_number)`; `active_key` (single active attempt);
  snapshot `(attempt_id,question_id)` / `(attempt_question_id,option_id)`;
  `exam_answers(attempt_id,question_id)`; `exam_integrity_settings.exam_id`;
  `exam_attempt_integrity_settings.attempt_id`;
  `competition_participants(competition_id,student_id)`;
  `competition_results(competition_id,participant_id)`.
- **Historical preservation:** the exam attempt snapshot FKs to live
  questions/options/answers are deliberately **removed** in
  `000201_protect_exam_snapshot_from_cascade_deletes` so a teacher editing/deleting live
  content never destroys an in-progress or submitted attempt's history.
- **Deletion guards:** an exam referenced by a competition, or with recorded attempts,
  **cannot be deleted** (409); likewise a course containing such exams cannot be deleted.
  Account termination uses **deactivation** (soft), not hard delete.
- **Concurrency/races:** `active_key`, attempt_number, participant and result unique
  indexes plus `lockForUpdate` transactions and duplicate-violation handling make join,
  attempt-start, answer, submit and disqualification/concurrency-safe.

## 16. API conventions

- **Versioning:** routes mounted under `/api/v1` (`bootstrap/app.php` `apiPrefix`).
- **Method/style:** RESTful, nested resources for content (courses → units → lessons →
  videos, exams → questions → options).
- **Envelope:** `{ success, message, data }` for success; `{ success, message, errors? }`
  for validation/errors.
- **Status codes:** 200/201 success, 400/401/403/404/409/422 by domain; JSON rendering is
  forced for all API requests.
- **Auth:** `auth:sanctum` on protected groups; public browsing limited to published
  courses.
- **Pagination/filtering:** `per_page` with sane caps; scoping by authenticated user.
- **Naming:** snake_case fields, ISO-8601 timestamps.

## 17. Static verification results

| Check | Result |
|---|---|
| PHP AST syntax (`check.js`, `php-parser` `version: 801`) | `TOTAL=363 BAD=0` |
| Referenced App/Test class existence (`refcheck.js`) | `References=304 MISSING=0` |
| Route → controller method resolution (all `/api/v1` routes) | ✅ all resolve |
| Controller dependency import/resolvability scan | ✅ no unresolved type-hints (only a comment keyword) |
| Public controller method authorization scan | ✅ every handler has authorize/abort_unless/FormRequest guard |
| Form Request `authorize()` present on all 36 requests | ✅ |
| Raw `request()->all()` mass-assignment scan | ✅ none |
| Subscription/payment/billing/marketplace code scan | ✅ none |
| Answer-key isolation (student vs teacher resources) | ✅ isolated |
| DB unique/constraint review | ✅ comprehensive |

## 18. Known non-blocking recommendations

Documented, **not implemented** (would expand scope):

1. **Student-role guard on teacher enrollment.** `CourseStudentController::store` accepts
   any `student_id`; adding a student-role check in `EnrollStudentToCourseAction` would
   make role isolation stricter. No data leak today (the teacher owns the course).
2. **`StudentResource` exposes `created_by`** to the student on their own profile —
   low-sensitivity; can be omitted for students if desired.
3. **Analytics label nit.** `BuildTeacherOverviewAction` names a percentage average
   `average_score`; cosmetic.
4. **Lazy finalization** of competitions is read-triggered (no scheduler); intentional and
   correct, but a queue/cron could push proactive notifications later.
5. **No course "archive"** operation (only publish/unpublish). If a course with students
   needs to be retired without deletion, an archive status could be added later.

## 19. Runtime testing limitation

**Runtime tests were NOT executed.** PHP/Composer and the migration/`artisan` toolchain
are unavailable in this sandbox, so `migrate:fresh --seed`, `artisan test`, `route:list`,
and `pint` could not run. **No runtime test pass/fail claim is made.** All verification in
this report is **static** (AST parsing, class-reference resolution, route/controller
resolution, dependency/authorization scans, and manual code review). A PHP-enabled CI
run is strongly recommended before production deployment.

---

## Final statement

**BACKEND FEATURE COMPLETE — STATIC VERIFICATION**

The backend feature scope is **FROZEN**. Future work should be limited to **bug fixing,
runtime verification, security hardening, performance optimization, and deployment
preparation** — **not feature expansion.** No new domain was introduced and no
subscription/payment/billing/marketplace/seller/monetization functionality exists.
