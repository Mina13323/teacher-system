# Implementation Map — Production Exam Hardening + LMS UX/Operability Phase

Short map of implementation surfaces. Status markers describe code coverage, not production clearance.
Most phase schema changes are additive and attempt history is not rewritten by the listed outcome
migrations. The later `2026_10_02_000001_restrict_exam_attempt_student_deletion` migration changes
an existing FK policy from cascade to restrict; it requires backend/DB validation. See
[`PRODUCTION_READINESS_AUDIT.md`](PRODUCTION_READINESS_AUDIT.md) for current finding and test status.

## P0 — Assessment correctness

### 1. Multiple-choice (multi-select) end-to-end
- DB: `database/migrations/2026_09_30_000001_create_exam_answer_options_table.php` (new child
  table of `exam_answers` — normalized selected-option set; no FK to `options` by design,
  matching the snapshot FK policy).
- Backend: `app/Models/ExamAnswerOption.php` (new), `app/Models/ExamAnswer.php` (+relation),
  `app/Actions/Exam/SaveExamAnswerAction.php` (multi-select sync + legacy mirror),
  `app/Actions/Exam/GradeExamAttemptAction.php`, `app/Actions/Exam/CalculateExamResultAction.php`
  (set-based grading; legacy single `option_id` fallback), `app/Actions/Exam/PublishExamAction.php`
  (single ⇒ exactly 1 correct; multiple ⇒ ≥ 2 correct), `app/Models/Question.php`,
  `app/Http/Requests/SubmitExamAnswerRequest.php` (+`option_ids`), resources
  (`ExamAttemptResource`, `ExamAttemptDetailResource` +`selected_option_ids`).
- Frontend: `resources/js/views/Student/ExamTake.vue` (true multi-select UI + `option_ids`),
  `resources/js/api/index.js` (unchanged payload shape, extended).
- Tests: `tests/Feature/Exam/MultipleChoiceGradingTest.php`, publish-validation cases.

### 2. Unified pass/fail outcome (single source of truth)
- Backend: `app/Enums/AttemptOutcome.php` (new), `app/Models/ExamAttempt.php`
  (`outcome()`, `isPassed()`), resources replace inline lambdas
  (`ExamResultResource`, `ExamAttemptResource`, `ExamAttemptDetailResource`),
  analytics (`BuildStudentAnalyticsAction`, `BuildTeacherOverviewAction`, `BuildCourseAnalyticsAction`).
- Semantics (documented on the enum): `EXPIRED` / `DISQUALIFIED` (flagged + teacher-confirmed) /
  `PENDING_REVIEW` (unpublished, or flagged awaiting review) / `PASSED` / `FAILED`.
- Tests: `tests/Feature/Exam/AttemptOutcomeTest.php`.

### 3. Lesson content delivery (+ direct lesson load fix)
- Backend: `app/Http/Resources/LessonResource.php` (+`content`), `ProgressController::show`
  (`->load('lesson')`), new `app/Http/Controllers/Student/LessonController.php` +
  `GET /student/lessons/{lesson}` (enrolled + published only).
- Frontend: `resources/js/views/Student/Lesson.vue` renders `content`.
- Tests: `tests/Feature/Course/StudentLessonContentTest.php`.

### 4. Essay feedback + answer review (post-publication)
- DB: `exams.allow_answer_review` (additive boolean, default true).
- Backend: `ExamAttemptResource` (review payload after `grades_published_at`: per-question
  `feedback`, `points_earned`, `is_correct`, `reference_answer`, option correctness),
  exam requests + teacher exam form + `StudentExamDetailResource`/`StudentExamResource`.
- Tests: `tests/Feature/Exam/StudentAnswerReviewTest.php`.

### 5. Interruption / heartbeat redesign (warning-based, honest evidence)
- Config: `config/integrity.php` (`warning_threshold` default 5, `THRESHOLD_TERMINATION` risk
  entry, docs).
- Enum: `IntegrityEventType::clientReportable()` becomes an **explicit allowlist**;
  new server-only `THRESHOLD_TERMINATION`.
- DB (additive): `exam_attempts.violation_warnings`, `exam_attempts.end_reason`,
  `exam_attempts.raw_percentage`, `exam_integrity_settings.violation_warning_threshold`,
  `exam_attempt_integrity_settings.violation_warning_threshold`.
- Backend: `ExpireExamAttemptAction` (heartbeat timeout no longer terminates or records
  events), `TerminateExamAttemptAction` (config-driven risk, honest event types, `end_reason`),
  `RecordIntegrityEventAction` (warning counter + `should_terminate` response, threshold
  termination), `CreateAttemptIntegritySettingsAction` / `AssignExamIntegritySettingsAction`
  (freeze threshold), `Student\AttemptController` (terminate endpoint = threshold-driven only),
  integrity settings update request/controller.
- Frontend: `resources/js/composables/useExamIntegrity.js` (warnings not first-strike
  termination; no fabricated `WINDOW_BLUR` on pagehide; per-burst dedupe instead of
  once-per-session), `resources/js/views/Student/ExamTake.vue` (warning banner, connection
  banner, offline answer queue + idempotent resync, localStorage recovery state, end-reason
  explanations), `resources/js/api/index.js` (warning payload handling).
- Tests: `tests/Feature/Integrity/InterruptionFairnessTest.php`; rewrite obsolete
  heartbeat/terminate regression tests (documented).

### 6. Percentage precision at the pass boundary
- Backend: `CalculateExamResultAction` (raw vs display percentage), grading actions store
  `raw_percentage`; `ExamAttempt::outcome()` compares raw (legacy rows keep rounded compare —
  historical results unchanged).
- Tests: boundary cases 59.49 / 59.5 / 59.99 / 60 / 60.01 in `AttemptOutcomeTest` +
  `MultipleChoiceGradingTest` helpers.

## P1 — Reliability & operations

### 7. Auto-submit at deadline
- Backend: `app/Actions/Exam/AutoSubmitExpiredAttemptAction.php` (policy: `exams.expiry_mode`
  additive column `auto_submit|expire`, default `auto_submit`), `ExpireExamAttemptAction`
  delegates, `app/Console/Commands/ProcessExpiredAttemptsCommand.php` (`attempts:process-expired`,
  scheduled every minute in `routes/console.php`), `end_reason` on all finalizations.
- Tests: `tests/Feature/Exam/AutoSubmitAtDeadlineTest.php`; update expiry tests that encoded
  blank-expiry (documented).

### 8. Soft delete / archive (content entities)
- DB: `deleted_at` on `courses`, `units`, `lessons`, `exams` (additive).
- Backend: `SoftDeletes` on those models; delete actions soft-delete; `restore` endpoints;
  `->withTrashed()` on historical read relations (`ExamAttempt::exam`, `Exam::course`,
  `Enrollment::course`, …) so history stays readable. Students keep suspend/restore +
  deactivate (documented decision: users are auth identities with unique emails — no soft delete).
- Tests: `tests/Feature/Hardening/SoftDeleteRecoveryTest.php`.

### 9. Staff audit log
- DB: `audit_logs` table. Backend: `app/Models/AuditLog.php`, `app/Support/AuditLogger.php`,
  wiring in auth/student/exam/grade/competition/import/export actions,
  `GET /admin/audit-logs` (admin), `GET /teacher/audit-logs` (own scope).
- Tests: `tests/Feature/Admin/AuditLogTest.php`.

### 10. Result exports (CSV + print sheet)
- Backend: `GET /teacher/exams/{exam}/results/export?format=csv|print`,
  `GET /teacher/students/{student}/transcript?format=csv|print` (CSV native; PDF via
  print-optimized HTML — no new composer deps available offline; documented).
- Frontend: export buttons on `Teacher/ExamDetail.vue`, `Teacher/StudentShow.vue`.
- Tests: `tests/Feature/Teacher/ResultExportTest.php`.

### 11–12, 15–16. Attempt search + grouped-by-student UX
- Backend: `GET /teacher/exams/{exam}/attempts?search=` (server-side search: name / student
  code / email / phone), `GET /teacher/exams/{exam}/attempts-grouped` (per-student groups with
  latest/best summary).
- Frontend: `Teacher/ExamDetail.vue` attempts workspace (search, expandable student groups,
  contextual actions: View / Grade / Publish / Export / Open student / Integrity).
- Tests: `tests/Feature/Teacher/AttemptSearchGroupingTest.php`.

### 13. Bulk student import
- Backend: `POST /teacher/students/import` (CSV; `dry_run` preview; per-row report;
  transactional create via `CreateStudentAction`).
- Frontend: import modal in `Teacher/Students.vue` (upload → preview → confirm → report +
  credentials sheet).
- Tests: `tests/Feature/Teacher/BulkStudentImportTest.php`.

## P2 — Product completeness

### 14. Assignments / homework
- DB: `assignments`, `assignment_submissions`. Backend: model/policy/requests/actions,
  teacher CRUD + grade, student list + submit (text/file). Routes under `/teacher` + `/student`.
- Frontend: `Teacher/Assignments.vue` + form, `Student/Assignments.vue` + submit.
- Tests: `tests/Feature/Assignment/AssignmentLifecycleTest.php`.

### 15. Lesson attachments
- DB: `lesson_attachments`. Backend: upload/delete (teacher), list/download (student,
  enrollment-gated). Frontend: CourseDetail + Student Lesson.
- Tests: `tests/Feature/Course/LessonAttachmentTest.php`.

### 16. Certificates
- DB: `certificates` (unique verification code). Backend: eligibility (published lessons
  complete), idempotent issue, privacy-safe public verification
  (`/public/certificates/{code}`, throttled), teacher listing. Frontend: student claim +
  printable sheet, verification page.
- Tests: `tests/Feature/Course/CertificateTest.php`.

### 17. Scheduled reminders + notification delivery
- DB: `users.notification_preferences` (additive JSON). Notifications become `ShouldQueue`
  (database queue). New: `ExamStartingSoonNotification`, `ExamClosingSoonNotification`,
  `AssignmentDueSoonNotification`, `CompetitionEndingSoonNotification`,
  `CertificateAvailableNotification`. Command `reminders:dispatch` (scheduled).
- Web Push: VAPID subscription/delivery, allowlisted HTTPS endpoints, origin-confined click URLs,
  quiet-hour gating, dead-endpoint cleanup, and in-app fallback are implemented. PHP transport and
  endpoint-security feature tests passed in CI run `36952481922`; provider egress/DNS and deployment
  controls remain **NOT TESTED**.

## Configuration / ops
- `routes/console.php`: schedule `attempts:process-expired` (every minute), `reminders:dispatch`.
- Relevant env vars include `EXAMS_DEFAULT_EXPIRY_MODE`, integrity settings in
  `config/integrity.php`, VAPID keys, `PUSH_ALLOWED_ENDPOINT_HOSTS`, push timeouts/caps, and
  database backup retention options. `db:backup` remains local-only; encryption/off-host delivery
  and restore drills are open release gates.
- Queue/scheduler requirements are documented for deployment (`queue:work`, `schedule:work`/cron).
- PHP/Composer are unavailable locally. SQLite-backed backend tests and test-database migrations
  passed in CI run `36952481922`; Composer audit, MySQL upgrade/locking, and operational checks remain
  **NOT TESTED**. Frontend tests/build/npm audits passed; see the current audit report.

## Tests / verification policy
- Regression tests added per fix; obsolete tests updated only where the new behaviour is
  intentional (heartbeat termination → fairness warnings; blank expiry → auto-submit), each
  change documented in the final report. Existing snapshot/IDOR/competition tests untouched.
- Runtime note: PHP and Composer are unavailable locally. `npm ci`, frontend tests/build, and npm
  audits passed on 2026-10-02. GitHub Actions run `36952481922` passed PHP syntax lint and the
  configured PHPUnit suite on PHP 8.2/8.3. Composer audit, MySQL, browser, and operational checks
  remain **NOT TESTED**; see [`PRODUCTION_READINESS_AUDIT.md`](PRODUCTION_READINESS_AUDIT.md).
