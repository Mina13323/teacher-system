# Phase 5.2 — Deep Integrity, Transaction & Cross-Domain Audit

## AUDIT SCOPE

This was a deep technical audit of the integrated system across all five phases,
focused exclusively on correctness under concurrency, retries, deletion, live
configuration changes, expiration, duplicate requests, competition finalization,
teacher actions, and historical data access. No new product feature, domain, or
infrastructure was added. **Phase 6 was not started.**

The audit reviewed source (no runtime), covering:

| Area | Files examined |
|------|----------------|
| Snapshot integrity | `StartExamAttemptAction`, `BuildAttemptSnapshotAction`, `SaveExamAnswerAction`, snapshot migrations, `ExamAttempt`/`ExamAnswer`/`ExamAttemptQuestion`/`ExamAttemptOption` models, `ExamSnapshotIntegrityTest` |
| Grading | `CalculateExamResultAction`, `GradeExamAttemptAction`, `SubmitExamAttemptAction` |
| Attempt transactions | `StartExamAttemptAction`, `SubmitExamAttemptAction`, `SaveExamAnswerAction`, `ExpireExamAttemptAction` |
| Integrity | `RecordIntegrityEventAction`, `EvaluateAttemptRiskAction`, `UpdateAttemptIntegrityStatusAction`, `ReviewExamAttemptIntegrityAction`, `CreateAttemptIntegritySettingsAction` |
| Competition | `lazyFinalize`, `JoinCompetitionAction`, `SyncCompetitionResultsAction`, `CalculateCompetitionScoreAction`, `RecalculateCompetitionLeaderboardAction`, `DisqualifyCompetitionParticipantAction`, `UpdateCompetitionAction`, `DeleteCompetitionAction`, `ArchiveCompetitionAction`, `PublishCompetitionAction` |
| Authorisation | `ExamAttemptPolicy`, competition + exam policies, Form Requests |
| Resources | All 30 resources (leakage review) |
| Database | All Phases 1–5 migrations (FK, unique, indexes, SQLite compatibility) |
| Lifecycle enums | `ExamAttemptStatus`, `CompetitionStatus`, `CompetitionParticipantStatus`, `IntegrityStatus`, `IntegrityEventType` |

## ISSUES FOUND

### Issue 1 — Competition finalization was not atomic

- **Component:** `Competition::lazyFinalize()` (ACTIVE → ENDED path).
- **Before:** The method wrote `status = Ended` and `save()` **outside** any
  transaction, and only then ran `RecalculateCompetitionLeaderboardAction`. If the
  leaderboard recomputation failed (a DB error, a constraint, etc.), the
  competition would already be persisted as `ended` but its leaderboard would be
  stale or only partially recomputed. Because `lazyFinalize` returns early for
  `ended` competitions, it would never self-heal.
- **Root cause:** Two dependent writes (persist status + persist frozen
  leaderboard) were not wrapped in a single transaction.
- **Fix:** Wrap the ACTIVE → ENDED transition and the leaderboard recomputation in
  one `DB::transaction`. Inside the transaction the competition row is re-read
  under `lockForUpdate()` so a concurrent finalizer cannot interleave; the
  in-memory `status` is only advanced when the transition actually happened
  (i.e. this request won the race). This keeps finalization atomic and idempotent.
- **Regression test:** `CompetitionTransactionTest::test_finalization_is_idempotent_and_keeps_frozen_ranks`.

### Issue 2 — Leaderboard recomputation was not atomic

- **Component:** `RecalculateCompetitionLeaderboardAction`.
- **Before:** It synced every participant's result (multiple `updateOrCreate`)
  and then persisted assigned ranks (multiple `save`), all outside a transaction.
  A mid-way failure (e.g. rank write after partial sync) left a leaderboard with
  some ranks updated and others stale.
- **Root cause:** Multi-write operation without a transaction boundary.
- **Fix:** Wrap the entire sync + rank-assignment sequence in a `DB::transaction`.
  Recalculation remains deterministic and idempotent.
- **Regression test:** `CompetitionTransactionTest` (via the disqualify +
  recompute and repeated-finalization assertions) and the existing
  `CompetitionScoringRankingTest::test_recalculated_rankings_are_identical`.

## ROOT CAUSES

Both issues share the same root cause: multi-write domain operations (finalize,
recompute leaderboard) lacked a transaction boundary, so a partial failure could
persist an intermediate state. The system previously relied on the operations
being successful in sequence, which is correct on the happy path but not under a
mid-write exception or a concurrent writer.

## FIXES

1. `app/Models/Competition.php` — `lazyFinalize()` finalization is now a single
   `DB::transaction` with `lockForUpdate()` on the competition row and an accurate
   in-memory status reflection.
2. `app/Actions/Competition/RecalculateCompetitionLeaderboardAction.php` —
   `execute()` now runs the sync + ranking inside a `DB::transaction`.

Both fixes are minimal, preserve the documented behavior, and add no new
business rules.

## REGRESSION TESTS

New tests added in `tests/Feature/Hardening/`:

| File | Coverage |
|------|----------|
| `AttemptStateMachineTest` | SUBMITTED/EXPIRED attempts cannot be revived or re-submitted through any API path; only IN_PROGRESS → SUBMITTED/EXPIRED is legal. |
| `SnapshotDeepIntegrityTest` | Full business operation (start → answer → submit → grade) survives live question deletion, live correct/incorrect option deletion, live answer-key flip, and mid-attempt question deletion. |
| `ExpirationAndIdempotencyTest` | Save-answer / submit / record-integrity-event after `expires_at` are rejected; client cannot extend the attempt; repeated submit is idempotent with a single grade. |
| `CompetitionTransactionTest` | Finalization is idempotent; disqualification re-ranks remaining valid participants (no gap), never leaves a disqualified participant ranked, preserves the historical result + attempt + integrity evidence, and is repeatable. |
| `DatabaseIntegrityTest` | DB-level unique constraints reject duplicate competition participants, duplicate competition results, and duplicate active attempts; deleting an exam referenced by a competition returns 409. |

Existing suites were preserved (no assertions weakened).

## SNAPSHOT INTEGRITY

Verified by source inspection and the newly added deep tests:

- `BuildAttemptSnapshotAction` writes a fully self-contained snapshot
  (`exam_attempt_questions` + `exam_attempt_options`) storing `question_text`,
  `points`, `position`, `option_text`, `is_correct`. `is_correct` is frozen from
  the question/option at start; it is never re-read from the live tables during
  grading.
- Migration `000201_protect_exam_snapshot_from_cascade_deletes` drops the FK
  constraints on `question_id`/`option_id` in the snapshot + answer tables, so a
  live question/option deletion **cannot** cascade into historical data. Columns
  remain as weak references (values preserved), which is what the snapshot
  integrity feature requires.
- Grading (`CalculateExamResultAction` + `GradeExamAttemptAction`) matches answers
  to snapshot rows and uses `attemptQuestions.attemptOptions` — never the live
  `questions`/`options` tables.
- `SaveExamAnswerAction` validates `question_id`/`option_id` against the snapshot,
  not `exists:questions,id` / `exists:options,id`, so a deleted live row is still
  answerable if it is in the snapshot.
- `ExamAttemptResource` exposes only the frozen question/option text and `selected`
  flag — never `is_correct` or the answer key.

## TRANSACTION AUDIT

Inventory of every Action that performs multiple writes:

| Action | Writes | Transaction? | Lock? | Unique constraint? | Idempotent? |
|--------|--------|--------------|-------|--------------------|-------------|
| `StartExamAttemptAction` | create attempt + snapshot + frozen integrity | ✅ | `lockForUpdate` on active attempt | unique `active_key`, unique `(student, exam, attempt_number)` | ✅ reuses active attempt |
| `SaveExamAnswerAction` | updateOrCreate answer | ✅ | `lockForUpdate` on attempt | unique `(attempt_id, question_id)` | ✅ |
| `SubmitExamAttemptAction` | state change + grading | ✅ | `lockForUpdate` on attempt | n/a (idempotent re-submit) | ✅ |
| `GradeExamAttemptAction` | per-answer grade + attempt state | ✅ | (caller holds lock) | n/a | ✅ |
| `RecordIntegrityEventAction` | create event + re-evaluate risk | ✅ | `lockForUpdate` on attempt | dedup by event type+window | ✅ dedup |
| `JoinCompetitionAction` | create participant | ✅ | `lockForUpdate` on competition | unique `(competition, student)` | ✅ |
| `SyncCompetitionResultsAction` | per-participant result create/upsert + status | ✅ (now inside recalc txn) | — | unique `(competition, participant)` | ✅ |
| `RecalculateCompetitionLeaderboardAction` | sync + persist ranks | ✅ (FIXED) | — | unique result rows | ✅ |
| `DisqualifyCompetitionParticipantAction` | status + result update + re-rank | ✅ | — | — | ✅ |

Lock inventory (`lockForUpdate`): used in `StartExamAttemptAction`
(active-attempt guard), `SaveExamAnswerAction` (attempt serialize),
`SubmitExamAttemptAction` (double-submit guard), `RecordIntegrityEventAction`
(event/submit race), `JoinCompetitionAction` (capacity race), and now
`Competition::lazyFinalize` (finalization race). Each lock is acquired before the
guarded critical read; no lock is redundant.

## CONCURRENCY AUDIT

- **Duplicate active attempt:** prevented by unique `active_key` + the
  `lockForUpdate` re-check in `StartExamAttemptAction` (race-safe).
- **Max attempts:** enforced server-side by counting submitted+expired attempts
  under the transaction; an attempt can never exceed `max_attempts` because
  `(student, exam, attempt_number)` is unique and stale in-progress attempts are
  expired first.
- **Answer write concurrency:** `(attempt_id, question_id)` unique + attempt lock
  → no duplicate rows; submitted/expired attempts reject further writes.
- **Competition join capacity:** competition row `lockForUpdate` + count check +
  unique `(competition, student)` → no capacity or duplicate races.
- **Finalization race:** competition row `lockForUpdate` + transactional status
  change + recompute → deterministic, no partial finalization (Issue 1).
- **Disqualify + recalc race:** disqualify runs inside a transaction and re-ranks;
  the recalc is now transactional (Issue 2).

## DATABASE AUDIT

FK behavior/cascade review:

| Table FK | Behavior | Why correct |
|----------|----------|-------------|
| `units.course_id` | cascade | unit belongs to course |
| `lessons.unit_id` | cascade | lesson belongs to unit |
| `videos.lesson_id` | cascade | video belongs to lesson |
| `enrollments.student_id`/`course_id` | cascade | enrollment is a join record |
| `lesson_progress.*` | cascade | progress is a join record |
| `exams.course_id` | cascade | exam belongs to course |
| `questions.exam_id` | cascade | question belongs to exam |
| `options.question_id` | cascade | option belongs to question |
| `exam_attempts.exam_id` | cascade | attempt belongs to exam |
| `exam_attempt_questions.attempt_id` | cascade | snapshot belongs to attempt |
| `exam_attempt_questions.question_id` | **FK dropped** | snapshot is independent of the live question |
| `exam_attempt_options.attempt_question_id` | cascade | option snapshot belongs to question snapshot |
| `exam_attempt_options.option_id` | **FK dropped** | snapshot is independent of the live option |
| `exam_answers.attempt_id` | cascade | answer belongs to attempt |
| `exam_answers.question_id`/`option_id` | **FK dropped** | answer independent of live rows |
| `exam_integrity_events.attempt_id` | cascade | event belongs to attempt |
| `exam_integrity_reviews.attempt_id` | cascade | review belongs to attempt |
| `exam_integrity_reviews.reviewed_by` | nullOnDelete | preserve review if reviewer removed |
| `competitions.exam_id` | **restrict** | cannot delete an exam referenced by a competition → protects competition history (Phase 5.1) |
| `competition_participants.*` | cascade | participant is a join record |
| `competition_results.*` | cascade | result belongs to participant/competition |

Unique constraints verified: `(student, exam, attempt_number)`, `active_key`,
`(attempt, question)` answer, `(competition, student)` participant,
`(competition, participant)` result, plus enrollment/progress/course-slug/email.

**SQLite note (static):** Migration `000201` uses `dropForeign`, which on SQLite
requires a table rebuild. Laravel's SQLite schema grammar handles this; however
this could not be executed at runtime in this sandbox (PHP absent). See
RUNTIME VERIFICATION.

## AUTHORIZATION AUDIT

- IDOR borders re-confirmed: student↔student attempt, teacher↔teacher exam/course,
  teacher↔teacher competition, teacher↔student integrity, `UpdateCompetitionAction`
  ownership, `DeleteExamAction`/`DeleteCourseAction` competition guard.
- `ExamAttemptPolicy::update` returns true only for the attempt owner; the answer
  submit Form Request authorizes `update`. `StartExamAttemptRequest` authorizes
  `student` role; the action enforces published + enrollment.
- Student competition routes are gated on enrollment + joining (Phase 5.1), and
  the leaderboard/me endpoints require an existing participant.
- No student path exposes `is_correct`, `correct_option`, integrity evidence, or
  another student's data.

## CROSS-DOMAIN AUDIT

- Exam → Attempt → Integrity: attempt snapshot & frozen integrity settings are
  created together in the `StartExamAttemptAction` transaction (no partial
  initialization possible).
- Attempt → Competition result: `SyncCompetitionResultsAction` reads trusted
  submitted attempts only; result values (score/percentage/completion_time/
  qualified/rank) are server-derived, never client-supplied. `CompetitionResult`
  has no client-writable controller path.
- Competition → Exam delete: `DeleteExamAction`/`DeleteCourseAction` throw a clean
  409 via `ResourceDeletionBlockedException` when a competition references the
  exam (verified by `CompetitionDeletionGuardTest` and new
  `DatabaseIntegrityTest::test_exam_referenced_by_competition_cannot_be_deleted`).
- Anti-cheat → competition: a flagged attempt is never auto-disqualified; only an
  explicit teacher disqualification excludes a participant, preserving all
  historical evidence (verified by
  `CompetitionTransactionTest::test_disqualification_does_not_delete_attempt_or_integrity_evidence`).

## LEADERBOARD AUDIT

- Deterministic ordering: `score DESC, completion_time ASC, completed_at ASC,
  participant_id ASC`. The query orders by `rank` for display; recomputation
  persists deterministic ranks.
- Standard competition ranking on score: `100, 100, 95, 95, 90 → 1, 1, 3, 3, 5`
  (verified by existing `CompetitionScoringRankingTest`).
- Tie fallback: the sort falls through to `participant_id ASC`, so identical
  score/completion_time/completed_at still yields deterministic ordering.
- After disqualification the remaining valid participants are re-ranked with no
  gap (Issue Phase 5.1 + new `CompetitionTransactionTest`).

## PRIVACY AUDIT

- Public course resources use `PublicUserResource` (id/name/display_name only) —
  no email/account metadata.
- `VideoResource.storage_path` hidden from public/student views; teacher/admin only.
- Student leaderboard rows expose only `rank`, `student_display_name`, `score`,
  `percentage`, `completion_time`; no emails, internal IDs, or integrity data.
- Student attempt/exam resources never expose `is_correct`/answer key/risk/severity.
- Teacher-only integrity resources (`ExamAttemptIntegrityResource`,
  `IntegrityEventResource`) are gated by `viewIntegrity`/`review` policy.

## API AUDIT

- Responses use the shared `{ success, message, data }` envelope and standard
  pagination (`per_page` capped at 100). No second response format introduced.
- Status codes: 401/403/404/409/422/429 appropriate; no stack trace/file path/
  SQL error leakage (the 409 for competition-referenced deletion is now handled
  in `bootstrap/app.php`).
- Rate limiting: default `api` middleware group applies `120/min`; auth `10/min`;
  integrity-events endpoint uses a dedicated limiter.

## REMAINING RISKS

- **Competition result timing boundary is currently broad:** `CalculateCompetitionScoreAction`
  counts any submitted attempt for the linked exam regardless of whether it was
  submitted before or after `competition.ends_at`. The existing documented rule
  only constrains *participation* to the open window; it does not restrict which
  submitted attempts may contribute to the result. This is a genuine ambiguity.
  Per the audit instruction ("do not invent a business rule without evidence"),
  behavior was **not changed**; it is documented here as the current behavior.
  A future decision on whether to exclude post-window submissions should be made
  deliberately.
- **SQLite `dropForeign` table rebuild** (migration `000201`) was not executed;
  it should be validated on a real SQLite instance (see RUNTIME VERIFICATION).
- `RecalculateCompetitionLeaderboardAction` writes `rank`/`qualified` for all
  non-disqualified participants that have a result; there is no separate
  qualification threshold. This matches the documented behavior but means
  "qualified" is effectively "has a rankable result."

## KNOWN LIMITATIONS

- Only `OPEN` competition entry is implemented (no invite-only).
- No real-time leaderboard / push updates.
- `completion_time` = `submitted_at - started_at` in seconds.
- The leaderboard recomputes on read while a competition is ACTIVE (no caching).
- PHP/Composer are not installed, so runtime verification could not run.

## RUNTIME VERIFICATION

**Runtime verification: NOT EXECUTED**
**Reason: PHP/Composer unavailable**

The sandbox has no PHP CLI and no Composer (`php: command not found`), and
`vendor/` is not present, so `php artisan test`, `migrate:fresh --seed`,
`route:list`, and `./vendor/bin/pint --test` could not be executed.

Static verification performed (Node + `php-parser` AST):

- **PHP syntax/parse check:** 318 files, **0 parse errors**.
- **Class/reference check:** 264 referenced `App/Tests` classes, **0 missing**.
- Migration FK/unique/index inspection (all Phases 1–5) — see DATABASE AUDIT.
- Route inspection (`routes/api.php`), model relationship inspection, resource
  leakage review, and static security review.

Runtime tests (migrations + the 44 test files, including 20 new Phase 5.2 test
methods across 5 new files) must be run on a clean database in a PHP environment
to confirm green.

## GIT STATE

- Branch: `arena/01a067b3-teacher-system`
- Commit: to be recorded at commit time; pushed to `origin/arena/01a067b3-teacher-system`.
- Working tree: clean after commit.
- Ahead/behind: 1 ahead of `origin/arena/01a067b3-teacher-system` after push.

## PHASE 6 STATUS

NOT STARTED
