# Phase 5 — Competitions & Leaderboard System: Final Report

## PHASE
Phase 5 — Competitions & Leaderboard. A separate competition domain layered on
top of the existing Examination System (Phases 1, 2, 3, 3.1, 4, 4.1). It lets a
teacher create a competition backed by an existing exam, students join and
participate, and produces a deterministic, frozen leaderboard. No Phase 6 work
was started.

> Competition results are derived **server-side** from the trusted exam attempt
> records. The client can never supply a score, percentage, rank, completion
> time or qualification.

## IMPLEMENTED
- Separate competition domain: `Competition` → `CompetitionParticipant` →
  `CompetitionResult`, with its own enums, actions, policies, resources and
  controllers (the `Exam` domain is not overloaded).
- Competition lifecycle `DRAFT → PUBLISHED → ACTIVE → ENDED → ARCHIVED`,
  enforced server-side via a state machine plus lazy scheduling.
- Registration with a unique `(competition_id, student_id)` constraint and
  server-side capacity enforcement under a transaction + row lock.
- Deterministic scoring (`highest_score` / `best_attempt`) and ranking
  (`score DESC, completion_time ASC, completed_at ASC, participant_id ASC`)
  with standard-competition tie handling.
- Paginated teacher/student leaderboards plus a student "my position" endpoint.
- Finalization that freezes the leaderboard once the competition ends (no cron
  required — lazy finalization on access plus an explicit teacher trigger).
- Anti-cheat integration: flagged attempts remain recorded and ranked, are shown
  to the teacher for review, and are only excluded when a teacher explicitly
  disqualifies the participant.
- Privacy-safe student identity on leaderboards via a public display name.

## COMPETITION DOMAIN
Models:
- `Competition` — `created_by`, `exam_id`, `title`, `description`, `status`,
  `starts_at`, `ends_at`, `max_participants`, `scoring_type`, `ranking_type`.
- `CompetitionParticipant` — `competition_id`, `student_id`, `joined_at`,
  `status`; unique `(competition_id, student_id)`.
- `CompetitionResult` — `competition_id`, `participant_id`, `attempt_id`,
  `score`, `percentage`, `completion_time`, `completed_at`, `rank`, `qualified`;
  unique `(competition_id, participant_id)`.

Enums: `CompetitionStatus`, `CompetitionScoringType`, `CompetitionRankingType`,
`CompetitionParticipantStatus`.

The competition references an exam via `exam_id` (RESTRICT on delete) as its
scoring source. It never duplicates the exam engine; it reuses the existing
`ExamAttempt` → result as the single source of truth.

## LIFECYCLE
```
DRAFT
  ↓
PUBLISHED
  ↓
ACTIVE
  ↓
ENDED
  ↓
ARCHIVED
```
- `DRAFT → PUBLISHED` requires a valid scheduling window
  (`starts_at` set, `ends_at > starts_at`).
- `PUBLISHED → ACTIVE` and `ACTIVE → ENDED` are derived from the time window and
  applied lazily when the competition is accessed or joined. Participation is
  accepted only while `starts_at <= now < ends_at`.
- `ENDED → ARCHIVED` is the ordinary archive path; `DRAFT`/`PUBLISHED` may also
  be retired to `ARCHIVED` (no participation yet). An `ACTIVE` competition must
  be ended first.
- Invalid moves (e.g. `ENDED → ACTIVE`, `ARCHIVED → ACTIVE`, `ARCHIVED → DRAFT`)
  are rejected server-side.

## REGISTRATION
- Server-side eligibility: authenticated student, competition active within its
  window, student enrolled in the linked exam's course.
- Unique `(competition_id, student_id)` prevents duplicate participation (also
  under concurrent requests).
- Capacity enforced under a transaction with a `lockForUpdate` on the competition
  row and a count check, so concurrent joins cannot exceed `max_participants`.
- `max_participants` is nullable (unlimited) and must be `> 0` when provided.
- Participant status is `registered` on join and only transitions to `completed`
  (or `disqualified` by a teacher) — students cannot change it.

## SCORING
`CompetitionScoringType`:
- `highest_score` — select the best attempt by **percentage** (tie-break: raw
  score, then earliest submission).
- `best_attempt` — select the best attempt by **raw score** (tie-break:
  percentage, then earliest submission).

`CalculateCompetitionScoreAction` picks exactly one submitted attempt per
participant. The competition result's `score`, `percentage`, `completion_time`
and `completed_at` are copied from that trusted attempt. A participant with no
submitted attempt has no result.

## RANKING ALGORITHM
```
score DESC
completion_time ASC
completed_at ASC
participant_id ASC
```
`RecalculateCompetitionLeaderboardAction`:
1. Syncs each participant's result from their submitted attempts.
2. Retrieves the valid (non-disqualified) results.
3. Orders them deterministically with the key above.
4. Persists the `rank`.

The final `participant_id` tie-breaker guarantees a stable, total ordering so
repeated recalculation always produces identical output (no randomness, no
reliance on DB default ordering).

## TIE HANDLING
Standard competition ranking on `score`:

```
100 → rank 1
100 → rank 1
95  → rank 3
```

Equal scores share a rank; the next distinct score is skipped. The tie-breakers
only determine the deterministic display order within an equal-ranked group — they
never change the rank number.

## LEADERBOARD
- `GET /student/competitions/{competition}/leaderboard`
- `GET /teacher/competitions/{competition}/leaderboard`
- `GET /student/competitions/{competition}/leaderboard/me`

Rows expose `rank`, `student_display_name`, `score`, `percentage`,
`completion_time`. Teacher rows additionally include `participant_id` and
`qualified`. The student view never receives emails, internal user ids,
participant ids, attempt ids, integrity details or moderation data.

Pagination via `?page=` / `?per_page=`; `per_page` is capped at 100.

## FINALIZATION
When `now >= ends_at` the competition transitions to `ended` and the leaderboard
is frozen (ranks persisted). This happens lazily when the competition is
accessed/joined and can also be forced by an authorized teacher via
`POST .../recalculate-leaderboard`. Recalculation is idempotent; no cron job is
required for correctness. Once `ended`, no new participation is accepted and the
snapshot is not silently recomputed on reads.

## ANTI-CHEAT INTEGRATION
A competition attempt uses the same Phase 4 integrity infrastructure. Rule
selected:

```
FLAGGED attempt
  ↓
Result remains recorded and ranked
  ↓
Teacher sees integrity_status for review
  ↓
Teacher may explicitly DISQUALIFY (excluded from ranking)
```

A flagged attempt is **never** automatically disqualified or accused. Only an
explicit teacher/admin enforcement excludes a participant.

## DISQUALIFICATION
`POST /teacher/competitions/{competition}/participants/{participant}/disqualify`
sets the participant status to `disqualified`, marks the result
`qualified=false` and clears its `rank`, and excludes the participant from
ranked positions. The historical result and review/audit data are preserved —
nothing is deleted.

## DATABASE CHANGES
New migrations (all `2026_01_01_00040x`):
- `000400_create_competitions_table` — competition config, `exam_id` RESTRICT FK,
  indexes on `(status, starts_at, ends_at)`, `created_by`, `exam_id`.
- `000401_create_competition_participants_table` — unique
  `(competition_id, student_id)`, indexes on `(competition_id, status)`,
  `student_id`.
- `000402_create_competition_results_table` — unique
  `(competition_id, participant_id)`, ranking indexes on
  `(competition_id, rank)` and
  `(competition_id, score, completion_time, completed_at, participant_id)`,
  plus `attempt_id`.

## API ENDPOINTS
Teacher (all require owning the competition / admin + `competitions.manage`):
- `GET/POST /teacher/competitions`
- `GET/PUT/DELETE /teacher/competitions/{competition}`
- `POST /teacher/competitions/{competition}/publish`
- `POST /teacher/competitions/{competition}/archive`
- `GET /teacher/competitions/{competition}/participants`
- `GET /teacher/competitions/{competition}/leaderboard`
- `POST /teacher/competitions/{competition}/recalculate-leaderboard`
- `POST /teacher/competitions/{competition}/participants/{participant}/disqualify`

Student:
- `GET /student/competitions`
- `GET /student/competitions/{competition}`
- `POST /student/competitions/{competition}/join`
- `GET /student/competitions/{competition}/leaderboard`
- `GET /student/competitions/{competition}/leaderboard/me`

Full request/response/validation documentation is in `docs/API.md`.

## AUTHORIZATION
- `CompetitionPolicy` scopes every teacher operation to owned competitions
  (`created_by`), with admin access via a `Gate::before` hook.
- Students cannot create/update/delete/publish/archive/recalculate/disqualify.
- Teacher A cannot view, edit, publish, archive, list participants/leaderboard,
  recalculate or disqualify any part of Teacher B's competition.
- A student may view/join/leaderboard only competitions they are eligible for;
  the `join` route requires enrollment in the linked exam's course and an active
  window.

## SECURITY
Audited for:
- **IDOR** — teacher↔teacher, student↔student and competition↔participant
  scopes are enforced (participant is verified against its competition).
- **Mass assignment** — only whitelisted `fillable` attributes; the update path
  strips non-editable config keys.
- **Client-controlled score/rank/percentage/completion_time/qualified** — no
  student endpoint accepts these; they are always derived server-side.
- **Client-controlled participant status** — never accepted from input.
- **Duplicate registration / duplicate results** — unique constraints.
- **Race conditions** — capacity uses a row lock + transaction; duplicates are
  caught by unique indexes.
- **Unauthorized leaderboard modification** — recalc/disqualify are teacher-only.

## CONCURRENCY
- Join: transaction + `lockForUpdate` on the competition row + count check +
  unique constraint (no duplicate/over-capacity).
- Result: `updateOrCreate` under the unique
  `(competition_id, participant_id)` constraint (no duplicate final results).
- Finalize / recalculate: idempotent; repeated runs produce identical ranks and
  do not corrupt state.

## TESTS
- `CompetitionManagementTest` — CRUD, ownership scoping, delete/archive guards.
- `CompetitionLifecycleTest` — linear lifecycle, scheduling promotion,
  finalization, invalid transitions, enum state machine.
- `CompetitionRegistrationTest` — join eligibility (window, enrollment),
  duplicate prevention, capacity enforcement.
- `CompetitionScoringRankingTest` — scoring types, server-derived values, ranking
  order, standard tie handling, recalculation determinism, no client-supplied
  score.
- `CompetitionLeaderboardTest` — student privacy (no email/internal ids), own
  position, disqualification exclusion with result preserved.
- `CompetitionAuthorizationTest` — student cannot manage, teacher IDOR,
  flagged-attempt review flow.
- `CompetitionFinalizationTest` — frozen leaderboard, idempotent finalization,
  no late participation after end.

## FULL TEST RESULT
⚠️ **PHP/Composer are not installed in this sandbox** (`php: command not found`),
so `php artisan test`, `migrate:fresh --seed`, and `route:list` could **not** be
executed here. Verification was done statically via a PHP AST parser:
- **Syntax:** 303 PHP files, **0 parse errors**
- **Refcheck:** 227 referenced `App/Tests` classes, **0 missing**

The new migrations (RESTRICT FK on `competitions.exam_id`, unique participant and
result constraints, leaderboard indexes) should be confirmed on a clean database
in a PHP environment. Existing Phase 1–4.1 tests should be re-run to confirm no
regressions.

## KNOWN LIMITATIONS
- The leaderboard recomputes results for all participants when read while the
  competition is `active` (no caching/premature infrastructure). Suitable for
  Phase 5 scale; a background sync can be added later.
- Results are materialized lazily and are part of the frozen snapshot once the
  competition ends; a late submission after `ends_at` is not retroactively added
  to an already-finalized leaderboard unless an authorized teacher explicitly
  recalculates.
- Status may show as `published` in a list until a competition is accessed/joined
  (scheduling is materialized lazily).
- Only `OPEN` entry is implemented; the model is structured so an
  `INVITE_ONLY` mode can be added later without rewriting the domain.
- `completion_time` is measured as `submitted_at - started_at` in seconds.

## NOT IMPLEMENTED
Invite-only entry, live/real-time leaderboards, caching, background jobs,
subscriptions, payments, AI, analytics dashboards, video streaming, social
features and chat — all intentionally excluded per the Phase 5 scope.

## NEXT PHASE
Phase 6 — not started, per instructions.
