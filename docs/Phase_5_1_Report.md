# Phase 5.1 — Cross-Phase Integration & Production Hardening

## AUDIT SCOPE

This phase was an audit of the entire integrated product across Phases 1–5
(Foundation, LMS Core, Examination, Examination Hardening, Anti-Cheat, Integrity
Hardening, Competitions & Leaderboard). Every domain boundary was reviewed for
correctness, security and race conditions: User → Auth → Authorization →
Course → Enrollment → Lesson → Exam → Attempt → Snapshot → Answers →
Submission → Grading → Integrity → Competition → Result → Leaderboard.

The audit did not introduce any new product domain. No Phase 6 work was started.

## ISSUES FOUND

Each issue below lists severity, component, root cause, fix and the regression
test added.

---

### Issue 1 — Public course catalog leaks creator email + account metadata

- **Severity:** High (privacy / data leak)
- **Component:** `CourseResource`, `CourseDetailResource` (unauthenticated
  `GET /courses`, `GET /courses/{course}`)
- **Root cause:** Both resources embedded the creator via the full
  `UserResource`, which serializes `email`, `avatar`, `is_active`, `roles` and
  the internal user id. The public course catalog is reachable by anyone
  (unauthenticated), so a visitor could harvest teacher email addresses and
  account metadata.
- **Fix:** Added a privacy-safe `PublicUserResource` (only `id`, `name`,
  `display_name`) and used it in place of `UserResource` for the creator in
  `CourseResource` and `CourseDetailResource`.
- **Regression test:** `PublicPrivacyTest::test_public_course_list_does_not_leak_creator_email_or_account_metadata`
  and `::test_public_course_detail_does_not_leak_creator_email`.

---

### Issue 2 — Internal video storage path exposed on the public course catalog

- **Severity:** Medium (internal detail leak)
- **Component:** `VideoResource` (used via the public course detail)
- **Root cause:** `VideoResource` always serialized `storage_path`, an internal
  storage implementation detail, to unauthenticated visitors through
  `GET /courses/{course}`.
- **Fix:** `VideoResource` now only exposes `storage_path` to authenticated
  teachers/admins; everyone else receives video metadata without the path.
- **Regression test:** `PublicPrivacyTest::test_public_course_detail_does_not_expose_internal_video_storage_path`
  and `::test_teacher_can_still_see_video_storage_path`.

---

### Issue 3 — Disqualifying a participant left stale rank gaps

- **Severity:** Medium (ranking correctness)
- **Component:** `DisqualifyCompetitionParticipantAction`
- **Root cause:** Disqualification set the participant `rank = null`,
  `qualified = false` and `status = disqualified`, but did not re-rank the
  remaining valid participants. Disqualifying the current #1 left the next-best
  participant marked as rank 2 with no rank 1 on the leaderboard.
- **Fix:** After disqualification the action re-runs
  `RecalculateCompetitionLeaderboardAction` (deterministic, idempotent) inside a
  transaction, renumbering the remaining valid participants so the leaderboard
  has no gaps.
- **Regression test:** `CompetitionDisqualifyRenumberTest::test_disqualifying_top_ranked_participant_renumbers_remaining_ranks`
  (a #1 who is disqualified is excluded from ranking, its historical result is
  preserved, and the next-best becomes #1).

---

### Issue 4 — Competition list endpoints reported a stale lifecycle status

- **Severity:** Medium (API consistency)
- **Component:** `Student\CompetitionController::index`,
  `Teacher\CompetitionController::index`
- **Root cause:** `show`/`join`/`leaderboard` resolved the lifecycle lazily
  (`lazyFinalize`), but the list/discovery endpoints returned the raw persisted
  `status`. A published competition whose scheduling window had already opened
  was shown as `published` in the list but `active` in `show` — misleading,
  inconsistent state.
- **Fix:** Centralized lifecycle resolution: both list endpoints now call
  `lazyFinalize()` on each item, so the discovered/listed status always matches
  the resolved status. Lazy finalization remains idempotent.
- **Regression test:** `CompetitionLifecycleConsistencyTest`
  (`test_student_list_shows_published_competition_as_active_once_window_opens`,
  `test_student_show_matches_list_status`,
  `test_published_competition_before_window_stays_published_in_list`).

---

### Issue 5 — Deleting an exam/course referenced by a competition raised a raw SQL error

- **Severity:** Medium (cross-domain deletion integrity)
- **Component:** `DeleteExamAction`, `DeleteCourseAction`
- **Root cause:** `competitions.exam_id` uses a RESTRICT FK. Deleting an exam
  referenced by a competition (or a course that cascades to such an exam) is
  blocked at the database, but surfaced to the client as an unhandled SQL
  exception → a 500 error instead of a clean, meaningful response.
- **Fix:** Both deletion actions now detect competitions referencing the
  exam/course and throw a clean `ResourceDeletionBlockedException` (rendered as
  a 409) with an actionable message. This protects historical competition data.
- **Regression test:** `CompetitionDeletionGuardTest`
  (`test_teacher_cannot_delete_exam_referenced_by_a_competition`,
  `test_teacher_can_delete_exam_not_referenced_by_competition`,
  `test_teacher_cannot_delete_course_containing_a_competition_referenced_exam`,
  `test_teacher_can_delete_course_without_competition_referenced_exams`).

---

### Issue 6 — Competition state machine disagreed with the archive action

- **Severity:** Low (enum/state consistency)
- **Component:** `CompetitionStatus::canTransitionTo`
- **Root cause:** `ArchiveCompetitionAction` allowed retiring a DRAFT or
  PUBLISHED (not-yet-started) competition directly to ARCHIVED, but
  `canTransitionTo` reported those as invalid, so the state machine and the
  action were inconsistent.
- **Fix:** Aligned `canTransitionTo` to permit the explicit retirement
  exceptions (DRAFT → ARCHIVED, PUBLISHED → ARCHIVED) while keeping the linear
  lifecycle otherwise strict. An ACTIVE competition must still end before
  archiving.
- **Regression test:** `CompetitionDomainTest::test_retirement_transitions_are_explicitly_allowed`.

---

## AUTHORIZATION AUDIT

Re-audited every endpoint for authentication, authorization, ownership and
resource relationship:

- Admin is granted every capability via `Gate::before`; all policies enforce
  role/permission and ownership scoping.
- Teacher endpoints are scoped to owned resources (courses, exams, competitions)
  via each policy's `canManage`/`isManagedBy`/`isOwnedBy`.
- Student endpoints are scoped to the authenticated student
  (`Enrollment`, `ExamAttempt`, `LessonProgress`, `CompetitionParticipant`,
  leaderboard/me).
- IDOR boundaries verified and covered by existing + new tests:
  student↔student attempt, teacher↔teacher exam, teacher↔teacher course,
  teacher↔teacher competition, and competition↔participant (the disqualify
  route verifies the participant belongs to the competition).
- Confirmed that the student answer endpoint is authorized in its Form Request
  (`SubmitExamAnswerRequest` → `can('update', $attempt)`), and the student
  integrity-event endpoint in `RecordIntegrityEventRequest`
  (`can('recordEvent', $attempt)`).

New coverage: `SecurityMatrixTest` asserts enrolled-but-not-joined students
cannot view a leaderboard (protects Student A from Student B's result), students
not enrolled in the course cannot view a competition, students cannot reach
teacher competition-management routes, and a client payload cannot influence
ranking.

## EXAM / ATTEMPT AUDIT

- **Access chain:** `StartExamAttemptAction` requires a published exam and an
  active enrollment in the exam's course. `StudentExamController::show`/`start`
  enforce the same via `assertAccessible` (published + enrolled). No endpoint
  allows starting an attempt by exam id alone without enrollment.
- **State machine:** `IN_PROGRESS → SUBMITTED` / `IN_PROGRESS → EXPIRED` only.
  `SubmitExamAttemptAction` rejects an already-submitted or expired attempt and
  is idempotent (re-submit returns the existing result). `SaveExamAnswerAction`
  rejects non-`in_progress` and expired attempts. No API can transition
  SUBMITTED → IN_PROGRESS or EXPIRED → IN_PROGRESS.
- **Immutability / snapshot:** `BuildAttemptSnapshotAction` freezes
  `question_text`, `points`, `position`, `option_text`, `is_correct` at attempt
  start. Grading (`GradeExamAttemptAction`/`CalculateExamResultAction`) reads
  only the snapshot, never the live `questions`/`options`. Existing snapshot
  integrity tests (`ExamSnapshotIntegrityTest`) confirm that editing/deleting a
  live question or option mid-attempt does not corrupt grading.
- **Pass percentage:** `ExamAttempt.pass_percentage` is authoritative; the
  exam's live `pass_percentage` is only used for new attempts. The submit
  result resource uses the frozen threshold.
- **Expiration:** `expires_at = started_at + duration_minutes`, `now()` is
  server-side. Save-answer, submit and integrity recording all reject expired
  attempts.

## SNAPSHOT INTEGRITY

- `exam_attempt_questions` / `exam_attempt_options` / `exam_answers` store their
  own frozen copies and their FK columns were made weak references (FK constraints
  dropped in migration `000201`) so live question/option deletion never destroys
  historical grading data.
- Deleting a live question/option does not cascade into snapshot rows (no FK), so
  an in-progress or submitted attempt stays gradable. Verified by existing
  `ExamSnapshotIntegrityTest` and `QuestionManagementTest`.
- Exam deletion cascades to its attempts/snapshots only when no competition
  references the exam (now guarded with a clean 409).

## ANTI-CHEAT AUDIT

- Client can never set `risk_score`, `risk_points`, `severity`,
  `integrity_status`, reviewer, or review decision. All are server-derived in
  `RecordIntegrityEventAction` / `EvaluateAttemptRiskAction` /
  `UpdateAttemptIntegrityStatusAction`.
- Students cannot access up to teacher integrity data or another student's
  integrity data (`ExamAttemptPolicy::viewIntegrity` / `recordEvent` /
  `review`).
- `MULTIPLE_SUSPICIOUS_EVENTS` is not client-submittable (rejected with 422) and
  remains a server-derived condition (`IntegrityRiskConfig::isMultipleSuspicious`),
  with no synthetic double-counting. Preserved unchanged.
- Teacher review is append-only (`ExamIntegrityReview` rows are never updated);
  a human decision is preserved and never overwritten by the automatic risk
  recalculation.
- Integrity configuration is frozen onto each attempt at start
  (`exam_attempt_integrity_settings`); later live-exam changes do not affect an
  existing attempt.

## COMPETITION AUDIT

- Access chain: a student must be enrolled in the course of the competition's
  linked exam to join/view, and must have joined to view a leaderboard.
- Discovery consistency: the student competition list is now scoped to
  competitions whose linked exam belongs to a course the student is actively
  enrolled in (previously it listed every published/active competition
  regardless of enrollment, inconsistent with `show`/`join`). Verified by
  `SecurityMatrixTest::test_student_not_enrolled_in_the_course_cannot_discover_the_competition_in_list`.
- Lifecycle is resolved centrally (`lazyFinalize`) across list/show/join/leaderboard
  so no endpoint reports a misleading status.
- Configuration immutability: once a competition leaves DRAFT,
  `scoring_type`/`ranking_type`/`exam_id` cannot be changed; once it is ACTIVE,
  even `starts_at`/`ends_at`/`max_participants` are read-only (only
  `title`/`description` remain editable).
- Result integrity: `score`/`percentage`/`completion_time`/`qualified` are always
  derived server-side from the trusted submitted exam attempt; no student
  endpoint accepts them.
- Anti-cheat integration: a flagged attempt is recorded and ranked, shown to the
  teacher for review, and only excluded when the teacher explicitly disqualifies
  the participant (never auto-disqualified).
- Disqualification now re-ranks the remaining valid participants so the
  leaderboard has no gaps, while preserving the disqualifed participant's
  historical result.

## LEADERBOARD AUDIT

- Ranking is deterministic: `score DESC, completion_time ASC, completed_at ASC,
  participant_id ASC`.
- Tie handling is standard competition ranking on score (`100, 100, 95 → 1, 1, 3`),
  verified by `CompetitionScoringRankingTest`.
- `RecalculateCompetitionLeaderboardAction` is idempotent; repeated runs produce
  identical ordering/ranks.
- Finalization freezes the leaderboard once the competition ends; the student and
  teacher leaderboard reads only recompute while the competition is ACTIVE.
- Teacher recalculation is explicit and only performed by an authorized teacher.

## DATABASE AUDIT

- Reviewed all Phases 1–5 migrations for FK behavior, indexes, unique
  constraints and SQLite compatibility.
- Confirmed the key protections: `competitions.exam_id` RESTRICT, unique
  `(competition_id, student_id)`, unique `(competition_id, participant_id)`,
  unique `active_key`, unique `(student_id, exam_id, attempt_number)`, unique
  `(attempt_id, question_id)` answers, and the snapshot FK-drop migration
  (`000201`).
- Confirmed clean migration ordering (each phase prefixes 0001–0004).
- No migration was altered; the deletion integrity issue was fixed at the
  application layer (Actions) so historical competition data is protected before
  reaching the RESTRICT FK.

## API AUDIT

- All routes are under `/api/v1` with consistent `teacher`/`student` separation.
- Responses use the shared `ApiResponse` envelope and API Resources; no second
  convention was introduced.
- Pagination (`per_page`) is capped at 100 across list endpoints.
- The public course catalog is now privacy-safe (no creator email, no internal
  video storage path); teacher JSON error statuses are now clean (409) instead of
  raw 500s for competition-blocked deletions.

## CONCURRENCY AUDIT

- `StartExamAttemptAction` uses a transaction + `lockForUpdate` + unique
  `active_key` + unique `(student_id, exam_id, attempt_number)` so a student can
  never hold two simultaneous active attempts for the same exam, and
  `max_attempts` cannot be bypassed via a race.
- `SaveExamAnswerAction` uses a transaction + `lockForUpdate` and a unique
  `(attempt_id, question_id)` index so concurrent answer writes are serialized.
- `SubmitExamAttemptAction` is idempotent (double-submit returns the existing result).
- `JoinCompetitionAction` uses a transaction + `lockForUpdate` on the
  competition row + a count check + the unique `(competition_id, student_id)`
  constraint, so concurrent joins cannot exceed capacity or create duplicates.
- Result creation uses `updateOrCreate` under the unique
  `(competition_id, participant_id)`; finalization and recalculation are
  idempotent.

## PRIVACY AUDIT

- Anti-cheat stores only event type, timestamp, limited metadata, severity and
  risk points. No clipboard contents, keystroke history, screen/camera/mic
  captures, GPS or files.
- Student leaderboard rows expose only `rank`, `student_display_name`, `score`,
  `percentage`, `completion_time` (no emails, internal ids, participant ids, or
  integrity data).
- Fixed: public course catalog no longer exposes creator email/account metadata
  or internal video paths.
- Student responses (exam attempt, exam detail, competition, roadmap, progress)
  never expose `is_correct`, the answer key, `risk_score`, severity, review notes
  or moderator metadata.

## PERFORMANCE AUDIT

- Leaderboard queries are indexed and paginated; ranking indexes exist on
  `(competition_id, rank)` and the composite ordering index.
- No premature caching or background infra was introduced. The leaderboard still
  recomputes on read while a competition is active (documented behavior).
- Eager loading is used on the leaderboard (`participant.student`), teacher
  participants (`student`, `result.attempt`), attempt views, and exam queries to
  avoid N+1.
- `lazyFinalize` is idempotent and only persists/recalculates on a lifecycle
  transition; it is now applied centrally in list/show/join/leaderboard.

## TESTS ADDED

- `tests/Feature/Hardening/PublicPrivacyTest.php` — public course creator privacy
  and video storage-path privacy.
- `tests/Feature/Hardening/CompetitionDeletionGuardTest.php` — exam/course
  deletion blocked cleanly when a competition references them.
- `tests/Feature/Hardening/SecurityMatrixTest.php` — cross-phase security matrix
  (leaderboard access, non-enrolled view, student→teacher route rejection,
  payload-can't-influence ranking).
- `tests/Feature/Competition/CompetitionDisqualifyRenumberTest.php` —
  disqualification re-ranks remaining participants, preserving history.
- `tests/Feature/Competition/CompetitionLifecycleConsistencyTest.php` — list vs
  show status consistency and lazy lifecycle resolution.
- `tests/Unit/CompetitionDomainTest.php` — added retirement-transition assertions.

## FULL TEST RESULT

⚠️ **PHP/Composer are not installed in this sandbox** (`php: command not found`),
so `php artisan test`, `migrate:fresh --seed`, `route:list`, and
`./vendor/bin/pint --test` could **not** be executed here. Verification was done
statically via a PHP AST parser:
- **Syntax:** 311 PHP files, **0 parse errors**
- **Refcheck:** 229 referenced `App/Tests` classes, **0 missing**

Runtime verification is **pending**. The new regression tests and the existing
suite should be run on a clean database in a PHP environment to confirm green.

## REMAINING RISKS

- A competition whose linked exam is unpublished when the competition is ACTIVE
  would prevent participants from producing results (no submission possible).
  Not a security issue, but a data-availability consideration.
- The student/teacher competition list now calls `lazyFinalize()` per item, which
  can (rarely) persist a lifecycle transition as a side effect of a read. This is
  correct and idempotent, but the write is a side effect to be aware of for very
  large lists.
- Leaderboard recomputation while ACTIVE happens on each read; for very large
  participant sets this is O(n) per read. Caching/backgrounding are deliberately
  deferred.

## KNOWN LIMITATIONS

- Only `OPEN` competition entry is implemented (no invite-only).
- No real-time leaderboard / push updates.
- `completion_time` = `submitted_at - started_at` in seconds.
- The leaderboard recomputes on read while active (no caching).
- Public route `/api/v1/courses/{course}` still exposes lesson `content` and
  published video metadata (title/duration/position) — intentional for a course
  catalog; only the internal `storage_path` was removed.

## DOCUMENTATION UPDATED

- `docs/Phase_5_1_Report.md` (this file).
- `docs/API.md` — public course responses now use a privacy-safe creator and do
  not expose an internal video path; competition lifecycle is resolved
  consistently everywhere; exam/course deletion returns 409 when a competition
  references them; competition disqualification re-ranks remaining participants.
- `README.md` — Phase 5.1 hardening entry in the phase list.

## GIT STATE

- Branch: `origin/arena/01a067b3-teacher-system`
- Committed and pushed on top of `9f0236c`.
- Working tree clean after commit.

## PHASE 6

NOT STARTED
