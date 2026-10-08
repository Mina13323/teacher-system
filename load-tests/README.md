# k6 load tests (STAGING ONLY)

Target: `https://staging.maherelmasry.com` — nothing else. Every script refuses to run (before any HTTP
request) unless `BASE_URL` is **exactly** `https://staging.maherelmasry.com`; `maherelmasry.com` and
`www.maherelmasry.com` are rejected explicitly. The check also runs again before every request.

Accounts come from the existing fixtures (`php artisan loadtest:seed`, see `../LOAD_TEST_FIXTURES.md`):
`loadtest.student.NNNN@staging.maherelmasry.com`, password `LoadTest#Staging-2026` (override with `LOADTEST_PASSWORD`).
The scripts never create or modify accounts.

| Script | Purpose |
| --- | --- |
| `student-flow.js` | **Primary.** Realistic journey: login → exam list → exam details → start → get attempt → answers (+ heartbeats) → submit |
| `exam-start-storm.js` | All VUs fire `POST /exams/{id}/start` at the same moment; each student double-starts (double-click) to verify the one-active-attempt rule |
| `answer-save-storm.js` | All VUs start, then save answers back-to-back at the same time; optional submit |
| `dashboard-flow.js` | Read-only: each VU logs in and opens the student dashboard `DASHBOARD_VIEWS` times (default 5); VU 1 opens the teacher dashboard as the load-test teacher. Needs no reset between runs |
| `k6-config.js` | Shared guard, sizing, metrics, thresholds, request helpers (not run directly) |

Request payloads, auth (Bearer token from `POST /auth/login`), and response fields are the ones used by the real
SPA and by `scripts/loadtest-smoke.mjs` (`rules_acknowledged` + `compact_response` on start, `option_ids` on answers).

## Before you run: fixtures and the one-attempt rule

The fixture exam has `max_attempts=1`, so **a fixture student can complete the exam once per reset**. The scripts
therefore use one student per VU (VU *n* → student `START_INDEX + n - 1`), one iteration per VU, and never resume or
reuse an old attempt: if a student already has any attempt, that VU skips, logs a `[fixture]` warning and increments
`fixture_not_clean`, which fails the run (threshold `count==0`) so a stale fixture can never produce a misleading green result.

1. Seed enough students **on staging** (a stage of N VUs needs students `1..N`; the smoke test already used #0001):
   ```bash
   php artisan loadtest:seed --students=50
   ```
2. **Reset between runs** (real rules are never bypassed; this deletes only fixture attempts and re-opens the window):
   ```bash
   php artisan loadtest:seed --students=50 --reset-attempts
   ```
   `php artisan loadtest:clean` removes the whole fixture. Alternatively use a fresh range with `START_INDEX`
   (e.g. `START_INDEX=51`) for the next run instead of resetting.
3. The exam window is `now−1h … now+7d` from the last seed; re-seed (`--window-days=N`) if it has lapsed.
4. The storm scripts leave attempts **in progress** (start storm) or submitted (answer storm); both need the reset above.

## Running

Bash / Git Bash:
```bash
BASE_URL=https://staging.maherelmasry.com k6 run load-tests/student-flow.js
```

Windows PowerShell:
```powershell
$env:BASE_URL="https://staging.maherelmasry.com"
k6 run .\load-tests\student-flow.js
```

With no sizing variables the default is **5 VUs** over a 10 s ramp — small and safe.

### Selecting a stage (50 / 100 / 250 / 500 / 750 / 1000 VUs)

Set `STAGE` (only those six values are accepted) or, for ad-hoc sizes up to 1000, `VUS`. Nothing runs automatically.

```powershell
$env:BASE_URL="https://staging.maherelmasry.com"
$env:STAGE="100"          # 50 | 100 | 250 | 500 | 750 | 1000
k6 run .\load-tests\student-flow.js
```
```bash
BASE_URL=https://staging.maherelmasry.com STAGE=250 k6 run load-tests/student-flow.js
```

| STAGE | Students needed | Default ramp (`RAMP_SECONDS`) | Arrival rate |
| --- | --- | --- | --- |
| (default 5) | 1–5 | 10 s | 0.5/s |
| 50 | 1–50 | 60 s | 0.83/s |
| 100 | 1–100 | 120 s | 0.83/s |
| 250 | 1–250 | 300 s | 0.83/s |
| 500 | 1–500 | 600 s | 0.83/s |
| 750 | 1–750 | 900 s | 0.83/s |
| 1000 | 1–1000 | 1200 s | 0.83/s |

**Controlled ramp:** VU *k* starts at *k/N* of the ramp window (+ ≤1 s jitter), so users arrive linearly instead of as an
instant spike; concurrency then builds up to roughly arrival-rate × flow duration. Override with `RAMP_SECONDS`.
(`per-vu-iterations` is used instead of `ramping-vus` because a VU must never loop onto a second attempt with the same student.)

### Other variables

| Variable | Default | Meaning |
| --- | --- | --- |
| `BASE_URL` | – (required) | Must be exactly `https://staging.maherelmasry.com` |
| `STAGE` / `VUS` | `VUS=5` | Concurrent users (1–1000) |
| `START_INDEX` | `1` | First fixture student used by VU 1 |
| `RAMP_SECONDS` | per table | Window over which VUs start |
| `LOADTEST_PASSWORD` | documented default | Fixture password |
| `THINK_SCALE` | `1` | Multiplier for think time; `0` = no pauses (stress), `2` = slower users |
| `LOGIN_MAX_RETRIES` | `0` | Retries on HTTP 429 at login (see below) |
| `ANSWERS_PER_ATTEMPT`, `HEARTBEAT_EVERY` | `8`, `2` | student-flow: saves per attempt; heartbeat after every Nth save (+1 before submit) |
| `DOUBLE_START` | `1` | start storm: two simultaneous starts per student (`0` = single) |
| `ANSWERS_PER_VU`, `ANSWER_GAP_MS`, `SUBMIT` | `20`, `250`, `1` | answer storm: saves per VU (max 90), gap between saves, submit at the end |
| `BARRIER_GRACE_SECONDS` | `15` / `20` | storms: seconds after the ramp at which all VUs fire together |

Save results outside git (a `load-tests/results/` folder is git-ignored):
```powershell
k6 run --summary-export=.\load-tests\results\flow-50.json .\load-tests\student-flow.js
```

## Login rate limit

The application throttles `POST /auth/login` to **10 requests/minute per IP** and per account (`config/api.php`,
limiter `login` in `AppServiceProvider`). **This is unchanged for everyone, including production.**

For the fixture students only there is a narrow, staging-only allowance (`App\Services\LoadTest\LoadTestLoginAllowance`):
a login whose identifier is exactly `loadtest.student.NNNN@staging.maherelmasry.com` is counted in its own per-IP bucket of
`loadtest.login_per_minute_per_ip` (600/min) instead of the normal 10/min. It applies **only if** `APP_ENV=staging`,
the `APP_URL` host is `staging.maherelmasry.com`, the connected database is `u481922752_staging` **and** the email matches
exactly. The per-account limit (10/min per account) still applies, fixture logins do not consume the normal per-IP budget of
other users, and there is no environment switch. The staging code containing this allowance must be deployed before the run.

So `LOGIN_MAX_RETRIES` can stay at `0` (the default) and the 250-1000 stages measure exam capacity, not the login throttle.
HTTP 429s, if any, are still counted in `login_throttled_429`. Authenticated calls remain limited to 120 requests/min per user.

## What is measured

Custom metrics (ms unless noted): `step_login_ms`, `step_exam_list_ms`, `step_exam_details_ms`, `step_start_attempt_ms`,
`step_get_attempt_ms`, `step_answer_save_ms`, `step_heartbeat_ms`, `step_submit_ms`, `flow_complete_ms`;
rates/counters: `http_5xx`, `flow_failed`, `flow_completed`, `answers_saved`, `login_throttled_429`, `fixture_not_clean`,
`start_double_mismatch` (start storm).

Every request carries `endpoint` = `login | exams | exam_details | start | attempt | answer | heartbeat | submit`
(plus `name`, with ids collapsed to `:id`, and `suite`). Filter in results, e.g. `http_req_duration{endpoint:start}`.

Checks cover: HTTP status; `{success,data}` envelope; token and user on login; load-test exam in the list; exam details
(`questions_count`, window); attempt created (`201`, `status=in_progress`); question snapshot (all questions, ≥2 options);
**answer persistence** (the saved `selected_option_ids` are echoed back); heartbeat (`in_progress` + `last_heartbeat_at`);
submission (`status` no longer `in_progress`, `submitted_at` set). Start storm additionally checks both double-start
responses are 201 with the same attempt id and exactly one `already_open=false`.

## Thresholds and why

These are first-run baselines for a small shared-hosting staging box; they are deliberately adjustable
(`buildThresholds` in `k6-config.js`). Tighten them once a baseline exists.

| Threshold | Value | Reason |
| --- | --- | --- |
| `http_req_failed` | `rate<0.02` | ≤2 % failed requests: allows rare network blips but any systematic failure (429/5xx/4xx) fails the run |
| `http_5xx` | `rate<0.005`, **abort** after 30 s | Server errors mean real faults (deadlocks, timeouts); near-zero tolerance, and the run aborts to stop hammering a failing staging server |
| `flow_failed` | `rate<0.05` | A student journey has ~15 requests; 5 % allows a few edge-case failures while a broken step still fails the run |
| `checks` | `rate>0.97` | Functional correctness under load (statuses, shapes, persisted answers) |
| `fixture_not_clean` | `count==0` | A reused student invalidates the run (one attempt per student) |
| `start_double_mismatch` | `count==0` | Concurrent starts must never create or return different attempts (DB unique `active_key` rule) |
| login p95 / p99 | 2000 / 4000 ms | bcrypt verify (cost 12) is deliberately CPU-heavy, ~0.2–0.5 s idle on shared hosting |
| exams, exam details p95 / p99 | 1500 / 3000 ms | Simple indexed reads; students notice >1.5 s |
| start p95 / p99 | 3000 / 6000 ms | Heaviest write: transaction + snapshot of 20 questions/≈84 options + integrity settings |
| attempt (GET) p95 / p99 | 2000 / 4000 ms | Serialises the full question/option snapshot |
| answer p95 / p99 | 1000 / 2500 ms | Row-locked transaction per save, but the SPA saves on every click so it must feel instant; the response is the full attempt |
| heartbeat p95 / p99 | 800 / 2000 ms | Single-row update called periodically; must be cheap |
| submit p95 / p99 | 3000 / 6000 ms | Grading of all answers in one request |

## Static validation (no load)

`node --check` on a copy of each script (k6 uses ES modules) validates syntax, and k6's own `k6 inspect` parses options
without running iterations:
```powershell
$env:BASE_URL="https://staging.maherelmasry.com"
k6 inspect .\load-tests\student-flow.js
```
