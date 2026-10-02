# Production-Readiness Audit — Risk-Based Test Plan

- **Prepared:** 2026-10-02
- **Audit phase:** Discovery, targeted remediation, and the current PHP-enabled CI retest are complete; production-engine, browser, and operational validation remain outstanding.
- **Important:** This is a test plan, not a production-readiness certification. Frontend checks passed locally and in CI; the full configured PHP suite passed on PHP 8.2/8.3 in CI run [`36952481922`](https://github.com/Mina13323/teacher-system/actions/runs/36952481922). MySQL-specific and operational checks remain **NOT TESTED**. The branch contains inherited and audit commits; no clean-room deployment was performed.

## 1. Scope and safety rules

The audit covers the Laravel API and Vue/PWA across authentication and roles, course/enrollment access, exam creation through grading and recovery, integrity handling, make-ups and bulk operations, assignments, competitions, certificates, notifications, database constraints/migrations, deployment/backup, dependencies/secrets, observability, performance, and regression coverage.

- Reproduce and fix defects in a local test database; never delete or mutate production data.
- Use fake transports for push/HTTP behavior. SSRF tests must not contact public, private, link-local, or metadata services.
- Load, concurrency, and chaos testing require isolated staging or disposable infrastructure with synthetic data and an agreed load budget. Do not run denial-of-service or destructive fault tests against production.
- Preserve the server-authoritative exam deadline: `MIN(attempt_started_at + duration_minutes, exam_window_end)`. Recovery must never extend/reset it or create a second attempt.
- For integrity and accessibility, keep ordinary text editing, selection, IME composition, and accessibility shortcuts working in answer fields. Fullscreen loss must remain an audited observation, not an automatic disqualification absent explicit policy.
- Keep historical attempts and answer snapshots intact except through the confirmed, audited, selected-record operation permitted by policy.
- Report every unavailable execution as **NOT TESTED**, with the environment limitation.

## 2. Baseline evidence and execution constraints

| Area | Prior findings / baseline evidence | Current verification and remaining work |
|---|---|---|
| Remote CI | Earlier runs were red: `36950159600` exposed an enrollment enum/string issue; `36950480773` retained five failures per PHP matrix; `36951038360` failed a fixed question-order assertion while question shuffling was enabled. Run `36952304344` then failed a fixed option-order assertion while `shuffle_options` remained enabled (756 tests, 3,775 assertions, one failure per PHP matrix); snapshot counts passed. | Fix `6bd4d24` disables both question and option shuffling only in the order-sensitive snapshot batch test. Latest run [`36952481922`](https://github.com/Mina13323/teacher-system/actions/runs/36952481922) on source commit `6bd4d24f2bf384b02bd5588695942c25be3f70d5` passed: PHP lint/full PHPUnit on PHP 8.2 and 8.3, frontend tests/build, and repo guards. Optional composer.lock regeneration was skipped. Structured results confirm success; successful-run details/counts were not retrieved. Non-blocking annotations flagged checkout Node 20 being forced to Node 24 and the `ubuntu-latest` move to Ubuntu 26 from 2026-10-19. |
| CI code lead | Earlier annotations mentioned `Teacher\ExamController::applyAttemptFilters()` accepting `Builder|Relation`; that concern did not account for all earlier failures. | Regression coverage and the full configured PHP test jobs pass in the latest run `36952481922`; the type-boundary case is no longer a current CI blocker. |
| Pagination guard | The remote guard failed on run `36943046841`; the local equivalent check passed. | The repository guard, including pagination, passed in run `36952481922`; no direct request `per_page` reads were found and checked `paginate()` calls use capped `perPage()`. |
| Runtime | PHP, Composer, and Docker remain unavailable in this checkout. Node 22/npm 10 are available; local frontend tests/build/audits passed; a JavaScript PHP parser accepted 600 files. | PHP syntax lint and the full configured PHPUnit suite passed on PHP 8.2/8.3 in run `36952481922`. Composer validation/audit were not run. Local PHP/Composer commands remain unavailable. |
| Database | Feature tests use SQLite; production-engine MySQL behavior and locking semantics had not been run. | SQLite-backed feature tests and test-database migrations passed as part of the CI suite. MySQL schema/locking, supported upgrade/rollback paths, and production concurrency remain **NOT TESTED**; use disposable CI/staging only. |

## 3. Prioritized regression and security matrix

### P0/P1 — release blockers

1. **Authorization and course-content boundary**
   - Unauthenticated course catalog/detail requests remain unauthorized.
   - A non-enrolled student can view the intended course-preview metadata but cannot receive lesson body `content`, protected playback references, or internal media/storage identifiers from `/api/v1/courses/{id}`.
   - A student with active access, lesson capability, and active enrollment can read the published lesson via the dedicated student lesson endpoint; non-enrolled, suspended, inactive, unpublished, and capability-disabled users are denied.
   - A staff member who does not manage the course cannot receive staff-only video fields through the generic catalog route; the authorized teacher-management route continues to work.
   - Verify course listing and enrollment status continue to reflect the authenticated viewer, not cached user-specific state.

2. **Exam timing, recovery, and autosubmission**
   - Before `starts_at`: reject and create no attempt. At the opening instant: preserve the documented inclusive-open behavior.
   - At and after `ends_at`: reject the start and create no zero-time attempt, deadline extension, or duplicate attempt; the exact-equality case now has a passing CI regression test.
   - For late entry, verify persisted `expires_at` equals `MIN(started_at + duration, ends_at)`; client-supplied timing is ignored.
   - Ambiguous start timeout performs a bounded read-only active-attempt lookup and never repeats the start POST automatically.
   - Integrity resume before the persisted deadline resumes the same attempt, preserving answers/snapshot/events and leaving the deadline unchanged. Resume at/after the deadline must not restore time or reopen the attempt.
   - Expired-attempt scheduler and request-time fallback are idempotent under retries; autosubmit preserves and grades saved answers according to the configured expiry policy.
   - Verify answer saves after deadline are rejected, heartbeat/submit cannot extend the deadline, and concurrent start/submit attempts cannot duplicate state. SQLite does not validate production row-lock semantics; repeat race cases with MySQL in isolated staging.

3. **Exam answers, snapshots, grading, and regrade**
   - Single-choice and multiple-choice selections, deselection, essay persistence/recovery, and required/optional explanation validation.
   - Explanations remain separate from selected answers and never affect automatic correctness.
   - Correct answers, points, question text, and options are frozen in the attempt snapshot; answer-key edits do not silently alter historical scores.
   - The explicit, confirmed regrade operation updates only eligible historical MCQ answers, uses row locking, preserves essays and unrelated attempts, and records an audit trail.
   - Results and answer keys remain hidden until the configured publication/review gate opens; student and staff resources expose only their authorized fields.

4. **Enrollment, make-up assignments, bulk selection/deletion**
   - Self-enrollment requires a student account, active access, required course capability, and a published course; duplicate enrollment is conflict-safe and unique at the database layer.
   - Student course listing/detail and lesson access are scoped to that student's active enrollment and capabilities. Cover lesson-only, exam-only, suspended, inactive, expired-access, and reactivated cases.
   - Make-up assignments are created only for explicitly selected, actively enrolled students; duplicate active grants are prevented; consuming a grant links exactly one new attempt; revocation is auditable and cannot alter earlier attempts.
   - At the UI selection cap, both make-up and attempt controls allow deselection and reject additions. An attempt that becomes `in_progress` while selected can still be removed from the selected set.
   - Attempt deletion is limited to explicit selected IDs belonging to the exam, requires confirmation/reason, rejects active attempts, soft-deletes only selected attempts, and audits each deletion. Assert answers, students, exams, enrollments, and unselected attempts remain unchanged.
   - Student deactivation/anonymization and permanent force deletion must be separate operations. The ordinary account-removal path must preserve academic history; permanent force deletion is reserved for the highest-level administrative role and must not automatically cascade-delete attempts/answers unless separately and explicitly authorized. Add policy, authorization, audit, confirmation, and referential-integrity regression coverage.

5. **Authentication and account security**
   - Email, `login`, and student-code aliases normalize consistently before throttling; per-IP and per-account budgets apply without a global empty-identifier bucket. Verify malformed/oversized input returns 422 rather than 500.
   - Uniform invalid-credential behavior; inactive/suspended/access-expired account denial; token revocation on deactivation/password reset; logout invalidates the active token.
   - Temporary/generated password lifecycle: a `must_change_password` account is restricted to the password-change flow, successful change clears the flag, and regeneration/reset revokes old tokens and re-enforces change where intended.
   - Validate role/ownership boundaries and IDOR resistance across admin, teacher, assistant, and student routes, including cross-teacher and cross-student IDs.
   - Determine whether API bearer tokens should expire; current Sanctum config sets no global expiry. Verify production deployment policy before changing session lifetime.

### P2 — high risk / operational readiness

6. **Push, uploads, and externally triggered work**
   - Validate push endpoints as HTTPS/provider-approved only; reject local, private, link-local, metadata, malformed, and redirected targets. Use a fake transport and assert redirects are disabled; never make a real network request in tests.
   - Preserve push payload encryption, per-user subscription ownership, dead-endpoint cleanup, deduplication, quiet hours, and the in-app-notification fallback.
   - Check assignment and lesson attachment authorization, file size/MIME/path handling, private storage, and authorized download behavior. Verify question-image public/private intent and that no raw private path leaks.

7. **Enrollment and dependent feature lifecycle**
   - Assignment create/submit/grade/download ownership; duplicate submissions and deadline handling.
   - Competition publish/open/close/join capacity/ranking/disqualification lifecycle and consistency between list, show, and leaderboard for students with and without required access.
   - Certificate uniqueness/eligibility, public verification minimum disclosure, progress/roadmap gating, Q&A moderation, personal notes/bookmarks, and notification ownership.
   - Bulk student import, deduplication, credential handling, batch limits, rollback/partial-failure behavior, and audit logging.

8. **PWA, recovery, and browser interaction**
   - Verify service-worker policy: `/api/` and protected storage remain NetworkOnly; only safe static assets are cached; navigation fallback does not swallow API/storage paths; updates remain prompt-driven.
   - Simulate interrupted start, answer-save timeout, page hide/reload, offline-to-online transition, and submission at deadline. Local recovery may restore drafts only for the same attempt and must never invent server state or time.
   - Browser/manual coverage: desktop/mobile Chromium, Firefox, and Safari where available; keyboard-only navigation, screen reader, RTL Arabic layout, IME input, essay editing/selection, paste rules, fullscreen exit, PWA install/update, and stale service-worker recovery.
   - Browser/device coverage is **NOT TESTED** unless a browser matrix is actually executed.

9. **Data, backup, migration, and disaster recovery**
   - Assert unique constraints and foreign keys for email/student code, enrollment pairs, active attempts, answer pairs, snapshot rows, and unique make-up `attempt_id`; verify intended delete/null/soft-delete behavior.
   - Run migrations from an empty DB and a supported upgrade path; explicitly review every constraint-changing migration and its rollback. The student-attempt migration changes the FK from cascade to restrict; its rollback restores cascade and must not be run casually. Test MySQL-specific SQL and transaction/lock behavior in disposable MySQL, not production.
   - Back up synthetic SQLite and MySQL databases, verify artifact integrity, restore into an isolated disposable target, and confirm schema plus representative exam/enrollment/answer data. Verify retention, permissions, encryption, and off-host copy.
   - Regression coverage for SQLite `VACUUM INTO`, standalone artifact integrity, and restore into an isolated disposable SQLite file passed in CI run `36952481922`. MySQL dump/restore, encryption, off-host storage, retention operations in production, and recovery-time objectives remain **NOT TESTED**.

### P2/P3 — performance, failure modes, and defense in depth

10. **Pagination, query cost, and load**
    - Ensure every list endpoint caps page size and has stable ordering; test huge, zero, negative, and malformed page sizes.
    - Inspect query counts for catalog/detail, exam attempts/grouping, dashboards, notifications, reminders, and analytics; check required indexes with representative synthetic data.
    - Load-test only isolated staging with an agreed profile, concurrency, duration, and abort threshold. No production load/DoS testing.

11. **Failure handling and observability**
    - Inject failures into answer persistence, grading, push, queue, backup, scheduler, and exports. Verify transaction rollback, idempotent retry, actionable logs, and a nonzero command result when any expired attempt fails.
    - Check `/up` and admin metrics semantics; verify queue/failed-job visibility and alerting runbooks. Confirm secrets and student content are not emitted in logs.
    - Chaos/failover testing for DB loss, queue outage, disk-full, process kill, backup corruption, and network partition is **NOT TESTED** without an isolated environment.

12. **Dependencies, secrets, and transport**
    - Run `composer validate`, `composer audit`, `npm audit --omit=dev` (and a full dev audit), secret scanning, PHP lint/static analysis, and frontend tests/build.
    - Resolve Laravel framework lifecycle/advisory policy before production release; broad advisory ignores are not remediation.
    - Inspect deployment environment for `APP_DEBUG=false`, strong `APP_KEY`, TLS, trusted proxy/host config, security headers, private storage, queue worker, scheduler, logging/rotation, and credential separation. These runtime deployment checks require owner-provided approved staging/deployment access and are otherwise **NOT TESTED**.

## 4. Verification commands and execution status

Run only after discovery is recorded and targeted fixes/regression tests are ready:

```sh
npm ci
npm test -- --run
npm run build

# In a PHP/Composer-enabled environment:
composer validate --strict
composer audit
php artisan test
php artisan test --filter=ExamWindowTest
php artisan test --filter=ResumeFlaggedAttemptTest
php artisan test --filter=StudentLessonContentTest
php artisan test --filter=AttemptSearchGroupingTest
php artisan test --filter=RegradeQuestionAttemptsTest
php artisan test --filter=BackupDatabaseTest
```

Execution status as of 2026-10-02 (session 3 update):
- **Dependencies & Security:** Upgraded to **Laravel 13** (`laravel/framework: v13.34.0`, `laravel/tinker: v3.0.2`, `spatie/laravel-permission: v8.3.0`, `league/commonmark: v2.10.3`). `composer validate --strict` **PASS**. `composer audit` reports **"No security vulnerability advisories found."** with 0 ignored and 0 un-ignored advisories. `npm audit` and `npm audit --omit=dev` report 0 vulnerabilities.
- **Frontend Verification:** `npm test` passed (4 files, 27 tests). `scripts/build-frontend.mjs` built and published cleanly to `public/`.
- **Backend Test Suite (Laravel 13):** `php artisan test` ran 760 tests: **759 passed, 1 failed (3,782 assertions, 156.64s)**. The single failure is `WebPushTest::test_payload_encryption_and_dead_endpoint_cleanup` due to a known local Windows PHP 8.5.7 ZTS OpenSSL EC key generation limitation (`openssl_pkey_new` with `prime256v1`), which passes in CI on Linux.
- **Anonymization & Retention (DATA-002):** Added `anonymized_at` column (migration `2026_10_02_000002_add_anonymized_at_to_users.php`), `User::isAnonymized()`, and lifecycle guards in activation, credential reset, password reset, profile update, and email change actions. All 13 tests in `StudentDataRetentionTest.php` pass.
- **CI Hardening:** Pinned runners to `ubuntu-24.04`, upgraded to `actions/checkout@v6`, Node 24 runtime, and added `composer validate --strict` and `composer audit` steps.

## 5. Confirmed policy decisions

- **Exam end boundary:** reject a start request when `now >= ends_at`; equality must create no attempt. Preserve the inclusive `starts_at` behavior and the hard deadline `MIN(started_at + duration_minutes, ends_at)`.
- **Student deletion:** provide separate history-preserving disable/anonymize and permanent force-delete operations. Force deletion is restricted to the highest-level administrative role; attempts/answers must not be automatically cascaded unless separately and explicitly authorized. Add distinct confirmation and audit coverage.
- **Password recovery policy (AUTH-002):** Confirmed by system owner that self-service password recovery is disabled. Staff-assisted resets only; no public forgot-password endpoint or email reset flow is exposed. All credential management remains authenticated and staff-mediated.
- **Anonymization lifecycle (DATA-002):** Anonymization is permanent and irreversible through ordinary management channels. An anonymized account cannot be reactivated, have its credentials regenerated or reset, or have its profile or email modified.
