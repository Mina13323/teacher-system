# Load-test fixtures (STAGING ONLY)

Deterministic, idempotent fixtures for load testing `https://staging.maherelmasry.com`.
Nothing here may ever run against production.

## Commands

```bash
php artisan loadtest:seed --students=10      # default is 10 if --students is omitted
php artisan loadtest:seed --students=50
php artisan loadtest:seed --students=1000
php artisan loadtest:seed --students=1500    # max 5000 (config/loadtest.php)

php artisan loadtest:seed --students=1500 --reset-attempts   # also wipe fixture attempts so the exam can be retaken
php artisan loadtest:seed --students=1500 --window-days=14   # exam window length (default 7 days)

php artisan loadtest:clean --dry-run         # show what would be deleted, roll back
php artisan loadtest:clean                   # delete every fixture record
```

Re-running `loadtest:seed` is safe: existing fixture users are reused and reset to the canonical
fixture state (active, can take exams, no forced password change, active access, active enrollment),
missing ones are created, and the exam window is re-centred on the current time. Running with a
smaller `--students` never deletes users; it only ensures students `0001..N` exist.

Optional smoke check of the real API flow with ONE student (not a load test):

```bash
BASE_URL=https://staging.maherelmasry.com node scripts/loadtest-smoke.mjs 1
```

It runs login → GET exams → GET exam → POST start → GET attempt → POST answer → POST heartbeat → POST submit.
Because the exam allows a single attempt, a student can smoke-test once; use another student number,
or `loadtest:seed --reset-attempts`, to repeat.

## Safety protections

Both commands call `App\Services\LoadTest\LoadTestGuard` first and abort with exit code 1 unless **all** hold:

1. `APP_ENV` = `staging`
2. the host of `APP_URL` is exactly `staging.maherelmasry.com`
3. the configured **and** connected database name is `u481922752_staging`

(values live in `config/loadtest.php`). `APP_ENV` alone is never trusted. Cleanup only matches the
markers below and keeps the fixture course/exam/teacher if any non-fixture data references them.

## What gets created

| Thing | Identifier |
| --- | --- |
| Students | email `loadtest.student.NNNN@staging.maherelmasry.com`, student code `LT-NNNN` (NNNN = `0001`…`1500`), name `Load Test Student NNNN` |
| Teacher | `loadtest.teacher@staging.maherelmasry.com` with the real Spatie `teacher` role + its seeded permissions |
| Course | slug `load-test-course`, status `published`, owned by the load-test teacher |
| Exam | title `Load Test Exam (load-test-exam)`, status `published`, in that course |
| Access periods | one per student, status `active`, notes `loadtest-fixture`, now−1d → now+(window+30)d |
| Enrollments | one per student in the course, status `active` |

Students: role `student`, `is_active=1`, `can_take_exams=1`, `can_access_lessons=1`, `can_join_competitions=1`,
`must_change_password=0`, academic year `secondary_1`, `created_by` = load-test teacher.

Exam: 60 minutes, pass 50 %, `max_attempts=1`, windowed (`starts_at` = now−1h, `ends_at` = now+7d),
shuffle questions/options on, `expiry_mode=auto_submit`, answer review allowed, default integrity settings.

Questions (20, all with valid answer keys per `Question::hasValidAnswerKey()`):
16 `single_choice` × 4 options × 1 correct × 1 point; 4 `multiple_choice` (Q5, Q10, Q15, Q20) × 5 options × 2–3 correct × 2 points.
The exam is validated with the application's own `PublishExamAction::assertValid()` before being marked published.

## Password policy

Every fixture account (students and teacher) uses one dedicated staging-only password:

```
LoadTest#Staging-2026
```

Override with `LOADTEST_PASSWORD` in the **staging** `.env`. The password is bcrypt-hashed once per run
(`BCRYPT_ROUNDS`) and the same hash is stored for all fixture users. Never reuse it anywhere else.

## Identifying fixture data

```sql
SELECT COUNT(*) FROM users WHERE email LIKE 'loadtest.student.%@staging.maherelmasry.com' AND student_code LIKE 'LT-%';
SELECT * FROM users   WHERE email = 'loadtest.teacher@staging.maherelmasry.com';
SELECT * FROM courses WHERE slug = 'load-test-course';
```

## What `loadtest:seed` / `loadtest:clean` do NOT touch

* Production (guard-blocked), any non-fixture user, course, exam, enrollment, attempt or access period.
* Application code paths: no model, policy, enum, validation or business rule is changed or bypassed;
  login, enrollment, access, exam-window and attempt-limit checks all run normally.
* Roles/permissions: only if the `teacher`/`student` roles or the teacher's permissions are missing are the app's own
  idempotent `RoleSeeder` + `PermissionSeeder` run (baseline RBAC, not fixture data); `loadtest:clean` never removes roles.
* Config, `.env`, cache, queue, sessions of real users. (Cleanup removes only the fixture users' tokens,
  notifications, sessions and audit-log rows.)

## Cleanup order

attempt children (integrity, answers, snapshot) → attempts → make-ups → enrollments → access periods →
exam integrity settings → options → questions → exam → course → fixture users' tokens/notifications/sessions/audit rows/role pivots →
students → teacher.

## Load-test notes

* `POST /auth/login` stays throttled to 10/min per IP and per account for everyone. Fixture students alone get a larger
  per-IP bucket (`loadtest.login_per_minute_per_ip`, 600/min), and only when APP_ENV, APP_URL host and database are the
  staging ones AND the email is exactly `loadtest.student.NNNN@staging.maherelmasry.com` (`LoadTestLoginAllowance`).
  The per-account limit still applies.
* Authenticated API budget is 120 requests/min per user.
