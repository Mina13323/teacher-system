# B1 — Fewer redundant exam heartbeat requests

Scope: B1 only. Frontend change; no backend, database, configuration or infrastructure change.
Baseline: `7473784` (`build: refresh staging frontend assets`), the staging-deployed frontend baseline.
Nothing was deployed. Production was not touched.

## 1. Audit (at `7473784`)

### What the proposal assumed vs. what the code does

The proposal assumed every heartbeat is a database request. That is out of date. Since `6076a2e`
the exam screen already sends two kinds of beat on one jittered chain (12–18 s):

| Beat | Endpoint | MySQL | Frequency before B1 |
|---|---|---|---|
| Time ping | `GET /api/v1/time` (`ServerTimeController`): no auth, no session, no throttle | none | ~7 of 8 beats |
| Status check | `POST /student/attempts/{id}/heartbeat` (`AttemptController::heartbeat`) | auth + 1 conditional UPDATE (fast path) | first beat ≥ 115 s after the last check (~every 2 min) |

So the only heartbeat worth removing for the database is the **status check**. Skipping the time
ping would save no MySQL connection and would make the clock offset and connection banner staler.

### Heartbeat callers and heartbeat-field readers/writers (whole repo)

- Frontend caller: `resources/js/views/Student/ExamTake.vue` `sendHeartbeat()` only, via
  `createHeartbeatScheduler` (`resources/js/utils/examRequestPacing.js`). No other view, store or service
  worker calls it. Load-test scripts (`load-tests/*.js`, `scripts/loadtest-smoke-lib.mjs`) call it directly.
- `last_heartbeat_at` writers: `AttemptController::heartbeat` (status check), `StartExamAttemptAction`
  (start). **Readers: none.** No rule, resource, teacher/admin screen, command or job reads it
  (only tests assert it is written). `ExamAttemptResource` does not expose it.
- `config/integrity.php` `heartbeat_interval_seconds`/`heartbeat_timeout_seconds`: not read by any code
  path that enforces anything; the comments there and in `ExpireExamAttemptAction`,
  `TerminateExamAttemptAction`, `RecordIntegrityEventAction` state that a missed heartbeat never
  terminates, never flags and never records an integrity event (tested in
  `tests/Feature/Exam/ExamHardeningRegressionTest.php::test_heartbeat_timeout_never_terminates_or_flags_attempt`,
  `tests/Feature/Integrity/InterruptionFairnessTest.php::test_network_loss_and_heartbeat_timeout_create_no_integrity_state`).

### What a status check does (server and client)

Server (`AttemptController::heartbeat`):
1. `authorize('update')` (owner only) — `ExamHotPathTest::test_another_student_cannot_send_a_heartbeat`.
2. Fast path: one UPDATE of `last_heartbeat_at`/`updated_at`, only while in progress and before both deadlines.
3. Otherwise `ExpireExamAttemptAction` (finalizes an expired attempt) and 422 if not in progress.
4. Returns `status`, `expires_at`, `server_time(_ms)`.

Client (`sendHeartbeat`): observes server time, clears the connection banner, adopts a changed
`expires_at` (restarts the countdown), and reloads the attempt when the status is not `in_progress` or
on 422.

Enforcement it does **not** do exclusively: every answer save, integrity event and submit re-checks
status and deadline on the server; `attempts:process-expired` (per minute) finalizes expired attempts;
the countdown locks the screen at the server-clock deadline. Item 3 above is the same finalization the
save path (`SaveExamAnswerAction::save` → `FinalizeExpiredAttemptAction`) and the sweep perform.

### What a successful answer save proves

- All saves go through `sendAnswerInOrder` (per-question `createKeyedQueue`) → `student.answer` with
  `compact_response: true`. Callers: `answer()` (click), `saveMcqExplanation` (3 s debounce),
  `saveEssay` / background essay save, `flushPending` (autosave chain with backoff, draft passes,
  submit). `answerKeepalive` (page exit) has no response and is not part of this.
- Server: `SaveExamAnswerAction::save` checks owner (`SubmitExamAnswerRequest` + `authorize`),
  in-progress and not expired **before and inside** a `lockForUpdate` transaction; otherwise 422.
- Compact response (`AttemptController::answer`): exactly `{id, status: 'in_progress', expires_at, question}`
  — contract asserted by `ExamHotPathTest::test_a_compact_answer_acknowledgement_reports_exactly_what_was_stored`.
  A 200 therefore proves: this attempt was in progress with this deadline when the server handled the save.
- It does **not** return server time, and does **not** write `last_heartbeat_at`.
- `expires_at` never changes for an in-progress attempt (`StartExamAttemptAction` sets it;
  `ResumeFlaggedAttemptAction` keeps it byte-for-byte). The heartbeat's deadline adoption is a safety net only.

### Lifecycle (unchanged by B1)

- Stops answering → no saves → status checks every ~2 min, exactly as before.
- Disconnect → time pings/saves fail → banner; saves queued with backoff. Reconnect → `markConnection(true)`
  sets `statusSyncWanted` → the next beat is a real status check (kept as is).
- Refresh / remount → `load()` reads the attempt, resets the schedule; `onBeforeUnmount` stops all timers.
- Hidden tab → beats pause; one beat on return (scheduler unchanged).
- Time-up while offline → forces a status check on the next beat (kept as is).
- Concurrency: one save per question at a time (keyed queue), per-question sequence numbers guard the
  UI merge; the heartbeat chain has one timer and one beat in flight.

### Conclusion

Skipping the beat entirely, as written in the proposal, is not the right shape here (the beat that would
be skipped is mostly the DB-free ping, and skipping it loses clock/connection freshness). Deferring the
**status check** after a confirmed save is safe: the save proves everything the client uses the status
check for, the server enforces deadlines and closures independently, and the one thing it does not
provide — a `last_heartbeat_at` write — has no reader. To keep that column meaningful anyway, a real
status check is still sent at least every ~6 minutes. No backend change is needed.

## 2. Behaviour

**Before.** Beat every 12–18 s. A beat is a status check when ≥ 115 s have passed since the last status
check (or attempt read), or after a reconnect / time-up-offline; otherwise a time ping.

**After.** Same chain, same beats. A beat is a status check when any of:
- ≥ 115 s since the attempt state was last **confirmed** — a status check sent, the attempt read, or a
  confirmed answer save **sent** (send time, so the confirmation is never assumed fresher than it is);
- ≥ 345 s (`STATUS_CHECK_MAX_GAP_MS`) since the last real status check (or attempt read);
- forced: first beat after the connection came back, or after the time-up-offline path.

Otherwise it is the time ping (as before).

**A save counts only when all hold** (`statusCheckGate.noteSaveResponse`):
- the request actually returned successfully (queued, debounced, in-flight, superseded, failed,
  timed-out, 422 and keepalive saves never reach this code);
- the response is a compact acknowledgement with `status === 'in_progress'`;
- its `id` equals the attempt the save was sent for **and** the attempt on screen;
- its `expires_at` is the same instant as the running countdown's deadline;
- the screen is not blocked (time up, expired, submitted) and the attempt is still `in_progress`;
- the schedule has been initialised (attempt read) and not invalidated (time-up offline).

The observation time only moves forward (`Math.max`), so late or out-of-order responses, or responses to
saves sent before a reload, change nothing.

Not changed: answer sending (no request is delayed, batched or dropped), retries/backoff, draft passes,
keepalive on exit, countdown, deadline adoption, submit/time-up, integrity monitoring and reporting,
reconnect/visibility handling, any server endpoint.

## 3. Estimates (simulation, not measured)

Simulated in `statusCheckGate.spec.js` ("B1 request estimate"):

| | Status checks / student / hour |
|---|---|
| Before (any student) | ~29 (one every ~2 min) |
| After, student saving at least every ~2 min (every 20, 45, 80 or 110 s) | ~10 (one every ~6 min) |
| After, student saving every 2.5 min | ~23 |
| After, idle student | ~29 (unchanged) |

Avoided DB heartbeat requests, **upper bound** (every student actively saving at least every ~2 min):

| Students | Avoided / hour | Avoided / second |
|---|---|---|
| 1 | ~19 | ~0.005 |
| 50 | ~950 | ~0.26 |
| 100 | ~1,900 | ~0.53 |
| 500 | ~9,500 | ~2.6 |

At 500 students the status-check rate drops from ~4.1/s to ~1.4/s in this best case. **Total HTTP
requests are unchanged**: each deferred status check becomes a `/time` ping (no MySQL, no auth). With
`exam-realistic.js` defaults (one answer every 40–80 s) nearly every student qualifies.

**Liveness:** the longest gap between `last_heartbeat_at` writes for an active, visible, online student
grows from ~133 s (115 s + one beat) to ~363 s (345 s + one beat). Nothing reads that value. For an idle
student nothing changes. The longest time the screen goes without a server confirmation that the
attempt is open is unchanged (~133 s).

**Unverified:** that fewer status checks measurably reduce new MySQL connections on Hostinger; real
student save cadence; the k6 mirror matching the browser exactly. The SQLSTATE 2002 /
`Operation not permitted` failures are unresolved, and B1 is not a fix for them.

## 4. Staging-only validation plan (not executed)

Pre-requisites: build the staging frontend from this branch with the repo's build script; staging DB
only; fixture students (`php artisan loadtest:seed ... --reset-attempts --duration=15`). Never Production.

**A. Functional (2–5 test students, real browsers, DevTools Network open)**
1. Start an exam; answer a question every ~30 s for 10 min. Expect `POST …/heartbeat` about every 6 min,
   `GET /time` on the other beats, every answer → 200 and visible after reload.
2. Stop answering for 6 min. Expect heartbeats back to ~every 2 min.
3. Go offline (DevTools) 1 min while answering; come back. Expect queued answers sent on backoff and the
   first beat after reconnect to be a heartbeat; no integrity event for the outage.
4. Refresh mid-exam and navigate away/back. Expect the attempt resumes, answers persist, no new attempt.
5. Hide the tab 1 min. Expect no beats while hidden and one on return.
6. Let one attempt reach its deadline while idle and one while answering. Expect the screen locks at the
   deadline, `auto_submit_at_deadline`/submission at `expires_at`, score unchanged vs. answers.
7. Teacher terminates / integrity threshold on one attempt; expect next save → 422 → reload to result.
8. Teacher integrity review page: events and warning counts identical in kind to a pre-B1 run.
9. DB check per attempt: one attempt per student, answers equal acknowledgements, `last_heartbeat_at`
   updated at most ~6 min apart while active.

**B. Load (k6 `load-tests/exam-realistic.js`, one step at a time, operator-run)**
- Step 1: `STAGE=50`. Optionally first replay `B1_SAVE_DEFERS_STATUS=0` at 50 for a before/after
  comparison of `endpoint:heartbeat` counts and `metrics:requests` DB-connection rate.
- Only if step 1 is clean: 100 → 250 → 374 → 500, with a cool-down between steps.
- Watch: `storage/logs` and `metrics:requests` for SQLSTATE 2002 / `Operation not permitted`,
  HTTP 503 (`service_busy`) / 504, k6 transport errors (`status 0`, EOF, reset), p95/p99 per endpoint
  vs. the README thresholds, 401s, answer mismatches, duplicate attempts/finalizations.

**Stop conditions (do not increase load):** any SQLSTATE 2002 / connection refusal (stop at once; do not
retry the same or a higher step until the hosting/DB issue is understood); any 503/504 burst; any lost
answer, duplicate attempt, grading change or unexpected 401; p95 above threshold at the current step.
