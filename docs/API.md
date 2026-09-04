# API Reference — v1

Base URL: `/api/v1`

All responses use a consistent envelope:

```json
{ "success": true, "message": "...", "data": { } }
```

Errors:

```json
{ "success": false, "message": "...", "errors": { } }
```

## Authentication

| Method | URL                        | Auth            | Role/Permission | Request body | Response          |
|--------|----------------------------|-----------------|-----------------|--------------|-------------------|
| POST   | `/auth/register`           | —               | —               | `name, email, password, password_confirmation` | `{ user, token }` |
| POST   | `/auth/login`              | —               | —               | `email, password` | `{ user, token }` |
| GET    | `/auth/me`                 | Bearer token    | authenticated   | —            | `{ user }`        |
| POST   | `/auth/logout`             | Bearer token    | authenticated   | —            | —                 |

---

## Public course discovery

Only **published** courses are returned to unauthenticated clients. Unpublished
lessons/videos are never exposed here.

| Method | URL                   | Auth | Role/Permission | Request body | Response        |
|--------|-----------------------|------|-----------------|--------------|-----------------|
| GET    | `/courses`            | —    | —               | —            | `CourseResource[]` |
| GET    | `/courses/{course}`   | —    | —               | —            | `CourseDetailResource` |

---

## Teacher / Admin — Courses

| Method | URL                                   | Auth | Role/Permission | Request body | Response |
|--------|---------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/dashboard`                  | Bearer | `teacher`/`admin` | —          | teacher dashboard |
| GET    | `/teacher/courses`                    | Bearer | `teacher`/`admin` | —          | `CourseResource[]` |
| POST   | `/teacher/courses`                    | Bearer | `courses.create` | `title, slug?, description?, thumbnail?, status?` | `CourseResource` |
| GET    | `/teacher/courses/{course}`           | Bearer | owns course / admin | —          | `CourseDetailResource` |
| PUT    | `/teacher/courses/{course}`           | Bearer | owns course + `courses.update` | `title?, slug?, description?, thumbnail?, status?` | `CourseResource` |
| PATCH  | `/teacher/courses/{course}/publish`   | Bearer | owns course + `courses.update` | —          | `CourseResource` |
| PATCH  | `/teacher/courses/{course}/unpublish` | Bearer | owns course + `courses.update` | —          | `CourseResource` |
| DELETE | `/teacher/courses/{course}`           | Bearer | owns course + `courses.delete` | —          | — |

**Errors:** 401 unauthenticated, 403 not owner, 404 not found.

---

## Teacher / Admin — Units

| Method | URL                                          | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/courses/{course}/units`            | Bearer | owns course / admin | —          | `UnitResource[]` |
| POST   | `/teacher/courses/{course}/units`            | Bearer | owns course + `courses.update` | `title, description?, position?` | `UnitResource` |
| PUT    | `/teacher/courses/{course}/units/reorder`    | Bearer | owns course + `courses.update` | `ordered_ids[]` | — |
| GET    | `/teacher/units/{unit}`                      | Bearer | owns course / admin | —          | `UnitDetailResource` |
| PUT    | `/teacher/units/{unit}`                      | Bearer | owns course + `courses.update` | `title?, description?, position?` | `UnitResource` |
| DELETE | `/teacher/units/{unit}`                      | Bearer | owns course + `courses.delete` | —          | — |

**Validation:** `title` required (max 255); `position` integer ≥ 0.

---

## Teacher / Admin — Lessons

| Method | URL                                          | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/units/{unit}/lessons`              | Bearer | owns course / admin | —          | `LessonResource[]` |
| POST   | `/teacher/units/{unit}/lessons`              | Bearer | owns course + `lessons.update` | `title, slug?, description?, content?, position?, is_published?` | `LessonResource` |
| PUT    | `/teacher/units/{unit}/lessons/reorder`      | Bearer | owns course + `lessons.update` | `ordered_ids[]` | — |
| GET    | `/teacher/lessons/{lesson}`                  | Bearer | owns course / admin | —          | `LessonDetailResource` |
| PUT    | `/teacher/lessons/{lesson}`                  | Bearer | owns course + `lessons.update` | `title?, slug?, description?, content?, position?, is_published?` | `LessonDetailResource` |
| PATCH  | `/teacher/lessons/{lesson}/publish`          | Bearer | owns course + `lessons.update` | —          | `LessonResource` |
| PATCH  | `/teacher/lessons/{lesson}/unpublish`        | Bearer | owns course + `lessons.update` | —          | `LessonResource` |
| DELETE | `/teacher/lessons/{lesson}`                  | Bearer | owns course + `lessons.delete` | —          | — |

---

## Teacher / Admin — Videos

Videos are metadata only (no transcoding / streaming).

| Method | URL                                          | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/lessons/{lesson}/videos`           | Bearer | owns course / admin | —          | `VideoResource[]` |
| POST   | `/teacher/lessons/{lesson}/videos`           | Bearer | owns course + `lessons.update` | `title, storage_path?, duration?, position?, is_published?` | `VideoResource` |
| PUT    | `/teacher/lessons/{lesson}/videos/reorder`   | Bearer | owns course + `lessons.update` | `ordered_ids[]` | — |
| GET    | `/teacher/videos/{video}`                    | Bearer | owns course / admin | —          | `VideoResource` |
| PUT    | `/teacher/videos/{video}`                    | Bearer | owns course + `lessons.update` | `title?, storage_path?, duration?, position?, is_published?` | `VideoResource` |
| PATCH  | `/teacher/videos/{video}/publish`            | Bearer | owns course + `lessons.update` | —          | `VideoResource` |
| PATCH  | `/teacher/videos/{video}/unpublish`          | Bearer | owns course + `lessons.update` | —          | `VideoResource` |
| DELETE | `/teacher/videos/{video}`                    | Bearer | owns course + `lessons.delete` | —          | — |

---

## Student — Enrollment & access

| Method | URL                                    | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------|------|-----------------|--------------|----------|
| POST   | `/student/courses/{course}/enroll`     | Bearer | `student` / `students.manage` | —          | `EnrollmentResource` |
| GET    | `/student/courses`                     | Bearer | `student` | —          | `StudentCourseResource[]` |
| GET    | `/student/courses/{course}`            | Bearer | enrolled + `student` | —          | `StudentCourseResource` (with roadmap) |
| GET    | `/student/courses/{course}/roadmap`    | Bearer | enrolled + `student` | —          | `CourseRoadmapResource` |

**Errors:** 401 unauthenticated, 409 duplicate enrollment, 422 course not
published, 404 not enrolled / not found.

---

## Student — Progress

| Method | URL                                    | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------|------|-----------------|--------------|----------|
| GET    | `/student/progress`                    | Bearer | `student` | —          | `LessonProgressResource[]` |
| GET    | `/student/lessons/{lesson}/progress`   | Bearer | enrolled + `student` | —          | `LessonProgressResource` |
| PUT    | `/student/lessons/{lesson}/progress`   | Bearer | enrolled + `student` | `progress_percentage, last_position_seconds?, completed?` | `LessonProgressResource` |

**Validation:** `progress_percentage` required integer 0–100;
`last_position_seconds` integer ≥ 0; `completed` boolean.

**Behavior:** `completed` becomes true (and `completed_at` set) automatically
at 100%; otherwise `completed` is false and `completed_at` null.

---

## Student — Dashboard

| Method | URL                | Auth | Role | Response |
|--------|--------------------|------|------|----------|
| GET    | `/student/dashboard` | Bearer | `student` | `{ enrolled_courses_count, completed_lessons_count, in_progress_lessons_count, recently_accessed_lessons, courses }` |

---

---

# Phase 3 — Examination System

Exam creation and management is teacher-only; taking exams is student-only.
Only **published** exams in courses the student is actively **enrolled** in are
discoverable. The backend is the source of truth for correct answers, timing,
grading, score and pass/fail.

> **Security:** Student-facing responses (exam listing, attempt view, result)
> **never** expose `is_correct`, `correct_option`, or an answer key. Correctness
> and grading data appear only in teacher/management endpoints.

## Concepts

- **Exam** belongs to a course + a teacher creator. Lifecycle: `draft` →
  `published` → `archived`. Settings: `duration_minutes`, `pass_percentage`,
  `max_attempts`, `shuffle_questions`, `shuffle_options`,
  `show_result_immediately`.
- **Question** belongs to an exam. Initial type is `single_choice` only.
- **Option** belongs to a question and carries `is_correct` (teacher only).
- **Exam attempt** belongs to an exam + a student. Lifecycle: `in_progress` →
  `submitted` | `expired`. `expires_at = started_at + duration_minutes`
  (backend wins).
- **Snapshot** (exam_attempt_questions / exam_attempt_options) freezes the
  question/option structure, content, order, points and correctness at attempt
  start, so later teacher edits do not alter an in-progress attempt.
  Randomization happens once, at start.

> **Phase 3.1 — Immutability.** Exam attempts are immutable historical
> representations of the exam configuration/content at the time the attempt was
> created. A teacher may edit, reorder, change the correct answer or points, or
> **delete** a live question/option without affecting an existing attempt's
> snapshot, answers, or grading. The attempt snapshot is fully self-contained
> (it stores its own `question_text`/`points`/`option_text`/`is_correct`), and
> `exam_attempts.pass_percentage` freezes the pass threshold at start so pass/fail
> never changes retroactively. Student answering is validated against the attempt
> snapshot, never the live questions/options tables, so a mid-attempt deletion
> by a teacher does not block the student.

## Teacher — Exam management

| Method | URL                                    | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/courses/{course}/exams`      | Bearer | owns course / admin | —          | `ExamResource[]` |
| POST   | `/teacher/courses/{course}/exams`      | Bearer | owns course + `exams.create` | `title, description?, duration_minutes?, pass_percentage?, max_attempts?, shuffle_questions?, shuffle_options?, show_result_immediately?` | `ExamResource` |
| GET    | `/teacher/exams/{exam}`                | Bearer | owns exam / admin | —          | `ExamDetailResource` (with questions+options) |
| PUT    | `/teacher/exams/{exam}`                | Bearer | owns exam + `exams.update` | same fields as create, all optional | `ExamResource` |
| POST   | `/teacher/exams/{exam}/publish`        | Bearer | owns exam + `exams.update` | —          | `ExamResource` |
| POST   | `/teacher/exams/{exam}/archive`        | Bearer | owns exam + `exams.update` | —          | `ExamResource` |
| DELETE | `/teacher/exams/{exam}`                | Bearer | owns exam + `exams.delete` | —          | — |
| GET    | `/teacher/exams/{exam}/attempts`       | Bearer | owns exam + `students.view` | —          | `ExamAttemptDetailResource[]` |
| GET    | `/teacher/attempts/{attempt}`          | Bearer | owns the attempt's exam | —          | `ExamAttemptDetailResource` |

**Create validation:** `title` required; `duration_minutes` 1–600;
`pass_percentage` 0–100; `max_attempts` 1–100; booleans nullable.

**Publish validation (422 with message on failure):** course valid, ≥ 1
question, every question ≥ 2 options, `single_choice` has exactly one correct
option, `duration_minutes > 0`, `0 ≤ pass_percentage ≤ 100`,
`max_attempts ≥ 1`.

## Teacher — Question management

| Method | URL                                    | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/exams/{exam}/questions`      | Bearer | owns exam / admin | —          | `QuestionResource[]` |
| POST   | `/teacher/exams/{exam}/questions`      | Bearer | owns exam + `exams.update` | `question_text, type?, points?, position?` | `QuestionResource` |
| GET    | `/teacher/questions/{question}`        | Bearer | owns exam / admin | —          | `QuestionResource` |
| PUT    | `/teacher/questions/{question}`        | Bearer | owns exam + `exams.update` | same as create, all optional | `QuestionResource` |
| DELETE | `/teacher/questions/{question}`        | Bearer | owns exam + `exams.delete` | —          | — |

**Validation:** `type` must be `single_choice`; `points` 1–1000; `position` ≥ 1.

## Teacher — Option management

| Method | URL                                    | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/questions/{question}/options`| Bearer | owns exam / admin | —          | `OptionResource[]` |
| POST   | `/teacher/questions/{question}/options`| Bearer | owns exam + `exams.update` | `option_text, is_correct?, position?` | `OptionResource` |
| PUT    | `/teacher/options/{option}`            | Bearer | owns exam + `exams.update` | same as create, all optional | `OptionResource` |
| DELETE | `/teacher/options/{option}`            | Bearer | owns exam + `exams.delete` | —          | — |

> `OptionResource` (teacher) exposes `is_correct`. It is used **only** in these
> teacher endpoints.

## Student — Exam discovery

| Method | URL                                  | Auth | Role | Request body | Response |
|--------|--------------------------------------|------|------|--------------|----------|
| GET    | `/student/exams`                     | Bearer | enrolled + `student` | — | `StudentExamResource[]` |
| GET    | `/student/exams/{exam}`              | Bearer | enrolled + `student` | — | `StudentExamDetailResource` (metadata + my_attempts; **no questions/answer key**) |
| GET    | `/student/exams/{exam}/attempts`     | Bearer | enrolled + `student` | — | `[{ attempt_number, status, score, percentage, started_at, submitted_at }]` |
| POST   | `/student/exams/{exam}/start`        | Bearer | enrolled + `student` | —          | `ExamAttemptResource` |

**Start behavior:** validates published + enrollment + attempt limit; reuses an
existing active attempt (no duplicate); otherwise creates one with
`attempt_number`, `started_at`, `expires_at`, and freezes the snapshot with
randomization applied once. Errors: 403 not accessible, 422 not published /
limit reached.

## Student — Attempt

| Method | URL                                  | Auth | Role | Request body | Response |
|--------|--------------------------------------|------|------|--------------|----------|
| GET    | `/student/attempts/{attempt}`        | Bearer | owns attempt | — | `ExamAttemptResource` (ordered questions+options, timing, status; **no is_correct**) |
| POST   | `/student/attempts/{attempt}/answers`| Bearer | owns attempt | `question_id, option_id` | `ExamAttemptResource` |
| POST   | `/student/attempts/{attempt}/submit` | Bearer | owns attempt | —          | `ExamResultResource` if `show_result_immediately`, else `{ attempt_id, status }` |

**Answer validation:** attempt must be `in_progress`; question must be in the
attempt snapshot; option must belong to that question; attempt not expired
(server-side). 422 on failure.

**Submit:** grades server-side (score, percentage, pass/fail), then marks
`submitted`. Idempotent — re-submitting a submitted attempt returns the existing
result without re-grading. An expired attempt cannot be submitted (422) and is
transitioned to `expired`. Errors use 403 / 422.

---

## Phase 3 status codes

| Code | Meaning |
|------|---------|
| 200  | Success |
| 201  | Created (exam, question, option, attempt start) |
| 401  | Unauthenticated |
| 403  | Unauthorized / not enrolled / not accessible |
| 404  | Not found / exam not published for student |
| 409  | Duplicate enrollment |
| 422  | Validation failed / not publishable / attempt limit / invalid attempt state / expired |

---

# Phase 4 — Anti-Cheat & Exam Integrity

> **Anti-cheat signals are indicators of suspicious activity, NOT definitive
> proof of cheating.** The system records evidence, calculates a risk score, and
> flags attempts for a teacher/admin to review. It never auto-fails, auto-submits
> or bans a student, and the final academic decision is a human decision.

## Concepts

- **Integrity settings** (per exam): `fullscreen_required`, `prevent_copy`,
  `prevent_paste`, `prevent_context_menu`, `detect_tab_switch`,
  `detect_window_blur`, `detect_keyboard_shortcuts`.
- **Frozen per-attempt settings:** the exam's integrity settings are frozen onto
  each attempt (`exam_attempt_integrity_settings`) when the attempt starts. A
  teacher changing the live exam later never changes the rules of an existing
  attempt. `New Attempt → Current Config; Existing Attempt → Frozen Config`.
- **Integrity events** (`exam_integrity_events`): server-scored evidence with
  `event_type`, `occurred_at`, `severity`, `risk_points`, `metadata`.
- **Risk score & integrity status** are stored on the attempt and are always
  server-controlled (`normal` | `monitoring` | `flagged` | `reviewed` |
  `cleared`).
- **Reviews** (`exam_integrity_reviews`): immutable teacher decisions
  (`CLEARED`/`FLAGGED`) with a note and reviewer, forming an audit trail.
- **Aggregate condition** (`multiple_suspicious_events`): a **server-derived**
  state computed from the distinct risk-bearing event types actually recorded.
  It is exposed to teachers on the attempt integrity response and **never** adds
  extra risk or a synthetic event row.

Severity/risk points are **never** accepted from the client — the backend
assigns them from a central config (`config/integrity.php`). The aggregate
`MULTIPLE_SUSPICIOUS_EVENTS` condition is likewise **never** client-submittable;
it is derived server-side so the same evidence is never double-counted.

## Teacher — Integrity configuration

| Method | URL                                | Auth | Role/Permission | Request body | Response |
|--------|------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/exams/{exam}/integrity`  | Bearer | owns exam / admin | — | `{ exam_id, configured, settings }` |
| PUT    | `/teacher/exams/{exam}/integrity`  | Bearer | owns exam + `exams.update` | any of the 7 boolean flags below | `ExamIntegritySettingsResource` |

**Settings fields:** `fullscreen_required`, `prevent_copy`, `prevent_paste`,
`prevent_context_menu`, `detect_tab_switch`, `detect_window_blur`,
`detect_keyboard_shortcuts` — each `boolean`, all optional (partial updates
allowed). A 403 is returned if the teacher does not own the exam.

## Teacher — Attempt integrity & review

| Method | URL                                             | Auth | Role | Response |
|--------|-------------------------------------------------|------|------|----------|
| GET    | `/teacher/attempts/{attempt}/integrity`         | Bearer | teacher manages exam | `ExamAttemptIntegrityResource` (student, exam, attempt, `integrity_status`, `risk_score`, `multiple_suspicious_events`, `event_count`, `events`, frozen settings, reviews) |
| GET    | `/teacher/attempts/{attempt}/integrity-events`  | Bearer | teacher manages exam | `IntegrityEventResource[]` |
| POST   | `/teacher/attempts/{attempt}/integrity/review`  | Bearer | teacher manages exam | `{ decision, note? }` → `IntegrityReviewResource` |

**Review body:** `decision` required (`CLEARED` or `FLAGGED`), `note` optional
string. `CLEARED` sets the attempt status to `cleared`; `FLAGGED` sets it to
`flagged`. Each review inserts a new immutable audit record; historical reviews
are never overwritten.

## Student — Integrity event recording

| Method | URL                                          | Auth | Role | Request body | Response |
|--------|----------------------------------------------|------|------|--------------|----------|
| POST   | `/student/attempts/{attempt}/integrity-events` | Bearer | owns attempt | `event_type, occurred_at?, metadata?` | `{ recorded, deduplicated }` |

**Request:** `event_type` required (one of the client-reportable,
browser-observable types: `TAB_SWITCH`, `WINDOW_BLUR`, `WINDOW_FOCUS`,
`FULLSCREEN_ENTER`, `FULLSCREEN_EXIT`, `COPY_ATTEMPT`, `PASTE_ATTEMPT`,
`CUT_ATTEMPT`, `CONTEXT_MENU_ATTEMPT`, `KEYBOARD_SHORTCUT`);
`occurred_at` optional ISO timestamp (validated to a reasonable range — client
timestamps are untrusted); `metadata` optional object of simple strings (e.g.
`{"visibility_state":"hidden"}`), **never** clipboard contents, keystrokes, or
arbitrary payloads.

> **`MULTIPLE_SUSPICIOUS_EVENTS` is rejected here (422).** It is a
> server-derived aggregate condition, not a valid client submission. The backend
> computes it from the recorded risk-bearing events and exposes it to teachers; a
> student can never manufacture it or inflate the risk score with it.

**Behavior:** only accepted for the student's own `in_progress`, non-expired
attempt. Severity and risk points are assigned server-side. If the corresponding
protection is disabled the event is recorded as ignored (0 risk). Repeated
identical events within a short window are deduplicated (the first contributes
risk, later ones report `deduplicated: true` without being stored).

**Rate limiting:** this endpoint is limited to **60 events per minute per user**
(`throttle:integrity-events`).

**Frontend integration contract:** hidden → `TAB_SWITCH`; visible → `WINDOW_FOCUS`;
blur → `WINDOW_BLUR`; fullscreen exit → `FULLSCREEN_EXIT`; copy → `COPY_ATTEMPT`;
paste → `PASTE_ATTEMPT`; context menu → `CONTEXT_MENU_ATTEMPT`; recognized
shortcut → `KEYBOARD_SHORTCUT`. The backend remains authoritative, and the exam
experience does not depend on this endpoint being reachable (events may be
queued/retried client-side). Failure of the endpoint does not block the exam.

## Phase 4 status codes

| Code | Meaning |
|------|---------|
| 200  | Success |
| 201  | Event recorded / review recorded |
| 401  | Unauthenticated |
| 403  | Unauthorized / not the attempt owner / not the managing teacher |
| 422  | Invalid event type / timestamp, attempt not active, expired, submitted |

## Status codes

| Code | Meaning |
|------|---------|
| 200  | Success |
| 201  | Created |
| 401  | Unauthenticated |
| 403  | Unauthorized / inaccessible |
| 404  | Not found |
| 409  | Duplicate enrollment |
| 422  | Validation failed / course not available |

---

## Competitions & Leaderboard (Phase 5)

Competitions are a separate domain from exams. A competition is owned by the
teacher who created it, references an existing exam as its scoring source, and
produces a deterministic leaderboard. The aggregate competition result is
always derived server-side from the linked exam attempt records.

### Lifecycle & scheduling

```
DRAFT -> PUBLISHED -> ACTIVE -> ENDED -> ARCHIVED
```

`PUBLISHED -> ACTIVE` and `ACTIVE -> ENDED` are driven by the server-side
scheduling window (`starts_at` / `ends_at`) and applied lazily when a
competition is accessed or joined (no cron required). Participation is accepted
only while the window is open. Invalid transitions (e.g. `ENDED -> ACTIVE`,
`ARCHIVED -> ACTIVE`) are rejected.

### Concepts

- **Status** — `draft | published | active | ended | archived` (`CompetitionStatus`).
- **Scoring type** — `highest_score | best_attempt` (`CompetitionScoringType`).
  `highest_score` picks the best attempt by percentage; `best_attempt` by raw
  score. Both reuse the trusted exam attempt result; the client never supplies a
  value.
- **Ranking type** — `score_desc` (`CompetitionRankingType`). Deterministic
  ordering: `score DESC, completion_time ASC, completed_at ASC, participant_id ASC`.
- **Participant status** — `registered | active | completed | disqualified | withdrawn`
  (`CompetitionParticipantStatus`). Students cannot change their own status.
- **Ranking / tie handling** — standard competition ranking on `score`: equal
  scores share a rank and the next distinct score is skipped
  (`100, 100, 95 -> 1, 1, 3`).
- **Finalization** — when the window closes the competition becomes `ended` and
  the leaderboard is frozen. Recalculation of an ended competition is an
  explicit, authorized teacher action (`POST .../recalculate-leaderboard`) and is
  idempotent.
- **Anti-cheat** — a flagged attempt remains recorded and ranked (never
  auto-disqualified). A teacher reviews it and may explicitly disqualify the
  participant; a disqualified participant is excluded from ranked positions but
  their historical result is preserved.

### Teacher / Admin — Competitions

All routes require ownership of the competition (or admin) and
`competitions.manage`.

| Method | URL | Auth | Request body | Response |
|--------|-----|------|--------------|----------|
| GET    | `/teacher/competitions` | Bearer | — | `TeacherCompetitionResource[]` (own only unless admin) |
| POST   | `/teacher/competitions` | Bearer | `title, description?, exam_id, starts_at?, ends_at?, max_participants?, scoring_type?, ranking_type?` | `TeacherCompetitionResource` |
| GET    | `/teacher/competitions/{competition}` | Bearer | — | `TeacherCompetitionResource` |
| PUT    | `/teacher/competitions/{competition}` | Bearer | `title?, description?, starts_at?, ends_at?, max_participants?` | `TeacherCompetitionResource` |
| DELETE | `/teacher/competitions/{competition}` | Bearer | — | — (fails 422 if participants/results exist) |
| POST   | `/teacher/competitions/{competition}/publish` | Bearer | — | `TeacherCompetitionResource` |
| POST   | `/teacher/competitions/{competition}/archive` | Bearer | — | `TeacherCompetitionResource` |
| GET    | `/teacher/competitions/{competition}/participants` | Bearer | — | `CompetitionParticipantResource[]` |
| GET    | `/teacher/competitions/{competition}/leaderboard` | Bearer | — | `LeaderboardResource[]` (incl. internal ids, `qualified`) |
| POST   | `/teacher/competitions/{competition}/recalculate-leaderboard` | Bearer | — | — |
| POST   | `/teacher/competitions/{competition}/participants/{participant}/disqualify` | Bearer | — | `CompetitionParticipantResource` |

### Student — Competitions

| Method | URL | Auth | Request body | Response |
|--------|-----|------|--------------|----------|
| GET    | `/student/competitions` | Bearer | — | `StudentCompetitionResource[]` |
| GET    | `/student/competitions/{competition}` | Bearer | — | `StudentCompetitionResource` |
| POST   | `/student/competitions/{competition}/join` | Bearer | — | `StudentCompetitionResource` (`is_joined=true`) |
| GET    | `/student/competitions/{competition}/leaderboard` | Bearer | — | `LeaderboardResource[]` (public fields only) |
| GET    | `/student/competitions/{competition}/leaderboard/me` | Bearer | — | `rank, score, percentage, completion_time, qualified, total_participants` |

> Pagination: `?page=` / `?per_page=`. `per_page` is capped at 100.

### Phase 5 errors

| Code | Meaning |
|------|---------|
| 403 | Not the owner / not eligible (not enrolled, outside window, already the wrong role) |
| 409 | Duplicate participation or competition at capacity |
| 422 | Invalid lifecycle transition / read-only state / publish without a schedule / invalid validation |
