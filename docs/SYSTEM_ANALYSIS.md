# Teacher-System — Deep System Analysis

> Historical baseline static audit of the codebase (backend, frontend, migrations, tests, docs).
> Sections 2–7 and D1–D10 describe the pre-remediation snapshot and are not a current finding
> register; use [`PRODUCTION_READINESS_AUDIT.md`](PRODUCTION_READINESS_AUDIT.md) for present status.
> As of 2026-10-02, PHP and Composer are unavailable locally; npm network access is available.
> GitHub Actions run `36951383243` passed PHP lint and the configured PHPUnit suite on PHP 8.2/8.3,
> plus repository guards and frontend tests/build. Composer audit, MySQL, browser, and operational
> checks remain **NOT TESTED**; use the current audit report for release status.

---

## 1. What the system is

A **teacher-owned LMS platform** ("El Masry" / Atlas Academy) delivered as:

- **Backend:** Laravel 11 REST API under `/api/v1`, Sanctum tokens, Spatie roles/permissions,
  SQLite (default) or MySQL/Postgres.
- **Frontend:** Vue 3 + Vite SPA, PWA (Workbox), vue-router, Pinia, vue-i18n (EN/AR + RTL),
  Tailwind.
- **Roles:** `admin`, `teacher`, `assistant` (student-ops staff), `student`.
- **Domains:** auth & accounts (incl. teacher-owned student provisioning, student codes,
  registration links, access periods/renewals) → courses → units → lessons → videos (protected
  playback) → enrollment & progress & roadmap → exams (question/option authoring, templates,
  frozen attempt snapshots, MCQ + essay grading, grade publication) → exam integrity (anti-cheat
  events, deterministic risk, teacher review) → competitions (lifecycle, leaderboards,
  disqualification) → analytics (admin/teacher/student) → in-app notifications → PWA landing page.

Roughly **705 tracked files**, ~363 PHP classes, 49 migrations, 34 API resources, 44 form
requests, 13 policies, 60+ action classes, ~494 test methods, and a substantial `docs/`
folder with phase reports, security audits, and a deployment guide.

---

## 2. System design assessment

### 2.1 Architecture — largely exemplary for a Laravel API

```
Route (thin) → Middleware (auth/role/throttle) → FormRequest (validation + authorize())
  → Controller (thin) → Policy (ownership) → Action/Service (business rules)
  → Model (constraints/casts) → Resource (API shape) + domain Exceptions → HTTP codes
```

Design decisions that are genuinely good and consistently applied:

| Pattern | Where | Why it matters |
|---|---|---|
| Actions as the unit of business logic | `app/Actions/*` | Testable, reusable, no fat controllers |
| Form Requests own validation **and** `authorize()` | `app/Http/Requests/*` | No un-authorized write endpoint |
| Policies traverse ownership chains | `app/Policies/*` (`isOwnedBy`/`isManagedBy`/`staffOwnerIds`) | Cross-teacher isolation, IDOR defence |
| Canonical JSON envelope + centralized exception→code mapping | `app/Support/ApiResponse`, `bootstrap/app.php` | Predictable contract, no SQL-error leakage (e.g. 409 `ResourceDeletionBlockedException`) |
| **Frozen attempt snapshot** | `BuildAttemptSnapshotAction`, `exam_attempt_questions/options`, migration `000201` drops live FKs | Historical attempts survive teacher edits/deletes — textbook-correct design |
| Frozen `pass_percentage` and frozen integrity settings per attempt | `StartExamAttemptAction`, `CreateAttemptIntegritySettingsAction` | Later exam edits never retroactively change outcomes |
| Single-active-attempt enforced at **DB level** (`active_key` unique) + `lockForUpdate` + unique-violation recovery | `StartExamAttemptAction`, `SubmitExamAttemptAction` | Concurrency-safe against double-clicks and races |
| Server-authoritative time & scoring | windows use `now()`; score/rank/risk only server-derived | Client clocks and payloads can't forge outcomes |
| Deterministic integrity risk model in one config | `config/integrity.php`, `IntegrityRiskConfig` | Traceable, auditable, no scattered magic numbers (mostly — see §4) |
| Privacy-by-design resources | `ExamAttemptResource` never emits `is_correct`; leaderboards use `publicDisplayName()`; registration tokens hashed + encrypted | Answer-key and PII leak prevention |
| Deletion guards instead of silent cascades | `DeleteCourseAction`/`DeleteExamAction` → 409 when referenced by competitions | Referential sanity at the domain level |
| Defence-in-depth throttling | `throttle:login`, `integrity-events`, `video-events`, `student-registration`, global `api` | Abuse resistance on the sensitive endpoints |
| Co-teaching as a scope abstraction | `User::staffOwnerIds()`, `CO_TEACHING_ENABLED` | Single toggle between "one teacher LMS" and "shared staff platform" |
| CI | `.github/workflows/ci.yml` (PHP 8.2 + 8.3, `artisan test`, npm build + vitest) | Regression safety net |

### 2.2 Design weaknesses (structural, not just bugs)

1. **Presentation layer encodes pass/fail policy, and two definitions coexist** (see §4.2).
   Domain outcome ("did this attempt pass?") should be one method on the attempt, not a lambda
   in two resources plus a third variant in analytics.
2. **No jobs / events / listeners.** Publishing an exam notifies every enrolled student
   synchronously inside the request (`PublishExamAttemptAction → notifyEnrolledStudents`).
   Fine for 30 students, a timeout risk for 3,000. Same for competition finalization (lazy,
   on-read) and attempt expiry (lazy, on-touch). Only two scheduled commands exist
   (`students:check-renewals`, `db:backup`).
3. **`multiple_choice` (multi-select) exists in the enum/UI but not in the data model.**
   `ExamAnswer` has a single `option_id` — the schema itself cannot represent a multi-select
   answer (see §4.1). A type was introduced without its storage/grading contract.
4. **Destructive deletes, no soft deletes.** `students/batch-delete` destroys accounts and the
   FKs cascade attempts/progress. Exam snapshots survive question deletes, but student/account
   history does not survive an operator's misclick. No recycle bin, no archive for accounts.
5. **Notification system is in-app only** (database channel). `MAIL_MAILER=log`; nothing sends
   email/SMS/WhatsApp/push. The notification *machinery* is complete; the *delivery* is not.
6. **Proctoring evidence is client-trust-based** by design (browser-observable events), which is
   honest, but heartbeat loss is conflated with cheating (see §4.3) — a fairness flaw in an
   otherwise thoughtful integrity design.
7. **Docs drift across the 8 phase reports.** Examples: `FINAL_PRODUCTION_READINESS_REPORT.md`
   claims the landing `CoursePreview` fetches live courses (it makes zero API calls now, as
   `FINAL_PUBLIC_SECURITY_AUDIT.md` correctly states); README still advertises "public course
   discovery" while `GET /courses` is behind `auth:sanctum` and the SPA route is role-gated;
   `docs/API.md` (Phase-2 era) lacks the essay, integrity, template, registration-link,
   heartbeat/terminate, analytics and admin surfaces.
8. **Template system stores structure, not content** (`ExamTemplate` = counts + marks only),
   so "templates" can't actually reuse questions — adjacent to the missing question-bank
   concept (§6).

---

## 3. Correctness of implementation

### 3.1 Verified-correct core behaviours (high confidence)

- **Attempt lifecycle:** start/resume idempotence, stale-attempt expiry, attempt limits vs
  `max_attempts`, window enforcement (`min(starts_at + duration, ends_at)`) against server
  clock, idempotent submission with double-submit lock, illegal-state rejections — all
  implemented coherently in `StartExamAttemptAction` / `SubmitExamAttemptAction` /
  `SaveExamAnswerAction` / `ExpireExamAttemptAction`.
- **Snapshot integrity:** question/option text, image, type, points, order frozen; live edits
  can't corrupt attempts; answer keys isolated from student-facing resources (verified in
  `ExamAttemptResource`, `StudentExamDetailResource`, `ExamResultResource`).
- **Grading:** MCQ auto-grade against the snapshot, essays enter `grading` status with
  range-checked manual awards (`GradeEssayAnswerAction`), grade publication is idempotent and
  audited (`graded_by`, `grades_published_at`), and scores stay hidden from students until
  publication (`ExamResultResource`, `BuildStudentAnalyticsAction::revealUnpublishedScores`).
- **Competitions:** capacity under row lock, unique participation, deterministic scoring with
  documented tie-breaks (`submitted_at` asc), standard competition ranking, lazy but *atomic*
  finalization, disqualification preserves history and re-ranks, attempt-timing rule
  (`submitted_at < ends_at`) enforced server-side.
- **Integrity:** only client-reportable event types accepted; `MULTIPLE_SUSPICIOUS_EVENTS` is a
  derived condition, never a scorable event; per-attempt frozen gates; dedup window; metadata
  caps; risk/status recomputed from events only.
- **Auth surface:** no open registration (teacher-owned invite links with sha256-hashed tokens,
  encrypted at rest, revocable/rotatable, throttled); login accepts email *or* student code
  (`ELM-#####`); account deactivation/credential reset revokes tokens; password changes revoke
  other tokens.
- **Video protection:** short-lived playback session tokens (`Str::random(48)`, TTL, revocation),
  no storage paths leaked publicly, playback-event reporting scoped + throttled.

### 3.2 Concrete defects found in the baseline snapshot (historical)

The findings below explain the pre-remediation code, not the current disposition. Several were
implemented in §30; current fix/test/retest status is recorded in
[`PRODUCTION_READINESS_AUDIT.md`](PRODUCTION_READINESS_AUDIT.md). The older recommendations in
§7 are likewise historical and must not be used as the current release checklist.

**D1 — Multi-select (`multiple_choice`) questions are broken end-to-end (correctness, high).**
- Authoring allows multiple correct options (`ExamQuestions.vue` renders checkboxes), but
- the answer API takes a single `option_id` (`SubmitExamAnswerRequest`, `SaveExamAnswerAction`
  `updateOrCreate`s one row with one `option_id`), the take-exam UI selects radio-style
  (`ExamTake.vue` `@click="answer(opt.id)"`, circle indicators), so a student physically cannot
  select two options;
- grading credits only the **first** correct option (`firstWhere('is_correct', true)` in
  `GradeExamAttemptAction` and `CalculateExamResultAction`) — picking the second correct option
  scores **0**;
- publish validation `Question::hasValidSingleCorrectOption()` checks `count >= 1` while the
  error message and docblock claim *"exactly one"* — so even a `single_choice` question with two
  answer keys publishes and silently misgrades;
- **zero tests** reference `multiple_choice`.
→ Fix: either implement `answer_option_ids[]` (JSON/child table) + set-equality grading with
partial credit, or remove the `multiple_choice` type until it is real; and make publish
validation enforce `count === 1` for `single_choice`, `count >= 1` (or `>= 2`) for
`multiple_choice`.

**D2 — Two conflicting definitions of "passed" (consistency, high).**
- `ExamResultResource` / `ExamAttemptResource`: `passed = integrity_status !== Flagged && percentage >= pass_percentage`
- `CalculateExamResultAction` / `BuildStudentAnalyticsAction` / `BuildTeacherOverviewAction::passRate`:
  `passed = percentage >= pass_percentage` (integrity ignored).
→ A flagged attempt reads **"Failed"** on the student's result screen but **"Passed"** in the
student's own analytics and in the teacher's pass-rate. Pick one policy (likely "flagged ⇒
pending review, not auto-failed"), implement it once in a domain method, and use it everywhere.

**D3 — Heartbeat loss is recorded as cheating (fairness/design, medium-high).**
`ExpireExamAttemptAction` terminates an attempt when a heartbeat is >60s late (internet drop,
laptop sleep, closed tab) and `terminate_on_violation` defaults to **true**. The termination path
(`TerminateExamAttemptAction`) then:
- records the event as `WINDOW_BLUR` (the `default` arm of the reason match — a mislabel; the
  real reason only lives in `metadata`);
- assigns **hardcoded** `risk_points = 10`, `severity = High` — bypassing `config/integrity.php`
  and contradicting the "no magic numbers" claim;
- auto-grades and (with `show_result_immediately`) immediately publishes the grade and sends
  "result available" for an attempt the student never submitted.
→ A student with a 2-minute network outage is branded `Flagged` with a fabricated `WINDOW_BLUR`
record. Terminate-on-heartbeat-loss should at most *expire* (or freeze) the attempt; any
integrity event should use the config table and an honest event type.

**D4 — Lesson `content` is authored but never delivered to students (broken pipeline, high).**
Teachers write lesson body text (`content` accepted by `CreateLessonRequest`/`UpdateLessonRequest`,
editable in `Teacher/CourseDetail.vue`), but:
- `LessonResource` **omits `content` entirely**, and there is no student lesson endpoint;
- the student `Lesson.vue` page renders only title/description/videos — the body text can never
appear on the learner screen.
→ The richest content type in the data model is write-only. Either expose it (student
`GET /lessons/{id}` + render) or remove the field to avoid teacher confusion.

**D5 — Student lesson page can't show its own title after direct load (small).**
`ProgressController::show` returns `LessonProgressResource` **without** `->load('lesson')`,
while `store` and `index` do load it. `whenLoaded('lesson')` therefore omits the relation on
`GET /student/lessons/{id}/progress`, and the SPA falls back to a generic "Lesson" heading.

**D6 — Essay `feedback` never reaches students (forgotten delivery, medium).**
`GradeEssayAnswerAction` stores `feedback` + `graded_at` per answer, but the only resource that
emits them is the teacher-facing `ExamAttemptDetailResource`. `ExamAttemptResource` /
`ExamResultResource` (student) return `selected_option_id`/`answer_text` only — so a teacher's
essay feedback is written and lost.

**D7 — Rounding at the pass boundary (small, but grading-critical).**
`CalculateExamResultAction` computes `percentage = (int) round(earned/total*100)` and compares
the rounded value to `pass_percentage`. 59.5% rounds to 60 and passes a 60% threshold. Compare
with full precision (or floor) and round only for display.

**D8 — Expired attempts are never graded yet consume `max_attempts` (fairness, medium).**
An expired attempt keeps its recorded answers but is excluded from reporting
(`countsAsAttempt()` = false) *and* counts against the attempt limit in
`StartExamAttemptAction`. A student whose browser crashed mid-exam loses an attempt with no
score and no review. Consider auto-submitting saved answers at `expires_at` (scheduled job) so
the attempt is graded rather than discarded.

**D9 — Hardcoded academic years in the public registration flow (small).**
`StudentRegistrationController::show` returns `['secondary_1','secondary_2','secondary_3']`
literal instead of `AcademicYear::cases()` — will silently drift from the enum.

**D10 — Docs/claims drift (process, medium).** See §2.2-7. Additionally the gap-analysis doc
itself records that a prior session shipped a broken import (`\ProfileController`) and an
unresolvable constructor dependency — evidence that static-only verification missed wiring
errors; CI now covers this, but `docs/API.md` remains stale.

### 3.3 Verification posture (baseline snapshot; superseded by current audit)

- The repository contains broad feature coverage for state machines, concurrency, IDOR matrices,
  privacy invariants, snapshots, transactions, rate limits, and regression cases. Test presence is
  not evidence of a passing current backend suite.
- The former gaps for multi-select, lesson delivery/progress, answer-review feedback, and related
  exam lifecycle behavior received implementation and regression coverage; the configured PHP
  suite passed on PHP 8.2/8.3 in CI run `36951383243`. This does not cover every manual or
  production-engine scenario.
- The 2026-10-01 run `36943046841` and subsequent partial-retest runs were red during remediation.
  The latest application-code run `36951383243` passed the PHP suites and pagination guard; logs
  for detailed test counts were unavailable. See [`PRODUCTION_READINESS_AUDIT.md`](PRODUCTION_READINESS_AUDIT.md).

---

## 4. Strength points (ranked)

1. **Attempt snapshot architecture** — immutability of historical exams done properly
   (frozen content, frozen pass threshold, FK-drop protection, deep-integrity tests).
2. **Security posture** — IDOR-safe policies everywhere, answer-key isolation, hashed/encrypted
   registration tokens, short-lived playback tokens, per-concern throttles, uniform 403s,
   no mass-assignment from raw input, secrets-safe backup command (0600 defaults file for
   mysqldump).
3. **Server-derived everything** — scores, rankings, risk, completion, qualification; clients
   can only report observations, never conclusions.
4. **Concurrency correctness** — DB-level unique guards + `lockForUpdate` + unique-violation
   recovery on attempts, joins, grading, and publication.
5. **Clean layering** — thin controllers, Actions, FormRequests, Policies, Resources; enforced
   by convention and by test (`AuthorizationTest`, `SecurityMatrixTest`, `StaffParityMatrixTest`).
6. **Deterministic, transparent anti-cheat scoring** — one config file, dedup windows, frozen
   per-attempt gates, server-derived aggregates, immutable review trail.
7. **Competition domain discipline** — atomic finalization, disqualification preserves history
   and re-ranks, deterministic ties, privacy-safe identities.
8. **Honest scope control** — README explicitly declares what is *not* built (proctoring
   hardware, AI grading, payments, transcoding) instead of faking it.
9. **Operational basics** — scheduled rotating local database backups with failure signalling,
   daily renewal checks, `/up` health route, and a PWA that never caches API/attempt payloads
   (`NetworkOnly`). Off-host/encrypted backup and restore drills remain open; see the current audit.
10. **Bilingual product surface** — EN/AR i18n with RTL handling, WhatsApp deep-link contact
    helpers, printable credential sheets — real teacher-workflow awareness.

---

## 5. Weak points in the baseline snapshot (historical; see current audit)

1. **Multi-select questions broken** (D1) — a correctness hole in the core grading loop.
2. **Inconsistent pass/fail semantics** (D2) — the same attempt can be "failed" and "passed"
    on two screens.
3. **Lesson content is write-only** (D4); essay feedback is write-only (D6) — features the data
    model and teacher UI promise but the student side never receives.
4. **Heartbeat-loss = auto-flagging** (D3) — unfair outcomes for flaky connections; hardcoded
    risk values leak magic numbers back into the integrity system.
5. **No delivery channels for notifications** (email/SMS/push) — in-app only; students who don't
    log in never learn an exam was published.
6. **Lazy-only lifecycle processing** — no job expires/grades attempts at the deadline, no
    scheduler finalizes competitions or sends "window closing" reminders; everything happens on
    someone's next request.
7. **No soft deletes / recovery** for accounts, enrollments, or content (batch-delete exists!).
8. **Weak account-recovery story** — no forgot-password, no email verification, no 2FA, `min:8`
    the only password policy.
9. **Synchronous notification fan-out** in request cycle (publish exam → N notifies).
10. **Docs drift** between README, `docs/API.md`, and the phase reports (§2.2-7).
11. **Analytics limited** — averages/pass rates exist; no trends over time, distribution,
    question-level analysis (item difficulty/discrimination), or export.
12. **Single-teacher operational assumptions** baked into flows (hardcoded academic years,
    co-teaching as all-or-nothing), while the product README positions a platform.

---

## 6. Forgotten features & functions — important for this system

Grouped by impact. "Missing" = not present at all; "present-but-unwired" = built on one side and
never delivered on the other (the most embarrassing class of gap, because the schema and UIs
already promise it).

### 6.1 Present-but-unwired (fix first — cheap wins)

| # | Feature | Evidence | Impact |
|---|---|---|---|
| 1 | **Lesson text content to students** | `content` writable (`CreateLessonRequest`, teacher form) but `LessonResource` omits it; no student lesson endpoint; `Student/Lesson.vue` never renders it | Teachers author materials students can never read |
| 2 | **Essay feedback to students** | `feedback`/`graded_at` saved by `GradeEssayAnswerAction`, exposed only in teacher `ExamAttemptDetailResource` | Grading effort wasted; students can't learn from mistakes |
| 3 | **Answer review after grading** | Even after `grades_published`, student resources omit per-question correctness/correct option | Students can't review which questions they got wrong |
| 4 | **Multi-select questions** | Type in enum + teacher checkboxes; single `option_id` model/grading (D1) | Misgraded exams |
| 5 | **Lesson title on direct lesson load** | `ProgressController::show` missing `->load('lesson')` (D5) | UI falls back to generic heading |
| 6 | **Academic year list** | Hardcoded in `StudentRegistrationController::show` vs `AcademicYear` enum (D9) | Drift |
| 7 | **API.md coverage** | Stops at Phase 2 while the API has ~200 endpoints | Integrators misled |

### 6.2 Identity, account & security (very important)

- **Self-service password reset (forgot password)** — today only a teacher/admin can reset.
  A locked-out student has no self-recovery path at all. (Email/OTP channel required.)
- **Email verification / valid contact channel** — `MAIL_MAILER=log`; `email_verified_at` exists
  but is unused; no message ever leaves the box.
- **2FA (TOTP) for admin/teacher accounts** — these accounts hold grades and PII.
- **Password policy hardening** — length-only rule; add complexity/breach-list checks, optional
  expiry, and "must change temporary password" enforcement on first login
  (`must_change_password` exists in the model — verify it is actually enforced at login).
- **Session & device management** — list/revoke active Sanctum tokens per account ("log out all
  devices" exists implicitly on reset; no visibility UI).
- **Login/security audit log** — who logged in, from where, failed attempts, admin actions
  (password resets, grade publications, disqualifications) in an append-only trail. The integrity
  review trail proves the pattern exists — it should cover the whole privileged surface.

### 6.3 Assessment & content (core academic value)

- **True/False question type** (and completion of multi-select) — the paper authoring tool is
  otherwise good; missing basic types forces MCQ-only exams.
- **Question bank** — save questions once, reuse across exams/courses; tags, difficulty levels,
  search. (`ExamTemplate` only stores counts+marks — it is a skeleton, not a bank.)
- **Randomized variants** — per-student question sampling from the bank; item shuffling already
  exists, sampling does not.
- **Question import/export** — CSV/Excel/Word/GIFT/QTI; export a paper to PDF/print
  (offline exams are still the reality in this market).
- **Grading toolbox** — partial credit & negative marking, per-option marks, manual score
  override for MCQ with audit, regrade-and-republish flow, curve/adjustment.
- **Proactive exam lifecycle job** — auto-submit saved answers when `expires_at` passes (grace
  submit) instead of lazy expiry that discards work (D8); "time left" already server-authoritative.
- **Exam scheduling calendar** — visible timetable of windows for students/teachers
  (`starts_at/ends_at` exist; no calendar surface).
- **Result exports & report cards** — class result sheets (CSV/PDF), per-student transcripts,
  printable reports; currently only credential sheets are printable.
- **Assignments/homework** — file upload submissions (PDF/photos), deadlines, teacher grading
  with feedback. Very important for a real school workflow; entirely absent.
- **Lesson file attachments** — PDFs, slides, worksheets on a lesson (only videos attach today).

### 6.4 Learning experience

- **Progress gating enforcement** — roadmap computes "locked" states (`BuildCourseRoadmapAction`)
  but lesson access isn't forced in order; decide whether locks are informational or enforced.
- **Discussion / Q&A per lesson** — student questions, teacher answers; the single biggest
  engagement lever for an async LMS.
- **Personal notes & bookmarks** — timestamped notes on videos/lessons.
- **Certificates of completion** — auto-issued PDF on course completion; high motivational value,
  trivial to generate.
- **Student answer review & study mode** — after grades publish, walk through the paper with
  correct answers, feedback, and explanations (`reference_answer` on essays is also never shown
  to anyone — another unwired field worth checking).
- **Search** — across courses, lessons, exams (catalog search is client-side on one page only).

### 6.5 Communication & delivery

- **Real notification delivery** — email at minimum; WhatsApp (the `PhoneNumber`/wa.me helpers
  prove demand), and Web Push for the installed PWA (no FCM/VAPID today).
- **Notification preferences & quiet hours**; **scheduled reminders** — "exam window opens in
  1h / closes in 30m", "grades published", "renewal due" (partially exists),
  "competition ending".
- **Teacher broadcast announcements** to a course or cohort (only 1:1 `students/{id}/notify`
  exists).

### 6.6 Competition & engagement

- **Team competitions**; **badges/awards**; **public shareable (privacy-safe) leaderboard links**
  for marketing; **seasonal rankings** across competitions.
- **Scheduled competition lifecycle job** — proactive finalization + "final results" notification
  instead of lazy-on-read.

### 6.7 Operations, admin & scale

- **Bulk student import (CSV/Excel)** — `batch-delete` exists without its inverse; onboarding
  hundreds of students by hand is the top operator pain.
- **Data export / account deletion (GDPR-ish)** — "export my data", right-to-be-forgotten flows.
- **Soft deletes / archive** for students, courses, exams (recoverability; today one click
  destroys history).
- **Audit dashboard** — admin view of staff actions, integrity outcomes, login anomalies.
- **Metrics & error tracking** — app metrics (attempt failure rates, heartbeat timeouts,
  queue depth), external error tracking, uptime monitoring beyond `/up`.
- **Caching layer (Redis)** for leaderboards/analytics at cohort scale; **queue workers** for
  notification fan-out, exports, and lifecycle jobs (already configured `QUEUE_CONNECTION=database`
  but nothing is queued).
- **OpenAPI spec generated from code** (replace hand-maintained API.md), Postman collection.
- **Localization of API messages** — backend messages are English-only while the UI is AR/EN.
- **Integrations** — Google Classroom/LTI, Zoom/Meet live sessions, webhooks.
- **Video pipeline** (declared out of scope, but strategically important): transcoding/HLS,
  CDN signing, adaptive bitrate — the current player points at external providers.

### 6.8 Explicitly deferred by the README (keep, but plan)

Webcam/mic/screen proctoring, AI cheating classification, subscriptions & payments, advanced
analytics, AI features, video streaming/transcoding. If this product goes commercial, the
payment/subscription layer and email infrastructure become prerequisites, not extras.

---

## 7. Priority recommendations from the baseline snapshot (historical; superseded)

The P0–P3 list below predates the remediation documented in §30. Do not treat it as the current
release plan; use [`PRODUCTION_READINESS_AUDIT.md`](PRODUCTION_READINESS_AUDIT.md) and
[`PRODUCTION_READINESS_TEST_PLAN.md`](PRODUCTION_READINESS_TEST_PLAN.md) instead.

**P0 — correctness (days):**
1. Fix or remove `multiple_choice` (D1) + tighten publish validation (`count === 1` for single).
2. Unify "passed" semantics in one domain method (D2).
3. Expose lesson `content` and essay `feedback` to students (D4, D6); load `lesson` in
   `ProgressController::show` (D5).
4. Stop treating heartbeat loss as cheating: expire/freeze instead of terminate+flag; move
   termination risk points into `config/integrity.php` with honest event types (D3).
5. Grade against full-precision percentages (D7).

**P1 — trust & fairness (1–2 weeks):** forgot-password + email delivery, auto-submit-at-deadline
job (D8), soft deletes, staff audit log, result exports (CSV/PDF).

**P2 — product completeness (1–2 months):** question bank + import/export, True/False + real
multi-select with partial credit, assignments, lesson attachments, certificates, reminders +
web push, bulk student import, answer review for students.

**P3 — scale & platform (ongoing):** queues for fan-out, Redis caching, OpenAPI pipeline,
metrics/error tracking, integrations, video pipeline.

---

## 30. FINAL IMPLEMENTATION REPORT — Production Exam Hardening + LMS UX/Operability

Scope executed on branch `arena/01a0efd9-teacher-system` across commits `d469062` (P0),
`911ee9d` (P1), `1f98880` (P2 backend) and the final frontend/report commit. Every claim
below is verifiable in code and tests; anything not fully delivered is marked
**PARTIAL** or **DEFERRED** — no false completeness claims.

### A. Bugs fixed

| # | Bug / defect | Fix | Regression test |
|---|---|---|---|
| A1 | `multiple_choice` grading compared raw arrays (order/dupe-sensitive) and publisher accepted invalid option sets | Normalized child schema `exam_answer_options`, set-comparison grading with exact-set matching first (no silent partial credit), publish validation: `single_choice` exactly 1 correct, `multiple_choice` ≥ 2 | `MultipleChoiceGradingTest` (13 cases incl. legacy single-choice compat + snapshot immutability) |
| A2 | Pass/fail duplicated across controllers/resources (and used rounded percentages) | Single source of truth `ExamAttempt::outcome()` + `AttemptOutcome`; values PASSED/FAILED/PENDING_REVIEW/DISQUALIFIED/EXPIRED; raw float comparison of `raw_percentage` vs `pass_percentage` | `AttemptOutcomeTest` (11 cases) incl. boundaries 59.49/59.5/59.99/60/60.01 |
| A3 | Lesson `content` never delivered to students; lesson title missing on progress endpoint | `StudentLessonController` returns `content`; progress response carries `lesson.title`; frontend falls back `lesson?.title || progress?.lesson?.title` | `StudentLessonContentTest` (6 cases) |
| A4 | Essay feedback visible before grade publication | `showForStudent` masks essay feedback/ai flags until `grades_published_at` | `StudentAnswerReviewTest` (5 cases) |
| A5 | Termination fabricated integrity events (e.g. `WINDOW_BLUR` for heartbeat timeout) and hardcoded risk points | `RecordIntegrityEventAction` is the only event writer; heartbeat/network loss never recorded as events; all risk scoring via `config/integrity.php` | `InterruptionFairnessTest` (10 cases) |
| A6 | Warning threshold hardcoded and not configurable | `violation_warning_threshold` frozen per attempt at start (from exam setting, default 5, config/DB-driven); terminate only when `warning_count > threshold` | `InterruptionFairnessTest`, `ExamHardeningRegressionTest` |
| A7 | Client recovery lost state after reload; expired attempts unrecoverable | Stateful recovery (reload-safe exam state in `useExamIntegrity`/`ExamTake.vue`); deterministic `end_reason` taxonomy + recovery copy | `AutoSubmitAtDeadlineTest`, `ExamHardeningRegressionTest` |
| A8 | Late submissions overwrote expired attempts / essays lost at deadline | Auto-submit at `expires_at` (idempotent, essays preserved, `end_reason='auto_submit_at_deadline'`); `expire` mode keeps legacy `end_reason='expired'` and 422 on late submit | `AutoSubmitAtDeadlineTest` (8 cases incl. idempotency) |
| A9 | `IssueCertificateAction` referenced nonexistent `lessons.course_id` | Eligibility via `$course->lessons()` (HasManyThrough); unpublished lessons excluded | `CertificateTest` |
| A10 | `User.notification_preferences` not fillable/cast — JSON corrupted on save | Added to `$fillable` with `'array'` cast | `ReminderDispatchTest` |
| A11 | Reminder dedupe unreliable with faked notifications; wrong `DatabaseNotification` FQCN in tests | Dedeupe implemented against real `notifications.data` rows; tests use `Illuminate\Notifications\DatabaseNotification` | `ReminderDispatchTest` (idempotency case) |
| A12 | Lesson title fallback bug (P0 A3) frontend half | `Student/Lesson.vue` resolves `{lesson, attachments}` wrapper and bare shapes | static + `StudentLessonContentTest` |

### B. Changed files (by layer)

- **Migrations:** Most `2026_09_30_000001..000010` changes add tables/columns/indexes (answer options, outcomes, integrity settings, soft deletes, audit, assignments, attachments, certificates, notification preferences). Later `2026_10_02_000001_restrict_exam_attempt_student_deletion` changes the existing student FK from cascade to restrict; it is non-additive at the constraint-policy level and has not been run in this environment.
- **Models/Enums**: `ExamAnswerOption`, `Assignment`, `AssignmentSubmission`, `LessonAttachment`, `Certificate`, `AuditLog`; `AttemptOutcome`, `IntegrityEventType` (+`ThresholdTermination`), `IntegrityRiskConfig`.
- **Domain**: `ExamAttempt::outcome()`, `Exam::autoSubmitsAtDeadline()/answerReviewEnabled()`, `Actions/Exam/*` (grading with set comparison, `FinalizeExpiredAttemptAction`), `Actions/Integrity/RecordIntegrityEventAction`, `Actions/Audit/RecordAuditLogAction`, `Actions/Certificate/IssueCertificateAction`, `Actions/Course/RestoreCourseAction`, `Services/NotificationPreferences`, `Console/Commands/{ProcessExpiredAttemptsCommand,DispatchRemindersCommand}`.
- **HTTP**: controllers (student lesson content/answer review, teacher grouped attempts/exports/bulk import/assignments/attachments, student assignments/certificates/preferences, public certificate verification), form requests, API resources (`outcome` in `ExamAttemptResource`; `AssignmentResource`, `CertificateResource`, `AuditLogResource`).
- **Frontend**: `api/client.js` (+`downloadFile`), `api/index.js` (attemptsGrouped/export/import/assignments/attachments/certificates/preferences), `views/Teacher/ExamDetail.vue` (grouped-by-student attempts + export toolbar), `views/Teacher/Students.vue` (import preview→confirm→report dialog), `views/Teacher/CourseDetail.vue` (lesson attachments), `views/Student/Assignments.vue` (new), `views/Student/Certificates.vue` (new), `views/Student/Lesson.vue` (attachments + content/title), `views/Student/ExamTake.vue` + `composables/useExamIntegrity.js` (recovery, warn-first), `layouts/StudentLayout.vue`, `router/index.js`, i18n `en.js`/`ar.js`.
- **Tests**: 8 new suites, 7 legacy suites pinned/extended (see G).

### C. Data-safety analysis per migration

Most migrations summarized here add tables, columns, or indexes without rewriting attempt history.
This is not true for every later migration: `2026_10_02_000001_restrict_exam_attempt_student_deletion`
drops and recreates the existing FK to change `ON DELETE CASCADE` to `RESTRICT` (no row rewrite, but
a behavior-changing constraint migration). It and its rollback require validation on supported DBs.

1. `exam_answer_options` — new table; backfill from legacy `correct_options`/JSON column
   is performed idempotently (per-question guard on existing rows); rollback = drop table
   only after verifying no live writes (documented in migration header).
2. Outcome columns — nullable adds; `raw_percentage` backfilled lazily on read
   (legacy NULL ⇒ rounded compare inside `outcome()`), never rewriting history.
3. `violation_warning_threshold` — nullable add; NULL ⇒ `config('integrity.warning_threshold')`.
4. `expiry_mode` / `allow_answer_review` — nullable/defaults; historical exams keep
   legacy behaviour (`expiry_mode` NULL ⇒ `expire` semantics).
5. Soft deletes — nullable `deleted_at` adds; zero effect on attempts/answers/grades rows.
6. `audit_logs` — append-only table; no source rows touched.
7. `assignments` / `assignment_submissions` — new tables with `UNIQUE(assignment_id, student_id)`
   (idempotent submission upsert); deletion of an assignment is soft and never cascades to submissions.
8. `lesson_attachments` / `certificates` — new tables; `UNIQUE(student_id, course_id)` +
   `UNIQUE(code)` guarantee idempotent issuance (same code returned on repeat).
9. `users.notification_preferences` — nullable JSON; missing keys ⇒ defaults (all ON).

Rollback policy: for the additive migrations, roll back only in documented dependency order. The
student-attempt FK migration is an exception: its `down()` restores cascade deletion. Do not roll it
back casually; validate the target schema and policy first. Migration execution/rollback remains
**NOT TESTED** in the current sandbox.

### D. Exam lifecycle

`start → active → (warn) → submit | auto_submit | terminate(integrity_threshold) | expire → grading → grades_published → outcome()`.
- Auto-submit at `expires_at` runs in the request path **and** via `attempts:process-expired`
  scheduled everyMinute; both are idempotent (first writer wins, repeat calls return the same state).
- Essay answers are preserved verbatim through every terminal transition.
- `end_reason` is deterministic: `submitted_by_student`, `auto_submit_at_deadline`, `expired`,
  `integrity_threshold`.
- Recovery: reloading the exam page restores saved answers and remaining time from
  server state (`GET student/attempts/{id}`), never trusting the client clock.

### E. Integrity model (fair by construction)

- Event types and risk points live only in `config/integrity.php` (`IntegrityRiskConfig`).
- **Warn-first**: warnings are recorded; termination requires `warning_count > threshold`
  (threshold frozen per attempt, default 5, configurable per exam in the DB) AND
  `terminate_on_violation`; below-threshold attempts are never terminated.
- **Never fabricate**: heartbeat loss, network loss, and timeouts are *not* integrity
  events. A page-blur is recorded only when the browser reports an actual visibility change.
- Flagged ⇒ `PendingReview`/`Disqualified` only after teacher review; all verdicts via `outcome()`.

### F. Attempt-management UX (teacher flow: find → overview → search → open → review → grade → publish → export)

- `GET teacher/exams/{exam}/attempts/grouped?search=` returns students with
  best/latest/pending-grading/integrity rollups + expandable attempts + summary.
- UI: ExamDetail → "By student" view with expandable rows, server-side search
  (name/code/email/phone), and export buttons (CSV + print/PDF view), all
  server-authorization-scoped.
- Bulk student import: paste CSV → server preview (valid/duplicate/invalid rows) →
  confirm → report + one-time credentials. ≤1000 rows, no duplicates created.
- Audit log (`admin/audit-logs`) records every mutating staff action (verbs listed in §7/§13 contract).

### G. Tests

New: `AttemptOutcomeTest` (11), `MultipleChoiceGradingTest` (13), `StudentAnswerReviewTest` (5),
`StudentLessonContentTest` (6), `ExamHardeningRegressionTest` (4), `InterruptionFairnessTest` (10),
`AutoSubmitAtDeadlineTest` (8), `AuditLogTest`, `SoftDeleteRecoveryTest`, `AttemptSearchGroupingTest`,
`ResultExportTest` (+XLSX/PDF cases), `WebPushTest` (subscription IDOR, idempotent
subscribe, quiet-hour suppression, aes128gcm framing + VAPID header, dead-endpoint
pruning), `BulkStudentImportTest`, `AssignmentLifecycleTest` (8), `LessonAttachmentTest` (4),
`CertificateTest` (6), `ReminderDispatchTest` (5). Legacy suites pinned (no tests deleted;
behaviour changes intentional and documented in the suites).

Matrix: correctness (grading/outcome/boundaries) · interruption (warn-first, no fabrication,
threshold freeze) · data safety (soft delete/restore, no cascade) · authorization
(student-B cannot read attempt of student-A; teacher-A cannot grade teacher-B's course;
student IDOR on assignments/certificates) · grouping/search · auto-submit idempotency ·
export scoping · import validation/dedup · certificate idempotency/verification disclosure.

**Verification status (2026-10-02):** PHP/Composer are unavailable locally. GitHub Actions run
`36951383243` passed PHP lint and the configured PHPUnit suite on PHP 8.2/8.3; this suite uses
SQLite. `composer validate`/`audit` and PHP static analysis remain **NOT TESTED**. Frontend
dependencies were installed with `npm ci`; `npm test` passed (27 tests), `npm run build` passed,
and both full and production-only `npm audit` reported zero vulnerabilities. The scoped `glob`
override was verified in CI. Manual browser, MySQL locking/restore, and isolated load/chaos checks
remain **NOT TESTED**.

### H. Implementation summary and remaining gaps (code status, not test certification)

The “FIXED” labels below mean implementation/tests are present in the worktree; PHP runtime
retesting was not possible. For current finding-by-finding status, see
[`PRODUCTION_READINESS_AUDIT.md`](PRODUCTION_READINESS_AUDIT.md).

**FIXED; included regression tests passed in the current PHP 8.2/8.3 CI suite** — all P0 items (A1–A8), P1 grouped attempt management/search/export/import/audit/soft-delete. Test-plan scenarios outside that suite remain unverified.
**FIXED** — P2 backend: assignments, lesson attachments, certificates, scheduled reminders, notification preferences (API + tests).
**FIXED (UI)** — student assignments (submit/resubmit/feedback), student certificates (claim/verify), lesson attachments download, teacher grouped attempts + export + import dialog + lesson attachment upload/delete.
**FIXED** — Teacher assignment administration: CourseDetail → Assignments tab with create/edit modal (title, description, due date, points, publish flag), publish/unpublish, recoverable delete (ConfirmDialog copy states submissions/grades are kept), submissions review modal (student, late badge, status, score, file download), grade modal (score + feedback). Uses the existing assignment endpoints/policies; i18n EN/AR complete.
**FIXED** — Native exports: `format=xlsx` emits a real Office Open XML workbook via the dependency-free `XlsxWriter` (pure-PHP stored ZIP + SpreadsheetML, full Unicode — Arabic preserved); `format=pdf` emits a real `application/pdf` via the dependency-free `SimplePdfWriter`, which embeds `resources/fonts/DejaVuSans.ttf` (DejaVu license in `resources/fonts/LICENSE-DejaVu.txt`) as a CIDFontType2/Identity-H font and shapes Arabic in-process (`ArabicText`: contextual presentation forms incl. lam-alef ligatures + RTL run re-ordering). CSV and the print-HTML sheet remain for compatibility. Tests assert PDF magic + `/FontFile2` + Arabic-name generation and XLSX ZIP structure + row content.
**FIXED** — Web Push: RFC 8030 delivery with RFC 8292 VAPID (ES256 JWT) and RFC 8291 aes128gcm payload encryption implemented on core PHP openssl primitives (`Services/Push/WebPushSender`) — no composer packages. `push_subscriptions` table (additive), `push:vapid-keys` command, `GET/POST/DELETE push-subscriptions` (idempotent for the owning account, IDOR-safe), HTTPS/provider-host allowlisting with outbound redirects disabled, and origin-confined notification-click URLs. PWA service-worker `push`/`notificationclick` handlers (`public/push-sw.js`, imported by `sw.js` and pinned via `importScripts` in vite.config for rebuilds), opt-in card in Notifications with graceful fallback. Reminder dispatch sends push as an extra channel under the identical preference/quiet-hour gating; the database notification stays the durable record; dead endpoints (404/410) are pruned. When `VAPID_*` env keys are absent the channel disables itself (fallback contract, covered by tests). SSRF regression cases passed in the backend PHPUnit suite in CI run `36951383243`; provider egress/DNS behavior and deployment restrictions remain **NOT TESTED**.
**DEFERRED** — assignment file preview in browser (download exists); competition-group leaderboard pagination (data model ready).

### Production deployment safety

1. **Migrations**: most feature migrations are additive; `2026_10_02_000001` changes the
   attempt/student FK delete policy from cascade to restrict. Run migrations only after validating
   the target schema and supported DB engine. The FK migration and its cascade-restoring rollback
   are exercised through SQLite-backed tests in CI; MySQL-specific upgrade/rollback behavior remains **NOT TESTED**. See the current production-readiness audit.
2. **Scheduler (required)**: `routes/console.php` schedules `attempts:process-expired`
   everyMinute (auto-submit/expire sweep) and `reminders:dispatch` hourlyAt(7). Deploy must
   run `php artisan schedule:work` (or cron `schedule:run`).
3. **Storage**: assignment submissions and lesson attachments are written to the **private**
   `local` disk; serve them only through the authorized download endpoints. Set
   `FILESYSTEM_DISK` accordingly; never expose the directory publicly.
4. **Config**: `config/integrity.php` values (risk points, `warning_threshold`) are
   deploy-configurable; DB per-exam settings override defaults per attempt.
5. **Backward compatibility**: all new API routes are additive; response resources keep
   legacy fields and add `outcome`/`passed` without renaming anything.
6. **Zero-downtime order**: migrate → deploy code → ensure scheduler running. Old code
   ignores the new columns; new code handles legacy NULLs (raw percentage, expiry mode).
7. **Web Push**: run `php artisan push:vapid-keys` and set `VAPID_PUBLIC_KEY`,
   `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` in `.env`. Without them, push silently
   stays off and in-app notifications continue — nothing breaks.
8. **PDF font**: `resources/fonts/DejaVuSans.ttf` ships in the repository
   (with its license) so PDF export needs no runtime font installation.

---

*End of analysis. All defect claims (§3.2) are traceable to the cited files and were confirmed
by direct code reading on branch `arena/01a0efd9-teacher-system`.*
