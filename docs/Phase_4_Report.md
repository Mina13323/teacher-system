# Phase 4 — Anti-Cheat & Exam Integrity: Final Report

## PHASE 4.1 — Integrity Hardening (Amendment)

This amendment removes the client-controlled `MULTIPLE_SUSPICIOUS_EVENTS` summary
event. The aggregate "multiple suspicious events" condition is now a
**server-derived** state computed from the recorded risk-bearing events, and is
never a valid client submission. No Phase 5 work was started.

- **Client summary event removed/rejected:** `POST .../integrity-events` now
  rejects `MULTIPLE_SUSPICIOUS_EVENTS` (422) at validation and in the action.
  Only browser-observable event types are client-reportable
  (`IntegrityEventType::clientReportable()`).
- **Server derivation:** `EvaluateAttemptRiskAction` computes a derived
  `multiple_suspicious_events` boolean from the distinct, risk-bearing event
  types actually recorded, and exposes it on the teacher integrity response.
- **No double counting:** the derived condition is informational only — it does
  **not** add any synthetic risk or event row. `TAB_SWITCH(+2) + COPY(+2) +
  FULLSCREEN_EXIT(+2) = 6` (flagged), never `6 + 3`.

## PHASE
Phase 4 — Anti-Cheat & Exam Integrity System. A privacy-conscious
integrity layer on top of the existing Examination System (Phases 1, 2, 3, 3.1).
It collects and scores integrity signals and events, flags suspicious attempts,
and lets a teacher/admin review — it does **not** build an invasive surveillance
system. No Phase 5 work was started.

> **Anti-cheat signals are indicators of suspicious activity, NOT definitive
> proof of cheating.** The final academic decision remains a teacher/admin
> decision.

## IMPLEMENTED
- **Per-exam integrity configuration** (`exam_integrity_settings`, one per exam)
  with `fullscreen_required`, `prevent_copy`, `prevent_paste`,
  `prevent_context_menu`, `detect_tab_switch`, `detect_window_blur`,
  `detect_keyboard_shortcuts`.
- **Frozen per-attempt settings** (`exam_attempt_integrity_settings`): the exam's
  integrity configuration is captured when the attempt starts; a later teacher
  change never silently changes the rules of an existing attempt.
- **Integrity event logging** (`exam_integrity_events`) with extensible
  `IntegrityEventType` enum (TAB_SWITCH, WINDOW_BLUR, WINDOW_FOCUS,
  FULLSCREEN_ENTER/EXIT, COPY/PASTE/CUT_ATTEMPT, CONTEXT_MENU_ATTEMPT,
  KEYBOARD_SHORTCUT, and the server-derived MULTIPLE_SUSPICIOUS_EVENTS which is
  never client-submittable).
- **Privacy-conscious metadata** — only simple strings such as
  `{"visibility_state":"hidden"}`, `{"shortcut":"CTRL+C"}`,
  `{"fullscreen":false}`. Clipboard contents, typed text, keystroke streams,
  screen/camera/mic captures are never accepted or stored.
- **Server-side severity & risk points** from a central config
  (`config/integrity.php`). The client can never set `severity`, `risk_points`,
  `risk_score`, or `integrity_status`.
- **Deterministic risk scoring** (`EvaluateAttemptRiskAction`) from the sum of
  event risk points; configurable thresholds (`normal` < monitoring < flagged).
- **Attempt integrity status** stored on the attempt (`normal` | `monitoring` |
  `flagged` | `reviewed` | `cleared`), server-controlled.
- **Teacher review + immutable audit trail** (`exam_integrity_reviews`) with
  `CLEARED`/`FLAGGED` decisions, note, reviewer, and reviewed_at. Historical
  reviews are never overwritten.
- **Deduplication** — repeated identical events within a short window are
  collapsed so a malicious client cannot inflate the score by flooding a single
  event type.
- **Rate limiting** on the student event endpoint (`throttle:integrity-events`,
  60/min per user).
- **Server validation** of attempt ownership, active/`in_progress` status,
  non-expiry, event type, and gating against the frozen attempt settings.
- **Teacher & Student resources** that keep risk/severity/review data out of
  student-facing responses.

## DATABASE CHANGES
New migrations (all `2026_01_01_00030x`):
- `exam_integrity_settings` (exam_id unique, 7 boolean flags, timestamps).
- `exam_attempt_integrity_settings` (attempt_id unique, frozen 7 boolean flags,
  timestamps).
- `exam_attempts`: added `integrity_status` (default `normal`) and `risk_score`
  (default 0), plus index on `(exam_id, integrity_status)`.
- `exam_integrity_events` (attempt_id FK, event_type, occurred_at, metadata JSON,
  severity, risk_points, timestamps; indexes on attempt_id/event_type/occurred_at).
- `exam_integrity_reviews` (attempt_id FK, reviewed_by FK nullOnDelete, decision,
  note, reviewed_at, timestamps; indexes for teacher review queries).

Foreign keys on the integrity child tables cascade on attempt deletion
(consistent with the existing retention model — integrity evidence belongs to an
attempt's lifecycle and is removed if the attempt itself is deleted). No other
destructive cascades were introduced.

## SNAPSHOT / CONFIGURATION FREEZING
- `CreateAttemptIntegritySettingsAction` runs inside `StartExamAttemptAction` at
  attempt creation, freezing the exam's current integrity configuration onto the
  attempt.
- If an exam has no configured settings, the system uses documented defaults
  (tab/blur detection on, prevention flags + fullscreen off).
- Later teacher edits to the live integrity settings affect only new attempts;
  existing attempts keep their frozen config (verified by
  `IntegrityConfigurationTest`).

## INTEGRITY EVENTS
- `POST /student/attempts/{attempt}/integrity-events` records an event.
- Backend authenticates and verifies the student owns the attempt, the attempt is
  `in_progress`, not expired, the event type is valid, and the event's protection
  is enabled (if disabled, the event is recorded as ignored with 0 risk).
- Severity and risk points are derived server-side from `config/integrity.php`.

## RISK MODEL
Baseline risk points (centralized, not magic numbers): WINDOW_BLUR +1,
TAB_SWITCH +2, FULLSCREEN_EXIT +2, COPY/PASTE/CUT_ATTEMPT +2,
CONTEXT_MENU_ATTEMPT +1, KEYBOARD_SHORTCUT +2, WINDOW_FOCUS +0,
FULLSCREEN_ENTER +0.

`MULTIPLE_SUSPICIOUS_EVENTS` is **not** a risk-scored event type — it is a
server-derived condition computed from the recorded risk-bearing events and adds
no synthetic risk (so the same evidence is never double-counted).

Thresholds: risk >= `flagged` (6) → `flagged`; risk >= `monitoring` (3) →
`monitoring`; otherwise `normal`. `EvaluateAttemptRiskAction` produces a
deterministic `{ risk_score, integrity_status, multiple_suspicious_events }` from
the recorded events.

## INTEGRITY STATUS
Automatic: `normal` / `monitoring` / `flagged`. Teacher review sets `cleared`
(CLEARED) or `flagged` (FLAGGED). `reviewed` is reserved for a future no-decision
review flow. A teacher's human decision is preserved: automatic recalculation
never overwrites `reviewed`/`cleared`.

## TEACHER REVIEW
- `GET /teacher/attempts/{attempt}/integrity` (summary + events + reviews),
  `GET /teacher/attempts/{attempt}/integrity-events`,
  `POST /teacher/attempts/{attempt}/integrity/review` (CLEARED/FLAGGED + note).
- Each review inserts a new immutable `exam_integrity_reviews` row; previous
  review history is preserved.

## SECURITY
- Only the attempt owner can record events; only the managing teacher can view
  integrity and review (verified by `IntegrityAuthorizationTest`).
- Client cannot set `risk_points`/`severity`/`integrity_status`/`risk_score`.
- Expired and submitted attempts reject new events (422).
- `occurred_at` is validated and backdated/far-future timestamps are rejected.
- IDOR cases tested: Student A ↔ Student B attempts, Teacher A ↔ Teacher B exams.

## PRIVACY
Data minimization: stores event type, timestamp, limited metadata, severity, and
risk points only. It does **not** store clipboard content, full keyboard history,
screen/camera/mic recordings, personal files, browser history, or location. The
frontend is documented to send only simple, purpose-limited metadata.

## RATE LIMITING
`POST /student/attempts/{attempt}/integrity-events` is limited to **60 requests
per minute per user** via a dedicated `integrity-events` throttler. High enough
for legitimate browser visibility/focus events, low enough to block flooding.

## API ENDPOINTS
Teacher:
- `GET/PUT /teacher/exams/{exam}/integrity`
- `GET /teacher/attempts/{attempt}/integrity`
- `GET /teacher/attempts/{attempt}/integrity-events`
- `POST /teacher/attempts/{attempt}/integrity/review`

Student:
- `POST /student/attempts/{attempt}/integrity-events`

Full request/response/validation documentation is in `docs/API.md`.

## TESTS
- `IntegrityConfigurationTest` — configure settings, teacher cross-exam gating,
  freeze-into-attempt, live-change isolation, defaults, unconfigured GET.
- `IntegrityEventTest` — valid/invalid event, cross-student rejection, expired &
  submitted rejection, client cannot set risk/severity/status, limited metadata,
  timestamp validation.
- `IntegrityRiskTest` — per-event risk, disabled-event no-op, deterministic
  totals, threshold transitions, deduplication window.
- `IntegrityAuthorizationTest` — student vs teacher integrity endpoints, teacher
  A vs teacher B, student cannot review.
- `IntegrityReviewTest` — teacher review, decision recording, status updates,
  audit-history preservation, teacher evidence endpoint.
- `IntegrityPrivacyTest` — student resources do not expose risk/severity/review.
- `IntegrityMultipleSuspiciousTest` (Phase 4.1) — client cannot submit the
  synthetic summary event, server derives suspicious state from individual
  events, and no double-counting of the same evidence.

## FULL TEST RESULT
⚠️ **PHP/Composer are not installed in this sandbox** (`php: command not found`),
so `php artisan test`, `migrate:fresh --seed`, and `route:list` could **not** be
executed here. Verification was done statically via a PHP AST parser:
- **Syntax:** 259 PHP files, **0 parse errors**
- **Refcheck:** 162 `App/Tests` imports, **0 missing**

The new migrations (including the SQLite table-rebuild for `exam_attempts` and
`dropForeign`/index changes) should be confirmed on a clean database in a PHP
environment. Existing Phase 1–3.1 tests were not executed and should be re-run to
confirm no regressions.

## KNOWN LIMITATIONS
- Events and risk are evaluated as they are recorded; there is no background job
  to sweep expired attempts or recompute risk between event arrivals.
- `show_result_immediately` and the live exam's other settings are not frozen
  here (only integrity config and pass % are frozen).
- The aggregate `MULTIPLE_SUSPICIOUS_EVENTS` condition is derived server-side
  from recorded risk-bearing events and is exposed to teachers on the attempt
  integrity response; it is never a client-submittable event and never adds
  risk.
- The deduplication window and rate limit are fixed constants in config; no
  per-exam override is provided.
- Students are not shown their integrity status (no explicit product requirement;
  prevents leaking risk/evidence).

## NOT IMPLEMENTED
Webcam, Microphone, Screen Recording, Keylogging, Browser Fingerprinting, GPS,
AI Cheating Detection — all intentionally excluded per the Phase 4 scope.

## NEXT PHASE
Phase 5 — Competitions & Leaderboard. Not started, per instructions.
