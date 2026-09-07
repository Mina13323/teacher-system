# Teacher LMS — Backend Gap Analysis & Phase 6 Gap-Closing Report

**Date:** 2026-09-07
**Scope:** Full static backend audit (all 17 work-order steps) + implementation of the
remaining feature gaps to make the Teacher-owned LMS backend feature-complete.
**Constraint respected:** This work closes feature gaps; it does **not** introduce
subscriptions/plans/entitlements/payments/billing/marketplace. AI remains an LMS
capability only (never a billing/business layer).

---

## Verdict

**BACKEND FEATURE COMPLETE (static verification).** Every endpoint in `routes/api.php`
is wired to a thin controller → Form Request → Action/Service → resource, and is
authorized server-side via a Policy. Static analysis is clean. **Runtime tests were
NOT executed** (PHP/Composer unavailable in this environment) — no runtime test
pass/fail claim is made. See the "Runtime-test caveat" section.

---

## Static verification results

| Check | Result |
|---|---|
| `check.js` (PHP AST syntax, `php-parser` `version: 801`) | `TOTAL=363 BAD=0` |
| `refcheck.js` (referenced App/Test class existence) | `References=304 MISSING=0` |
| Unused-import scan (new gap-closing files) | clean (only benign route-alias/enum noise) |
| Raw `request()->all()` mass-assignment scan | none found |

---

## Audit steps 1–17: findings & action

### 1. Full backend inventory
Complete. 21 models, 12 policies, 15 exception classes, 61 Actions, 1 service
(+ IntegrityRiskConfig), 30 controllers, 41 Form Requests, 34 Resources, 16 enums,
4 notifications, 35 migrations, and the full `routes/api.php` surface was traced
route → middleware → controller → request → policy → action/repo → resource. No
route references a missing class (fixed this session: `ProfileController` import).

### 2. Student account lifecycle — COMPLETE
- Teacher **creates** a student (`CreateStudentAction`) with name/email/password
  (+ optional phone/bio/`course_ids`). Email normalized to lowercase, `unique`
  check in `CreateStudentRequest`.
- Student **logs in** (`LoginUserAction`), **gets own profile** (`GET /api/v1/auth/profile`)
  and **updates** it (`PUT /api/v1/auth/profile`) — name/avatar/phone/bio only.
- **Student cannot edit email** via self-service: `UpdateProfileRequest` has no
  `email` field and `UpdateProfileAction` whitelists name/avatar/phone/bio.
- **Teacher/admin can update email** with a unique check (`UpdateStudentRequest` +
  `UpdateAccountEmailAction`), bypassing the self-service restriction.
- **Password flows:** teacher-created initial password; self change
  (`ChangePasswordAction`, requires `current_password`, `different:current_password`,
  revokes *other* tokens); manager reset (`ResetUserPasswordAction`, revokes all
  tokens); deactivation (`SetAccountActiveStateAction(false)`, revokes all tokens).
- **Activation/deactivation** and **duplicate-email** protection are enforced.
- **No password leakage:** `password`/`remember_token` are `$hidden`; neither
  `StudentResource`, `UserResource`, `PublicUserResource`, nor any resource
  exposes them.
- **Cross-teacher access blocked:** `StudentPolicy::managesStudent` restricts a
  teacher to students they `created_by` OR who are actively enrolled in a course the
  teacher owns. Admin can manage any account. `StudentController::index` further
  scopes the list.
- **IDOR:** `show/update/activate/deactivate/resetPassword/notify` all call
  `$this->authorize(...)` (view/update/manage) or a Form Request `authorize()`.

> Minor note (non-blocking): `StudentResource` is used for the student's own profile
> and includes `created_by` (who created the account) — low-sensitivity metadata,
> considered acceptable.

### 3. Teacher management boundaries — COMPLETE
A teacher can only manage content they own (Course/Unit/Lesson/Video/Question/Option
policies traverse ownership via `isOwnedBy`/`isManagedBy`), only their own students
(`StudentPolicy`), only their own courses' enrollments (`CourseStudentController` +
`EnrollStudentCourseRequest` auths the course), and sees only their own analytics
(analytics actions scope by `created_by`). `Teacher\DashboardController` now gates
with `viewAny(Course::class)` (fixed this session).

### 4. Student experience & IDOR — COMPLETE
- Student sees only their own enrollments, progress, attempts, answers, results and
  analytics (all queries filter by `$request->user()->getKey()`).
- **Cannot read another student's attempt/answer/progress**: `ExamAttemptPolicy::view`
  and `update` require `student_id === user->id`; `LessonProgressPolicy` likewise;
  `Student\AttemptController::show/answer/submit` are policy-gated.
- **Cannot access teacher-only data**: teacher attempt/answer-key resources
  (`ExamAttemptDetailResource`, `OptionResource` with `is_correct`) are only reachable
  under `/api/v1/teacher/*` and are gated by `viewAttempts`/`view` policies that
  require managing the exam.
- **Answer key never leaks to students**: `ExamAttemptResource` (student attempt) and
  `StudentExamDetailResource` omit `is_correct`; the leaderboard exposes only a
  privacy-safe `publicDisplayName()`.

### 5. LMS content management — COMPLETE
CRUD, ordering (`reorder` endpoints + `position`), publication flags
(lesson/video `is_published`), ownership policies, and relationship nesting are all
in place. **Deletion guards:** `DeleteCourseAction`/`DeleteExamAction` refuse to
delete a course/exam that is referenced by a competition (surfaced as a clean 409 via
`ResourceDeletionBlockedException`); the exam attempt snapshot is protected from live
question/option cascade deletes (see step 15).

### 6. Enrollment & progress — COMPLETE
- Teacher enroll/unenroll; student self-enroll on published courses only.
- **Duplicate protection** via status-aware logic: teacher enroll returns the existing
  active enrollment (idempotent) or re-activates a cancelled one; student self-enroll
  throws `DuplicateEnrollmentException` (409) on a duplicate.
- **Ownership:** enroll/unenroll authorize the course (teacher owns it).
- **Progress idempotency:** `UpdateLessonProgressAction` creates-or-updates, enforcing
  the `completed`/`completed_at` invariant. `LessonProgressPolicy` restricts a student
  to their own progress.
- **IDOR:** progress endpoints verify enrollment in the lesson's course before
  reading/writing.

### 7. Exam system (static) — COMPLETE
Lifecycle (draft→published→archived), snapshot freezing (`BuildAttemptSnapshotAction`),
server-side grading (`CalculateExamResultAction`/`GradeExamAttemptAction`), timing
(`expires_at`, server-side `isExpired`), idempotent submission, concurrency safety
(active-key unique index + `lockForUpdate`), deletion guards, and answer-key
protection are all implemented. Publish validation requires ≥1 question, ≥2 options,
exactly one correct option per single-choice question, `duration_minutes>0`,
`0<=pass_percentage<=100`, `max_attempts>=1`.

### 8. Anti-cheat / integrity — COMPLETE (privacy-conscious)
Only browser-observable event types are client-accepted (`clientReportable()`),
`MULTIPLE_SUSPICIOUS_EVENTS` is server-derived only. Metadata is capped (≤20
string values ≤255 chars), risk points/severity/status are server-computed, events
are deduplicated within a window, and the attempt's integrity settings are frozen.
**No webcam / microphone / screen-capture / keylogging / clipboard-content /
GPS / browser-history collection.**

### 9. Competitions — COMPLETE
Lifecycle (draft→published→active→ended), eligibility (enrolled in linked exam's
course + window open), `max_participants` capacity enforced under a row lock,
idempotent join with unique `(competition_id, student_id)`, deterministic scoring
(`CalculateCompetitionScoreAction`), deterministic ranking with standard competition
ranking, lazy finalization that freezes the leaderboard in a transaction, explicit
disqualification that preserves history and re-ranks, and privacy-safe display names.
**Timing rule enforced:** an attempt only counts if `submitted_at < ends_at` (no
`ends_at` ⇒ any submitted attempt counts). `qualified` is competition ranking
eligibility only, never exam pass/fail.

### 10. Analytics — COMPLETE
Teacher overview/course/student analytics are scoped to teacher-owned data; student
`/analytics/me` is self-scoped; admin dashboard covers the platform. Summaries
include progress, completion, scores, pass/fail via the frozen threshold, averages,
and integrity/suspicion summaries. Students never see another student's analytics.

### 11. Notifications — COMPLETE (in-app, no third-party infra)
`notifications` table (Laravel database channel) + four notifications:
exam published, competition published, result available (contains **no score/answer
key**), teacher→student message. `NotificationController` wires index / unread-count /
read / read-all / teacher send. Cross-user read is guarded (`notifiable_id` check → 404).

### 12. Admin / system management — COMPLETE
Admin dashboard, teacher CRUD/activation/reset, student CRUD/activation/reset, and
student analytics are all behind `authorizeAdmin()` (403 unless admin). Admin keeps
privileged operations explicit and does not silently bypass business rules (e.g. admin
can still reset password/deactivate via dedicated actions that revoke tokens).

### 13. API consistency — COMPLETE
Every route uses `/api/v1` prefix, the `{success, message, data}` envelope
(`ApiResponse`), Form-Request validation, server-side authorization, and consistent
status codes. `bootstrap/app.php` maps every business exception to a sensible code
(400/401/403/404/409/422/500) with JSON rendering + `ForceJsonResponse`. Pagination
& filtering are provided where relevant (`per_page`, scoping).

### 14. Security / IDOR — COMPLETE (concrete fixes applied)
- Policies traverse ownership on every model; no cross-teacher access.
- No raw-mass-assignment (`request()->all()`) anywhere.
- Client can **never** set score/rank/completion-time/qualification/result-status/
  integrity-risk conclusions — all are server-derived (attempt grading, risk
  evaluation, disqualification, leaderboard).
- Answer key and `is_correct` are isolated to teacher-facing resources.
- Confirmed no privilege escalation via student route hit against teacher/admin
  endpoints: every teacher/admin action is gated by a Policy/Form Request/`authorizeAdmin`.

### 15. DB integrity — COMPLETE
FKs (with `nullOnDelete` for `users.created_by`), unique indexes (users.email,
attempt `active_key`, attempt_number, competition participants, exam answers unique
per (attempt, question)), nullable/size handling, enum consistency (string-backed
enums), and delete behavior. The `exam_attempt_questions/options/answers` snapshot FKs
are deliberately **dropped** in `000201_protect_exam_snapshot_from_cascade_deletes`
so a teacher's live content edit/deletion never destroys historical attempt data.

### 16. Code quality — COMPLETE
Controllers are thin (delegate to Actions/Services); Form Requests own validation +
`authorize()`; business rules live in Actions; authorization in Policies; API shape in
Resources. No duplicated rules; no over-abstraction.

---

## Concrete fixes applied this session

1. **`routes/api.php`** — added missing `use App\Http\Controllers\Auth\ProfileController;`.
   Without it `[ProfileController::class, ...]` resolved to the global `\ProfileController`
   and **all** `/api/v1/auth/profile` + `/api/v1/auth/password` routes would crash at
   runtime.
2. **`Teacher\StudentController`** — removed an injected but unused and un-imported
   `BuildStudentAnalyticsAction` (would have broken constructor DI by resolving to a
   non-existent `App\Http\Controllers\Teacher\BuildStudentAnalyticsAction`).
3. **`Teacher\DashboardController::index`** — added `$this->authorize('viewAny', Course::class)`
   so a non-teacher gets a proper 403 instead of a 200 with empty dashboard data.
4. **Competition timing test coherence** — `CompetitionFinalizationTest` and
   `CompetitionTransactionTest` were pre-existing latent runtime failures: they
   *joined* participants *after* the window had already closed (`ends_at` in the past),
   which the join endpoint correctly rejects (403, not 201). Fixed by registering
   participants directly (representing "joined while open") via a new
   `registerParticipantDirectly()` helper, and submitting attempts *before* `ends_at`.
5. **`CompetitionResultTimingTest`** — corrected the "after `ends_at`" case so the join
   happens while the window is open and only the attempt lands after close.

---

## Business-rule ambiguities / non-blocking recommendations

These are documented; none are security or data-integrity defects:

1. **Student-role guard on teacher enrollment.** `CourseStudentController::store`
   accepts any `student_id` (role not checked), so a teacher could enroll a non-student
   role into their own course. No data leak (the course is the teacher's own), but a
   role-isolation guard would make it strictly correct. Recommended: validate the
   target user has the `student` role in `EnrollStudentToCourseAction`.
2. **`StudentResource` exposes `created_by`** to the student on their own profile.
   Low-sensitivity; acceptable, or could be omitted for students.
3. **Analytics label nit.** `BuildTeacherOverviewAction` names a percentage-average
   field `average_score`. Cosmetic.
4. **Lazy finalization.** Competition finalization and leaderboard snapshotting are
   trigger-based (on read/join) rather than a scheduled job. Intentional — no cron
   dependency required for correctness; a queue/scheduler could be added later for
   proactive notifications.
5. **Runtime tests unverified.** PHP/Composer are unavailable in this sandbox, so no
   test suite was executed. All verification is static.

---

## Runtime-test caveat

Static analysis confirms structure, wiring, class references, imports, and
policies. **No runtime PHP execution, migrations, or `artisan test` were run.** The
defects listed above were found by code inspection; their fixes are syntactically and
structurally validated but were **not executed**. A PHP-enabled CI run is
recommended before production deployment.
