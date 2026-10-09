// Shared configuration, STAGING safety guard, helpers and metrics for the k6 suite.
// Contracts mirror the real API (see scripts/loadtest-smoke.mjs and LOAD_TEST_FIXTURES.md).
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import exec from 'k6/execution';
import { ABORT_ON_ANY, criticalSignal } from './lib/guards.js';

// ---------------------------------------------------------------------------
// HARD STAGING GUARD. Runs in the k6 init context, i.e. before any VU or HTTP
// request exists. Any other value (production included) aborts the run.
// ---------------------------------------------------------------------------
export const REQUIRED_BASE_URL = 'https://staging.maherelmasry.com';
const FORBIDDEN_HOSTS = ['maherelmasry.com', 'www.maherelmasry.com'];

export function assertStagingUrl(raw) {
  const value = String(raw === undefined || raw === null ? '' : raw).trim();
  if (value === '') {
    throw new Error(`BASE_URL is required and must be exactly ${REQUIRED_BASE_URL}`);
  }
  const normalized = value.replace(/\/+$/, '');
  const m = /^[a-z][a-z0-9+.-]*:\/\/(?:[^@/]*@)?([^/:?#]+)/i.exec(normalized);
  const host = m ? m[1].toLowerCase() : '';
  if (FORBIDDEN_HOSTS.indexOf(host) !== -1) {
    throw new Error(`REFUSING TO RUN: "${host}" is PRODUCTION. These tests are staging-only.`);
  }
  if (normalized !== REQUIRED_BASE_URL) {
    throw new Error(`REFUSING TO RUN: BASE_URL must be exactly ${REQUIRED_BASE_URL} (got "${value}").`);
  }
  return normalized;
}

export const BASE_URL = assertStagingUrl(__ENV.BASE_URL);
const API = `${BASE_URL}/api/v1`;

// ---------------------------------------------------------------------------
// Fixture identity (config/loadtest.php): loadtest.student.NNNN@staging...
// ---------------------------------------------------------------------------
// No default password: the staging fixture password must be supplied (the same
// value as LOADTEST_PASSWORD in the staging .env). Never printed or logged.
export const FIXTURE_PASSWORD = __ENV.LOADTEST_PASSWORD || '';
if (FIXTURE_PASSWORD.length < 16) {
  throw new Error('LOADTEST_PASSWORD is required (the staging fixture password, at least 16 characters). It is never printed.');
}
export const FIXTURE_DOMAIN = 'staging.maherelmasry.com';
export const EXAM_TITLE_PATTERN = /load-test-exam/;
const MAX_FIXTURE_STUDENT = 5000;

export function studentEmail(n) {
  return `loadtest.student.${String(n).padStart(4, '0')}@${FIXTURE_DOMAIN}`;
}

// ---------------------------------------------------------------------------
// Run sizing. STAGE (50|100|250|500|750|1000) or VUS; default is a tiny safe run.
// ---------------------------------------------------------------------------
const ALLOWED_STAGES = [50, 100, 250, 374, 500, 750, 1000];
const DEFAULT_VUS = 5;
// Seconds over which VU start times are spread (the controlled ramp).
const DEFAULT_RAMP = { 5: 10, 50: 60, 100: 120, 250: 300, 374: 450, 500: 600, 750: 900, 1000: 1200 };

function intEnv(name, dflt) {
  const raw = __ENV[name];
  if (raw === undefined || raw === '') return dflt;
  const n = parseInt(raw, 10);
  if (isNaN(n)) throw new Error(`${name} must be an integer (got "${raw}")`);
  return n;
}

function resolveVus() {
  if (__ENV.STAGE !== undefined && __ENV.STAGE !== '') {
    const stage = parseInt(__ENV.STAGE, 10);
    if (ALLOWED_STAGES.indexOf(stage) === -1) {
      throw new Error(`STAGE must be one of ${ALLOWED_STAGES.join(', ')} (got "${__ENV.STAGE}")`);
    }
    return stage;
  }
  const vus = intEnv('VUS', DEFAULT_VUS);
  if (vus < 1 || vus > 1000) throw new Error('VUS must be between 1 and 1000');
  return vus;
}

export const VUS = resolveVus();
// First fixture student used by VU 1; VU n uses START_INDEX + n - 1.
export const START_INDEX = intEnv('START_INDEX', 1);
if (START_INDEX < 1 || START_INDEX + VUS - 1 > MAX_FIXTURE_STUDENT) {
  throw new Error(`START_INDEX + VUS - 1 must be within 1..${MAX_FIXTURE_STUDENT}`);
}
export const RAMP_SECONDS = intEnv('RAMP_SECONDS', DEFAULT_RAMP[VUS] || Math.max(10, VUS));
// 0 disables think time (pure stress); 1 = realistic; 2 = twice as slow.
export const THINK_SCALE = parseFloat(__ENV.THINK_SCALE === undefined ? '1' : __ENV.THINK_SCALE);
// Login is throttled to 10/min/IP by the app. Retry on 429 honouring Retry-After.
export const LOGIN_MAX_RETRIES = intEnv('LOGIN_MAX_RETRIES', 0);
export const LOGIN_RETRY_CAP_SECONDS = intEnv('LOGIN_RETRY_CAP_SECONDS', 65);

export function studentForThisVu() {
  const n = START_INDEX + __VU - 1;
  return { n, email: studentEmail(n) };
}

/**
 * One iteration per VU, one student per VU: the exam allows a single attempt
 * (max_attempts=1) so a student can only be used once per reset. VU start times
 * are staggered across RAMP_SECONDS by the scripts (see rampDelay()).
 */
export function oneShotScenario(extraSeconds) {
  return {
    executor: 'per-vu-iterations',
    vus: VUS,
    iterations: 1,
    maxDuration: `${RAMP_SECONDS + extraSeconds}s`,
    gracefulStop: '30s',
  };
}

/** Linear ramp: VU k starts at k/VUS of the ramp window (+ up to 1s jitter). */
export function rampDelay() {
  const share = (__VU - 1) / VUS;
  sleep(share * RAMP_SECONDS + Math.random());
}

export function think(minSeconds, maxSeconds) {
  if (THINK_SCALE <= 0) return;
  sleep((minSeconds + Math.random() * (maxSeconds - minSeconds)) * THINK_SCALE);
}

// ---------------------------------------------------------------------------
// Metrics
// ---------------------------------------------------------------------------
export const m = {
  login: new Trend('step_login_ms', true),
  exams: new Trend('step_exam_list_ms', true),
  examDetails: new Trend('step_exam_details_ms', true),
  start: new Trend('step_start_attempt_ms', true),
  attempt: new Trend('step_get_attempt_ms', true),
  answer: new Trend('step_answer_save_ms', true),
  heartbeat: new Trend('step_heartbeat_ms', true),
  submit: new Trend('step_submit_ms', true),
  dashboard: new Trend('step_dashboard_ms', true),
  flow: new Trend('flow_complete_ms', true),
  http5xx: new Rate('http_5xx'),
  flowFailed: new Rate('flow_failed'),
  flowCompleted: new Counter('flow_completed'),
  loginThrottled: new Counter('login_throttled_429'),
  answersSaved: new Counter('answers_saved'),
  fixtureNotClean: new Counter('fixture_not_clean'),
  criticalFailures: new Counter('critical_failures'),
};

/**
 * Stop the WHOLE run now. exec.test.abort() is k6's supported global abort: it
 * interrupts every VU (in-flight requests included) and ends the run with exit
 * code 108, so no further requests, retries or iterations are started. The
 * reason is logged once per VU that reaches it; it never contains a token,
 * password or response body. A failed check() does NOT stop a run - only this
 * and abortOnFail thresholds do.
 */
export function abortRun(reason) {
  m.criticalFailures.add(1);
  console.error(`[ABORT] ${reason}`);
  exec.test.abort(`ABORT: ${reason}`);
}

// ---------------------------------------------------------------------------
// Thresholds (rationale for each value: load-tests/README.md "Thresholds").
// `endpointsInUse` limits latency thresholds to tags a script actually sends.
// ---------------------------------------------------------------------------
const LATENCY = {
  login: { p95: 2000, p99: 4000 },
  exams: { p95: 1500, p99: 3000 },
  exam_details: { p95: 1500, p99: 3000 },
  start: { p95: 3000, p99: 6000 },
  attempt: { p95: 2000, p99: 4000 },
  answer: { p95: 1000, p99: 2500 },
  heartbeat: { p95: 800, p99: 2000 },
  submit: { p95: 3000, p99: 6000 },
  student_dashboard: { p95: 1500, p99: 3000 },
  teacher_dashboard: { p95: 1500, p99: 3000 },
};

export function buildThresholds(endpointsInUse) {
  const t = {
    http_req_failed: ['rate<0.02'],
    http_5xx: [{ threshold: 'rate<0.005', abortOnFail: true, delayAbortEval: '30s' }],
    flow_failed: ['rate<0.05'],
    checks: ['rate>0.97'],
    fixture_not_clean: [ABORT_ON_ANY],
    // Backstop for abortRun(): any critical signal also trips this count threshold.
    critical_failures: [ABORT_ON_ANY],
  };
  endpointsInUse.forEach((ep) => {
    const l = LATENCY[ep];
    t[`http_req_duration{endpoint:${ep}}`] = [`p(95)<${l.p95}`, `p(99)<${l.p99}`];
  });
  return t;
}

// ---------------------------------------------------------------------------
// HTTP helpers (same headers/auth/payloads as scripts/loadtest-smoke.mjs)
// ---------------------------------------------------------------------------
export function request(method, path, body, token, endpoint, trend) {
  // Defence in depth: re-check the target before every request.
  assertStagingUrl(BASE_URL);
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = http.request(method, `${API}${path}`, body ? JSON.stringify(body) : null, {
    headers,
    timeout: '30s',
    // `name` collapses /attempts/123/... into one series; `endpoint` is the filter tag.
    tags: { endpoint, name: `${method} ${path.replace(/\/\d+/g, '/:id')}` },
  });
  m.http5xx.add(res.status >= 500);
  if (trend) trend.add(res.timings.duration);
  // Critical signals end the run immediately (5xx, transport failure, SQLSTATE
  // 2002 / connection refused, unexpected 401). See lib/guards.js.
  const critical = criticalSignal({ status: res.status, body: res.body, endpoint });
  if (critical) abortRun(critical);
  return res;
}

export function json(res) {
  try {
    return res.json();
  } catch (e) {
    return null;
  }
}

/** POST /auth/login -> token, or null. Retries 429 up to LOGIN_MAX_RETRIES. */
export function login(email) {
  for (let attempt = 0; ; attempt++) {
    const res = request('POST', '/auth/login', { email, password: FIXTURE_PASSWORD }, null, 'login', m.login);
    if (res.status === 429) {
      m.loginThrottled.add(1);
      if (attempt < LOGIN_MAX_RETRIES) {
        const wait = Math.min(parseInt(res.headers['Retry-After'] || '60', 10) || 60, LOGIN_RETRY_CAP_SECONDS);
        sleep(wait + Math.random() * 2);
        continue;
      }
    }
    const body = json(res);
    const ok = check(res, {
      'login: status 200': (r) => r.status === 200,
      'login: token present': () => !!(body && body.data && typeof body.data.token === 'string'),
      'login: student user': () => !!(body && body.data && body.data.user && body.data.user.email === email),
    });
    return ok ? body.data.token : null;
  }
}

/** GET /{role}/dashboard. Returns true when it answered 200 with the standard envelope. */
export function getDashboard(token, role) {
  const res = request('GET', `/${role}/dashboard`, null, token, `${role}_dashboard`, m.dashboard);
  const body = json(res);
  return check(res, {
    [`${role} dashboard: status 200`]: (r) => r.status === 200,
    [`${role} dashboard: success envelope`]: () => !!body && body.success === true && !!body.data,
  });
}

/** GET /student/exams -> the load-test exam summary, or null. */
export function findExam(token) {
  const res = request('GET', '/student/exams', null, token, 'exams', m.exams);
  const body = json(res);
  const list = body && Array.isArray(body.data) ? body.data : [];
  const exam = list.find((e) => EXAM_TITLE_PATTERN.test(e.title || '')) || null;
  const ok = check(res, {
    'exams: status 200': (r) => r.status === 200,
    'exams: success envelope + data array': () => !!body && body.success === true && Array.isArray(body.data),
    'exams: load-test exam listed': () => exam !== null,
  });
  return ok ? exam : null;
}

/** GET /student/exams/{id}. Returns { ok, detail, clean } where clean = no prior attempt. */
export function getExamDetails(token, examId) {
  const res = request('GET', `/student/exams/${examId}`, null, token, 'exam_details', m.examDetails);
  const body = json(res);
  const detail = body && body.data ? body.data : null;
  const ok = check(res, {
    'exam details: status 200': (r) => r.status === 200,
    'exam details: id + questions_count': () => !!detail && detail.id === examId && detail.questions_count > 0,
    'exam details: window open': () => !!detail && detail.is_windowed === true,
  });
  const prior = detail && Array.isArray(detail.my_attempts) ? detail.my_attempts.length : 0;
  return { ok, detail, clean: prior === 0, prior };
}

/** POST /student/exams/{id}/start (compact_response, like the SPA). */
export function startAttempt(token, examId) {
  const res = request(
    'POST',
    `/student/exams/${examId}/start`,
    { rules_acknowledged: true, compact_response: true },
    token,
    'start',
    m.start
  );
  return { res, body: json(res) };
}

export function checkStart(r, label) {
  const d = r.body && r.body.data;
  return check(r.res, {
    [`${label}: status 201`]: (x) => x.status === 201,
    [`${label}: attempt created (id, in_progress)`]: () => !!d && typeof d.id === 'number' && d.status === 'in_progress',
  });
}

/** GET /student/attempts/{id}. Returns the attempt payload or null. */
export function getAttempt(token, attemptId, expectedQuestions) {
  const res = request('GET', `/student/attempts/${attemptId}`, null, token, 'attempt', m.attempt);
  const body = json(res);
  const a = body && body.data ? body.data : null;
  const ok = check(res, {
    'attempt: status 200': (r) => r.status === 200,
    'attempt: id matches, in_progress': () => !!a && a.id === attemptId && a.status === 'in_progress',
    'attempt: question snapshot with options': () =>
      !!a &&
      Array.isArray(a.questions) &&
      a.questions.length === expectedQuestions &&
      a.questions.every((q) => Array.isArray(q.options) && q.options.length >= 2),
  });
  return ok ? a : null;
}

/** Pick option ids the way the SPA submits them: option_ids, 1 for single, 2 for multiple. */
export function pickOptionIds(question) {
  const ids = question.options.map((o) => o.id);
  const want = question.question_type === 'multiple_choice' ? 2 : 1;
  const chosen = [];
  while (chosen.length < Math.min(want, ids.length)) {
    const id = ids[Math.floor(Math.random() * ids.length)];
    if (chosen.indexOf(id) === -1) chosen.push(id);
  }
  return chosen;
}

/** POST /student/attempts/{id}/answers; verifies the answer is echoed back as persisted. */
export function saveAnswer(token, attemptId, question) {
  const optionIds = pickOptionIds(question);
  const res = request(
    'POST',
    `/student/attempts/${attemptId}/answers`,
    { question_id: question.id, option_ids: optionIds },
    token,
    'answer',
    m.answer
  );
  const body = json(res);
  const saved =
    body && body.data && Array.isArray(body.data.questions)
      ? body.data.questions.find((q) => q.id === question.id)
      : null;
  const ok = check(res, {
    'answer: status 200': (r) => r.status === 200,
    'answer: persisted selection echoed': () =>
      !!saved &&
      Array.isArray(saved.selected_option_ids) &&
      optionIds.length === saved.selected_option_ids.length &&
      optionIds.every((id) => saved.selected_option_ids.indexOf(id) !== -1),
  });
  if (ok) m.answersSaved.add(1);
  return ok;
}

export function heartbeat(token, attemptId) {
  const res = request('POST', `/student/attempts/${attemptId}/heartbeat`, null, token, 'heartbeat', m.heartbeat);
  const body = json(res);
  return check(res, {
    'heartbeat: status 200': (r) => r.status === 200,
    'heartbeat: in_progress + timestamp': () =>
      !!body && !!body.data && body.data.status === 'in_progress' && !!body.data.last_heartbeat_at,
  });
}

export function submitAttempt(token, attemptId) {
  const res = request('POST', `/student/attempts/${attemptId}/submit`, null, token, 'submit', m.submit);
  const body = json(res);
  const d = body && body.data;
  return check(res, {
    'submit: status 200': (r) => r.status === 200,
    'submit: attempt closed': () =>
      !!d && d.attempt_id === attemptId && d.status !== 'in_progress' && !!d.submitted_at,
  });
}

export function warnFixture(email, prior) {
  m.fixtureNotClean.add(1);
  console.warn(
    `[fixture] ${email} already has ${prior} attempt(s); the exam allows 1. ` +
      'Reset on staging: php artisan loadtest:seed --students=<N> --reset-attempts (see load-tests/README.md).'
  );
  // A prior attempt means a dirty fixture or a duplicate attempt: continuing would
  // test the wrong thing, so stop the whole run.
  abortRun('fixture student already has an attempt (reset required); refusing to continue');
}
