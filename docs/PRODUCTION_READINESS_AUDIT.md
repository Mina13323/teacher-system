# LMS Production-Readiness Audit

- **Review date:** 2026-10-02
- **Scope:** Laravel API/backend, Vue/PWA frontend, database/schema changes, auth and authorization, course/enrollment access, exams and recovery/grading, make-ups/bulk actions, operational commands, dependencies, and verification evidence.
- **Environment:** Audit source commit `f920385` on `arena/01a0f7fa-teacher-system`; no production data or production services were used. The working tree was clean at the source commit before this report refresh. PHP, Composer, and Docker are unavailable locally.

## Executive decision

**The LMS is not cleared for production.** The latest application-code CI run, [`36951383243`](https://github.com/Mina13323/teacher-system/actions/runs/36951383243), passed on 2026-10-02 for source commit `f920385`: the PHP 8.2/8.3 lint and full PHPUnit jobs, repository guards (including pagination), and frontend unit/build jobs all succeeded. This resolves the observed CI failures. However, the dependency set still pins unsupported Laravel 11.57.0 with known advisories; Composer audit/validation and a framework upgrade remain outstanding. MySQL migration/locking behavior, encrypted off-host backup/restore, browser/manual validation, staging load/failure drills, and deployment configuration remain **NOT TESTED**.

This is a code review plus the verification listed below—not a production-readiness certification. Passing CI covers the configured automated suite, not every scenario in the test plan or the unsupported production-engine and operational checks. Do not deploy this worktree as a production-ready release.

## Findings

### DEP-001 — Unsupported Laravel line and unresolved framework advisories

- **Severity:** High — release blocker.
- **Status:** Open; no dependency upgrade performed.
- **Evidence/reproduction:** `composer.json` allows `laravel/framework` `^11.31`; `composer.lock` pins `v11.57.0`. Composer configuration explicitly ignores Laravel advisory identifiers and says the ignores are for dependency resolution only, not remediation. Laravel 11 security support ended on 2026-03-12. The pinned release is in affected ranges for the reviewed High-severity CRLF-injection advisory; the reviewed signed-URL path-confusion advisory also has patched ranges above Laravel 11. `composer audit` could not run locally because Composer/PHP are absent.
- **Root cause:** The application remains on an end-of-security-support major, and advisory ignores suppress dependency-resolution failures without upgrading vulnerable code.
- **Required fix:** Upgrade to a currently supported, patched Laravel release; verify all current advisories; remove the ignores only after the dependency tree is actually remediated; check PHP/runtime compatibility and application behavior.
- **Test/retest:** `composer validate`, `composer audit`, and a dependency upgrade remain **NOT TESTED** because Composer/PHP are unavailable locally and the CI workflow does not run Composer audit. PHP lint and the full configured PHPUnit suite passed in CI run `36951383243` (see CI-001); passing application tests does not establish support status or remove advisories.
- **Evidence sources:** [Laravel support schedule](https://laravel.com/docs/master/releases); [GHSA-5vg9-5847-vvmq](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq); [GHSA-crmm-hgp2-wgrp](https://github.com/advisories/GHSA-crmm-hgp2-wgrp).

### DEP-002 — Unsupported transitive `glob` release warning in Workbox build chain

- **Severity:** Low — developer/build dependency hygiene.
- **Status:** Fixed in the lockfile; local frontend retest passed.
- **Evidence/reproduction:** The prior `npm ci` installed `glob@11.1.0` through `vite-plugin-pwa → workbox-build@7.4.1` and emitted npm's deprecation warning that old glob releases are unsupported and contain known security issues. `npm audit` itself reported zero vulnerabilities, so this was a warning rather than an audit finding.
- **Root cause:** Workbox declares `glob` `^11.0.1`, whose latest compatible 11.x release is deprecated.
- **Fix:** Added a scoped npm override for `workbox-build` to `glob@13.0.6`; the override is limited to the build dependency chain.
- **Test/retest:** `npm ci` cleanly installed 469 packages with zero audit findings and no glob deprecation warning; `npm ls glob` showed only the overridden 13.0.6; `npm test` (27 tests), `npm run build`, both npm audits, and the frontend unit/build jobs in CI run `36951383243` passed.

### CI-001 — Earlier backend CI failures are resolved on the audited code commit

- **Severity:** High if a required backend job regresses; no current CI blocker on the audited source commit.
- **Status:** Resolved for source commit `f920385`; reopen if a subsequent required CI run fails.
- **Evidence/reproduction:** Initial run [`36943046841`](https://github.com/Mina13323/teacher-system/actions/runs/36943046841) tested an earlier child commit (`8da3a72…`) and was red. Subsequent failures were diagnosed incrementally: run `36950159600` exposed an enrollment-status enum being treated as a string; run `36950480773` still had five failures per PHP matrix, including enrollment-authorization expectations, the exact exam-window end boundary, and regrade fixture setup; run `36951038360` had one remaining snapshot-test failure. In that last run, the 51-question and 102-option fixture/snapshot counts passed; the failing assertion compared the live question ID at position 51 to a fixed snapshot position while `shuffle_questions` was enabled. The earlier `applyAttemptFilters()` annotation was not an explanation for all failures.
- **Root cause:** Multiple discrete implementation/test issues were corrected. The final failure was a nondeterministic test assumption about shuffled question order, not a fixture-count or snapshot-batch failure.
- **Fix:** `ab0dd93` handles the enrollment enum status; `ad1c214` corrects enrollment/exam/regrade regressions; `f920385` disables question shuffling in the order-sensitive snapshot batch test and restores the snapshot action's existing relation-load behavior. The batch test still covers 51 questions and 102 options.
- **Test/retest:** GitHub Actions run [`36951383243`](https://github.com/Mina13323/teacher-system/actions/runs/36951383243) completed with overall `success` on source commit `f9203851fd2a854856a1e4299915bf2cabf60e75`. PHP lint and the full configured PHPUnit test steps passed on PHP 8.2 and 8.3; repository guards and pagination passed; frontend unit tests and build passed. The optional composer.lock-regeneration job was skipped. GitHub's structured run view confirms successful job conclusions, but detailed log download returned EOF, so exact current test/assertion counts are not reported here.

### DATA-001 — Student hard deletion could erase exam-attempt history through cascade

- **Severity:** High — data-integrity risk.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** The prior teacher delete and batch-delete paths removed student accounts while `exam_attempts.student_id` used cascade-on-delete. Removing the referenced user could therefore erase attempt rows and their dependent history, including soft-deleted attempts, without a separate selected-history confirmation.
- **Root cause:** Account removal and academic-history deletion were coupled, with the foreign key encoding cascade rather than the required retention policy.
- **Fix:** Teacher removal is now history-preserving deactivation. Admin workflows are split into anonymization and explicitly confirmed force deletion; force deletion is admin-only and audited. A migration changes the attempt FK to `RESTRICT`; the force-delete action enumerates and audits only that student's attempts before deleting them. The migration rollback restores cascade and must not be run casually.
- **Regression tests:** `tests/Feature/Admin/StudentDataRetentionTest.php`, `tests/Feature/Student/StudentLifecycleTest.php`, and related student tests cover permissions, history preservation, and explicit deletion behavior.
- **Test/retest:** The student-retention/lifecycle regression tests passed as part of the PHP 8.2/8.3 suite in CI run `36951383243`; the suite uses SQLite and exercises test-database migrations. MySQL FK behavior, supported upgrade/rollback paths, and production-engine preservation remain **NOT TESTED**.

### DATA-002 — Anonymized accounts have no durable marker or lifecycle guard

- **Severity:** Medium — privacy and retention-policy risk.
- **Status:** Open; the intended post-anonymization reactivation policy remains unresolved.
- **Evidence/reproduction:** `AnonymizeStudentAction` removes direct identifiers and deactivates the account, but does not set a dedicated `anonymized_at`/state marker. The shared account-state action can activate a student without checking an anonymization marker. Existing retention tests verify identifier removal and retained history, but do not test repeat anonymization or later reactivation/reset/update.
- **Root cause:** Anonymization is represented only by rewritten PII and ordinary account flags, not by an explicit lifecycle state consumed by other actions.
- **Required fix:** Confirm whether anonymization is irreversible. If it is, add a durable marker and block activation, credential reset, and ordinary profile updates (with admin-only audited exception only if explicitly approved); otherwise define and test the controlled re-identification flow. Do not infer the policy.
- **Test/retest:** **NOT TESTED** — no marker/lifecycle guard regression coverage exists; policy decision required before changing behavior.

### PUSH-001 — Browser-controlled push endpoints created SSRF and ownership risks

- **Severity:** High — security risk.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** A stored browser subscription endpoint is later used as an outbound HTTP destination with VAPID headers. Accepting arbitrary endpoints or following redirects could allow requests outside the intended push providers. Upserting by endpoint while replacing `user_id` could also let another authenticated account take over a stored endpoint; concurrent registrations could race the per-user cap.
- **Root cause:** The outbound destination and global ownership of a subscription were not sufficiently constrained at the validation and persistence boundaries.
- **Fix:** Require HTTPS on a configured host allowlist; reject literal IPs and unsafe URL components; revalidate stored endpoints before send; disable redirects, enforce TLS verification and bounded timeouts; reject endpoint ownership conflicts; lock the user row while persisting and enforcing the per-account subscription cap.
- **Regression tests:** `tests/Feature/Notification/WebPushTest.php` covers rejected destinations, ownership conflicts, idempotency, and the cap. `resources/js/utils/pushNotificationUrl.spec.js` covers unsafe notification URLs.
- **Test/retest:** Frontend URL tests passed (2 tests, included in the 27-test run), and the PHP push feature tests passed in CI run `36951383243`. No real external or internal network request was made. Provider egress controls/DNS behavior and deployment restrictions remain **NOT TESTED**.

### EXAM-001 — Multiple-choice questions were not multi-select end-to-end

- **Severity:** High — grading correctness.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** The baseline model stored one `exam_answers.option_id`, the student UI acted like radio buttons, and grading chose one correct option even though authoring permitted multiple keys. The baseline had no multi-select regression test.
- **Root cause:** A question type was exposed in authoring without a normalized multi-answer persistence, UI, API, and scoring contract.
- **Fix:** Multi-select answers use normalized `exam_answer_options` rows; the student UI toggles a selected set and submits option IDs; scoring and regrade use exact set matching while retaining legacy single-option reads; publish validation enforces the correct number of keys per type.
- **Regression tests:** `tests/Feature/Exam/MultipleChoiceGradingTest.php` and `tests/Feature/Exam/RegradeQuestionAttemptsTest.php` cover set matching, validation, and historical compatibility.
- **Test/retest:** The multiple-choice grading and corrected-key regrade tests passed in the SQLite-backed PHP 8.2/8.3 suite in CI run `36951383243`. Production-DB scoring/locking behavior remains **NOT TESTED**.

### EXAM-002 — Pass/fail, review status, and threshold rounding could disagree

- **Severity:** High — grading/result correctness.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** The baseline calculated pass status in separate resources/analytics and compared a rounded integer percentage in one path. A flagged attempt could appear failed in one view and passed in another, and values near a threshold could be rounded into a pass.
- **Root cause:** Outcome policy was duplicated and display rounding participated in the decision.
- **Fix:** `ExamAttempt::outcome()` and `AttemptOutcome` now centralize outcomes; flagged/unpublished work remains pending review, confirmed disqualification is distinct, and new attempts compare raw percentage to the frozen pass threshold while legacy rows retain their stored historical comparison.
- **Regression tests:** `tests/Feature/Exam/AttemptOutcomeTest.php` and result/analytics tests cover state and boundary cases.
- **Test/retest:** The `AttemptOutcome` and result/analytics feature tests passed in the SQLite-backed PHP 8.2/8.3 suite in CI run `36951383243`. Broader manual result/resource review remains **NOT TESTED**.

### INTEGRITY-001 — Heartbeat/network loss must not be treated as cheating

- **Severity:** High — exam fairness and integrity.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** The baseline expiry path could terminate a student after missed heartbeats and record a fabricated integrity event with hardcoded risk points. A network drop, sleeping device, or suspended PWA could therefore look like misconduct.
- **Root cause:** Transport liveness and browser-observed integrity events were conflated.
- **Fix:** Heartbeat/network loss no longer terminates an attempt or creates an integrity event. Only actual configured integrity events can trigger threshold termination; event types and risk points are configuration-driven and server-timestamped.
- **Regression tests:** `tests/Feature/Integrity/InterruptionFairnessTest.php` and `tests/Feature/Exam/ExamHardeningRegressionTest.php` cover interrupted sessions and threshold rules.
- **Test/retest:** The interruption-fairness and exam-hardening tests passed in CI run `36951383243`. Real browser fairness/accessibility checks remain **NOT TESTED**.

### COURSE-001 — Lesson body/progress data was not consistently delivered

- **Severity:** High — core course functionality/access.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** The baseline teacher API accepted lesson `content` but student resources omitted it; the direct progress lookup did not load its lesson relation, leaving the page without a title. A catalog-safe lesson preview and enrollment-gated student content endpoint are now separate.
- **Root cause:** Write, read, resource, and student-page contracts were not connected, and the progress resource relied on an unloaded relation.
- **Fix:** Student lesson delivery now returns student-safe content only behind enrollment/access policy; progress loads the lesson relation. Generic catalog previews remain separate from protected lesson content.
- **Regression tests:** `tests/Feature/Course/StudentLessonContentTest.php` and course/enrollment access tests cover content visibility and progress shape.
- **Test/retest:** Lesson-content and course/enrollment feature tests passed in CI run `36951383243`. Manual browser rendering and the broader device/accessibility matrix remain **NOT TESTED**.

### EXAM-003 — Essay feedback and answer review require publication gating

- **Severity:** High — exam confidentiality.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** Essay feedback and answer-key material must not reach the student before the configured publication/review gate. The baseline student result resources did not consistently deliver feedback after publication, while an ungated fix would expose review data prematurely.
- **Root cause:** Student and teacher attempt resources did not share an explicit, publication-aware review contract.
- **Fix:** Student review payloads are returned only when grade publication and the exam's answer-review setting permit them; grader identity is withheld. Explanations stay separate from correctness/scoring.
- **Regression tests:** `tests/Feature/Exam/StudentAnswerReviewTest.php` covers hidden-before-publication and visible-after-publication behavior.
- **Test/retest:** The student-answer-review feature tests passed in CI run `36951383243`; manual resource/browser review remains **NOT TESTED**.

### EXAM-004 — Saved answers on expired attempts needed deterministic finalization

- **Severity:** High — student fairness and recovery.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** The baseline could leave expired attempts ungraded while consuming the student's attempt allowance. Saved answers then had no deterministic final result after a crash or missed client submission.
- **Root cause:** Expiry closed attempts without a single idempotent server finalization path shared by request-time recovery and scheduler processing.
- **Fix:** `FinalizeExpiredAttemptAction` handles the configured auto-submit/expire policy; request-time and scheduled finalization use the same transactional path, preserve essays/saved answers, and do not extend the server deadline. The scheduled command now fails nonzero if any item fails (see OPS-001).
- **Regression tests:** `tests/Feature/Exam/AutoSubmitAtDeadlineTest.php`, `tests/Feature/Hardening/ExpirationAndIdempotencyTest.php`, and `tests/Feature/Hardening/ProcessExpiredAttemptsCommandTest.php`.
- **Test/retest:** Autosubmit, idempotency, expiration-command, and exact-window-end regression tests passed in CI run `36951383243`. The deadline remains `MIN(attempt_started_at + duration_minutes, exam_window_end)`; starts at `now >= ends_at` are rejected. Live scheduler/cron operation and production-engine races remain **NOT TESTED**.

### REG-001 — Public registration options were duplicated instead of using the enum

- **Severity:** Low — future validation/maintenance drift.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** The registration-link endpoint returned a literal three-value academic-year list independent of `AcademicYear::cases()`.
- **Root cause:** The API options were manually duplicated rather than derived from the canonical enum.
- **Fix:** The endpoint now maps `AcademicYear::cases()` to their backing values.
- **Regression test:** `tests/Feature/Auth/PublicStudentRegistrationTest.php` asserts the endpoint values equal the enum.
- **Test/retest:** The public registration option regression test passed in CI run `36951383243`.

### DOC-001 — Older readiness and implementation documents overstated current status

- **Severity:** Medium — release-process risk.
- **Status:** Partially remediated; full cross-document validation **NOT TESTED**.
- **Evidence/reproduction:** Older reports claimed “PRODUCTION READY” or “SECURITY AUDIT PASSED,” described public APIs incompletely, called Web Push deferred after it was implemented, and described all migrations as additive. The `2026_10_02_000001` migration changes FK delete policy; a generic rollback restores cascade. These contradictions could mislead deployment or rollback decisions.
- **Root cause:** Historical reports were not clearly labeled as snapshots when the code, routes, dependencies, and verification evidence changed.
- **Fix:** This current audit and test plan are now the source of truth. The prior final readiness/security reports are marked historical/superseded; the implementation map and system analysis distinguish baseline findings from current status; the Hostinger runbook is marked not cleared for production, its smoke checks are unchecked, and its generic rollback warning now calls out the cascade hazard.
- **Test/retest:** Manual document cross-check and `git diff --check` performed. Automated Markdown link/content validation is **NOT TESTED**; other historical reports may still contain stale implementation details.

### AUTH-001 — Login throttling needed to canonicalize identifier aliases

- **Severity:** Medium.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** The login throttle runs before `LoginRequest::prepareForValidation()`. Requests using `email`, `login`, and `student_code` could therefore be keyed inconsistently or consume an empty-identifier bucket instead of a common per-account budget.
- **Root cause:** Throttle-key construction did not canonicalize alternate login fields at the middleware boundary.
- **Fix:** The limiter now reads supported aliases before validation, resolves known email/student-code aliases to a stable user ID, and applies independent IP and account/identifier budgets.
- **Regression tests:** `tests/Feature/Auth/LoginRateLimitTest.php` covers alias and IP changes.
- **Test/retest:** Login rate-limit feature tests passed in the PHP 8.2/8.3 suite in CI run `36951383243`. Production reverse-proxy/IP configuration and live abuse behavior remain **NOT TESTED**.

### AUTH-002 — No self-service password recovery or verified email delivery

- **Severity:** Medium — account availability/supportability.
- **Status:** Open product/operations gap; current policy uses staff-assisted resets.
- **Evidence/reproduction:** The public auth surface exposes login, but no forgot-password/reset-token flow or verified outbound email delivery is configured. Teacher/admin staff can reset credentials; generated/temporary credentials require the forced-password-change flow. The deployment guide must not imply email delivery is live while `MAIL_MAILER=log` or equivalent remains configured.
- **Root cause:** Account recovery depends on authorized staff intervention; external delivery and a user-owned recovery channel are not implemented/verified.
- **Required fix:** Confirm whether self-service recovery is a production requirement. If required, configure a secure mail provider, expiring single-use reset tokens, enumeration-safe responses, throttles, audit events, and recovery tests; otherwise document the staff-assisted support process and recovery SLA.
- **Test/retest:** **NOT TESTED** — no recovery endpoint/provider/browser flow was run.

### OPS-001 — Expired-attempt command could report success after per-attempt failures

- **Severity:** Medium — monitoring/operational correctness.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**.
- **Evidence/reproduction:** A failed finalization was logged and counted, but the prior command exit behavior could still report success, preventing cron/monitoring from detecting incomplete work.
- **Root cause:** The command's process result did not reflect its accumulated failure count.
- **Fix:** Continue processing later attempts, log each failure, report the totals, and return a nonzero exit code when any attempt failed.
- **Regression test:** `tests/Feature/Hardening/ProcessExpiredAttemptsCommandTest.php` checks both continued processing and failure status.
- **Test/retest:** This command failure-status test passed in CI run `36951383243`. Live scheduler monitoring and alert delivery remain **NOT TESTED**.

### BACKUP-001 — SQLite backup was a raw database-file copy labeled as SQL

- **Severity:** High — recovery/data-integrity risk.
- **Status:** Remediation implemented; included regression tests passed in the current CI suite. Environment-specific verification remains **NOT TESTED**. Backup design remains local-only.
- **Evidence/reproduction:** The prior SQLite strategy copied only the main database file and named it `.sql`. With WAL mode, committed data may reside in sidecar state; a raw SQLite file is also not a portable SQL dump. A successful command could therefore create an incomplete or misleading recovery artifact.
- **Root cause:** File-copy backup was treated as a consistent database snapshot and shared the SQL artifact naming path.
- **Fix:** SQLite now uses `VACUUM INTO` to produce a standalone `.sqlite` snapshot, runs `PRAGMA quick_check`, rejects unsupported drivers and non-local `--disk` values, and prunes both `.sqlite` and `.sql` artifacts. MySQL/MariaDB continue to produce SQL dumps.
- **Regression tests:** `tests/Feature/Database/BackupDatabaseTest.php` checks WAL-mode data, artifact integrity, restoring into an isolated SQLite database, and rejection of a remote disk.
- **Test/retest:** The SQLite backup test passed in CI run `36951383243`, including standalone artifact integrity and isolated SQLite restore. MySQL dump/restore, production retention, deployment permissions, encryption, off-host copies, corruption recovery, and recovery-time objectives remain **NOT TESTED**.

### DR-001 — Production disaster recovery is not demonstrated

- **Severity:** High — operational release blocker.
- **Status:** Open.
- **Evidence/reproduction:** The implemented backup command writes local files only and explicitly rejects remote disks. No isolated MySQL restore, encrypted/off-host replication, restore drill, backup-corruption recovery, or measured recovery-time/recovery-point objective was run.
- **Root cause:** Backup delivery and restore operations depend on deployment infrastructure and a PHP/MySQL-capable approved environment that is not available in this checkout.
- **Required fix:** Configure encrypted off-host backup retention; validate and monitor backup artifacts; run documented SQLite and production-engine restore drills against disposable targets; establish and prove recovery objectives and alerting.
- **Test/retest:** **NOT TESTED** — no production or staging environment was accessed, and no destructive tests were performed.

### VERIFY-001 — Runtime, browser, performance, and failure verification is incomplete

- **Severity:** High for backend release validation; Medium for the unverified operational/browser matrix.
- **Status:** Open.
- **Evidence/reproduction:** PHP and Composer are not installed locally. GitHub Actions run `36951383243` ran PHP syntax lint and the full configured PHPUnit suite on PHP 8.2 and 8.3; the suite boots the Laravel application and uses SQLite. MySQL concurrency/upgrade migrations, manual browser/PWA coverage, staging load, and chaos/failover drills were not completed. The JavaScript PHP parser accepted 600 files locally; CI PHP lint is the stronger syntax evidence. Detailed CI log download returned EOF, while the structured job conclusions were successful.
- **Root cause:** MySQL, browser, and approved staging infrastructure are unavailable in this checkout. Production tests were intentionally out of scope.
- **Required fix:** Run Composer validation/audit and resolve framework support/advisories; then use approved disposable MySQL/staging and browser environments for upgrade/rollback, lock/race, PWA, load, failure, and operational checks.
- **Test/retest:** PHP lint and SQLite-backed PHPUnit suite **PASS** in CI run `36951383243`. Composer validate/audit, MySQL, browser/manual, load/chaos, deployment alerting, and recovery drills remain **NOT TESTED**.

## Verification performed on 2026-10-02

| Check | Result |
|---|---|
| `npm ci` | **PASS** after the scoped Workbox glob override; 469 packages audited, zero vulnerabilities, and no deprecation warning. |
| `npm test` | **PASS**; 4 files, 27 tests. |
| `npm run build` | **PASS**; 227 modules transformed; main JS chunk 416.70 kB raw / 139.05 kB gzip, CSS 66.06 kB raw / 11.04 kB gzip; PWA generated with 96 precache entries (982.05 KiB), 81 public asset files, all entry references present. |
| `npm audit` | **PASS**; zero reported vulnerabilities. |
| `npm audit --omit=dev` | **PASS**; zero reported vulnerabilities. |
| Pagination guard | **PASS** locally and in CI run `36951383243`; no direct request `per_page` reads and checked `paginate()` calls use capped `perPage()`. |
| PHP syntax-oriented parser | **PASS** for 600 PHP files. This is not PHP runtime lint or a Laravel/PHPUnit test. |
| `git diff --check` | **PASS** after the latest edits. |
| PHP lint and PHPUnit | **PASS** in CI run `36951383243` on PHP 8.2 and 8.3; the full configured suite uses SQLite and boots the app. Detailed test logs were unavailable, so exact current counts are not reported. |
| Composer validation/audit | **NOT TESTED** — Composer audit/validation is not part of the executed CI checks, and Composer is unavailable locally. |
| MySQL restore/concurrency, browser/manual, staging load/chaos, production recovery | **NOT TESTED** — no approved disposable/staging environment was available or accessed. |

## Coverage status by requested domain

- **Architecture/features:** Static review covered the Laravel route/controller/action/model boundaries and Vue/PWA feature surfaces identified in the audit plan. Automated backend feature tests passed in CI; the complete browser-to-production end-to-end experience was not exercised.
- **Database integrity:** SQLite-backed feature tests and test-database migrations passed in CI. MySQL behavior, supported upgrade/rollback paths, production locking, and off-host restore are **NOT TESTED**.
- **Authorization, validation, security:** Included policy, request-validation, login, push, and student-retention regression tests passed in CI. This does not cover every role/route or real deployment boundary. Self-service recovery/email delivery is absent; anonymization lacks a durable marker/guard pending the policy decision in DATA-002.
- **Race/idempotency/abuse:** Included SQLite tests for enrollment, push ownership/caps, retries, and selected actions passed in CI. Production-engine concurrency, load, and abuse verification are **NOT TESTED**.
- **Courses/enrollment:** Student eligibility, access capability, enrollment visibility/idempotency, cross-teacher authorization, and lesson-content regression tests passed in CI run `36951383243`. Manual browser validation remains **NOT TESTED**.
- **Exams/recovery/grading:** The server-authoritative deadline remains `MIN(attempt_started_at + duration_minutes, exam_window_end)`; starts at `now >= ends_at` must be rejected. Included snapshot, essay persistence/recovery, MCQ scoring, explicit regrade, autosubmit, exact-end, and integrity-resume tests passed in CI. Browser/manual and production-engine race checks remain **NOT TESTED**.
- **Make-ups/bulk operations:** The viewer-specific assignment query, selected-student grant, selected-record attempt action, and bounded selection behavior are represented in code/tests; included backend and frontend tests passed in CI. Full policy review and production-engine race tests remain **NOT TESTED**.
- **PWA:** Build and service-worker generation passed. Offline/reload/update behavior, protected-route caching, accessibility, RTL/IME, browser/device support, and manual exam recovery are **NOT TESTED**.
- **Performance:** Pagination guard passed locally and in CI. Build evidence shows a 416.70 kB raw / 139.05 kB gzip main JS chunk and 982.05 KiB PWA precache. No representative query profiling, production-sized dataset, API latency baseline, or staging load test was run (**NOT TESTED**).
- **Failures/chaos and observability:** Command failure signaling regression tests passed in CI. Queue/database/disk-full/network fault injection, alert delivery, runbook execution, and chaos/failover are **NOT TESTED**.
- **Dependencies/secrets:** npm audit is clean; Laravel remains unsupported/advisory-affected and Composer audit is **NOT TESTED**. `.env` is not tracked. Deployment secrets, TLS, proxy, debug, database, queue, scheduler, and storage configuration are **NOT TESTED**.
- **Regression coverage:** Frontend tests/build and the full configured backend PHPUnit jobs passed in CI run `36951383243` on PHP 8.2 and 8.3. Detailed log download failed with EOF; structured job conclusions were successful.

## Release gates

1. Upgrade Laravel to a supported, patched release and verify/remove advisory ignores after a clean Composer audit.
2. Preserve green PHP 8.2/8.3 full-suite CI and the pagination guard; run Composer validation/audit and verify migrations on supported upgrade paths. The current CI run `36951383243` passed PHP lint, PHPUnit, guards, and frontend jobs.
3. Complete disposable MySQL concurrency and encrypted off-host backup/restore drills with documented recovery objectives.
4. Complete approved browser/PWA, staging load, failure/chaos, alerting, and deployment-configuration checks.
5. Resolve the anonymization reactivation policy and confirm the student account-recovery/support process.
6. Keep this report and test plan current with exact CI evidence; reconsider release status only after every blocker above is resolved.

NOT READY FOR PRODUCTION
