// Logic of the single-student staging smoke check (NOT a load test).
// Kept free of process/CLI concerns so scripts/loadtest-smoke.spec.js can drive
// it with a mocked fetch: nothing here talks to a real server unless the CLI
// (scripts/loadtest-smoke.mjs) hands it the real fetch.

export const STAGING_URL = 'https://staging.maherelmasry.com';
export const EXAM_TITLE = 'Load Test Exam (load-test-exam)';
export const EMAIL_DOMAIN = 'staging.maherelmasry.com';
export const MIN_PASSWORD_LENGTH = 16;
// Statuses a submitted attempt may report; "in_progress" and "expired" are failures.
export const FINAL_STATUSES = ['submitted', 'grading', 'published', 'graded'];

const REQUEST_TIMEOUT_MS = 30_000;

export class SmokeFailure extends Error {
  constructor(step, message) {
    super(message);
    this.name = 'SmokeFailure';
    this.step = step;
  }
}

/**
 * Accept only https://staging.maherelmasry.com (a single trailing slash is
 * harmless). Everything else - production, other hosts, ports, http, paths,
 * credentials, queries, malformed input - is rejected before any request.
 */
export function validateBaseUrl(raw) {
  const value = typeof raw === 'string' ? raw.trim() : '';
  if (value === '') {
    throw new SmokeFailure('config', `BASE_URL is required and must be exactly ${STAGING_URL}`);
  }
  if (!/^https:\/\/staging\.maherelmasry\.com\/?$/i.test(value)) {
    throw new SmokeFailure('config', `REFUSING TO RUN: BASE_URL must be exactly ${STAGING_URL} (got "${value.slice(0, 80)}")`);
  }
  let parsed;
  try {
    parsed = new URL(value);
  } catch {
    throw new SmokeFailure('config', 'REFUSING TO RUN: BASE_URL is not a valid URL');
  }
  if (parsed.protocol !== 'https:' || parsed.hostname !== 'staging.maherelmasry.com' || parsed.port !== '') {
    throw new SmokeFailure('config', `REFUSING TO RUN: BASE_URL must be exactly ${STAGING_URL}`);
  }
  return STAGING_URL;
}

export function validateStudentNumber(raw) {
  const text = String(raw === undefined || raw === null || raw === '' ? 1 : raw).trim();
  if (!/^\d{1,4}$/.test(text) || Number(text) < 1) {
    throw new SmokeFailure('config', 'The student number must be an integer between 1 and 9999.');
  }
  return text.padStart(4, '0');
}

export function validatePassword(raw) {
  if (typeof raw !== 'string' || raw.trim() === '') {
    throw new SmokeFailure('config', 'LOADTEST_PASSWORD is required (the staging fixture password). It is never printed.');
  }
  if (raw.length < MIN_PASSWORD_LENGTH) {
    throw new SmokeFailure('config', `LOADTEST_PASSWORD must be at least ${MIN_PASSWORD_LENGTH} characters. It is never printed.`);
  }
  return raw;
}

const sameSet = (a, b) => {
  const s = (x) => JSON.stringify((x || []).slice().sort((p, q) => p - q));
  return s(a) === s(b);
};

/**
 * Run the full flow. Throws SmokeFailure on the first problem; resolves with a
 * summary only when every step - including persistence and finalization - was
 * verified. Tokens and the password never appear in messages or logs.
 */
export async function runSmoke({ base, email, password, fetchImpl, log = () => {}, now = () => Date.now() }) {
  let token = null;
  let attemptId = null;

  async function call(step, method, path, body) {
    let res;
    try {
      res = await fetchImpl(`${base}/api/v1${path}`, {
        method,
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
        signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
      });
    } catch (e) {
      throw new SmokeFailure(step, `${step}: request failed (${e && e.name ? e.name : 'network error'})`);
    }
    let json = null;
    try {
      json = await res.json();
    } catch {
      json = null;
    }
    log(`${res.ok ? 'OK  ' : 'FAIL'} ${res.status} ${step}`);
    return { status: res.status, ok: res.ok, json };
  }

  const need = (step, cond, message) => {
    if (!cond) throw new SmokeFailure(step, `${step}: ${message}`);
  };
  const dataOf = (step, r, expectedStatus = 200) => {
    need(step, r.status === expectedStatus, `expected HTTP ${expectedStatus}, got ${r.status}`);
    need(step, r.json && typeof r.json === 'object' && r.json.success !== false, 'response is not a successful JSON envelope');
    need(step, r.json.data !== undefined && r.json.data !== null, 'response has no data');
    return r.json.data;
  };

  try {
    // 1. Sign in.
    const login = dataOf('login', await call('login', 'POST', '/auth/login', { email, password }));
    need('login', typeof login.token === 'string' && login.token.length > 0, 'no token in the response');
    need('login', login.user && login.user.email === email, 'signed in as a different account than requested');
    token = login.token;

    // 2. Find the exact fixture exam - never fall back to "the first exam".
    const listData = dataOf('exam list', await call('exam list', 'GET', '/student/exams'));
    const list = Array.isArray(listData) ? listData : Array.isArray(listData.data) ? listData.data : null;
    need('exam list', list !== null, 'exam list has an unexpected shape');
    const matches = list.filter((e) => e && e.title === EXAM_TITLE);
    need('exam list', matches.length === 1, `expected exactly one exam titled "${EXAM_TITLE}", found ${matches.length} (listed: ${list.length})`);
    const examId = matches[0].id;
    need('exam list', Number.isInteger(examId), 'the fixture exam has no numeric id');

    // 3. Verify identity, eligibility and a clean attempt state BEFORE starting.
    const exam = dataOf('exam details', await call('exam details', 'GET', `/student/exams/${examId}`));
    need('exam details', exam.id === examId && exam.title === EXAM_TITLE, 'details are not the fixture exam');
    need('exam details', exam.max_attempts === 1, `max_attempts is ${exam.max_attempts}, expected 1`);
    need('exam details', Number.isInteger(exam.questions_count) && exam.questions_count > 0, 'exam reports no questions');
    need('exam details', Number.isInteger(exam.duration_minutes) && exam.duration_minutes > 0, 'exam reports no valid duration');
    need('exam details', exam.is_windowed === true, 'the exam window is not open');
    const opens = Date.parse(exam.starts_at);
    const closes = Date.parse(exam.ends_at);
    need('exam details', !isNaN(opens) && !isNaN(closes) && opens <= now() && now() < closes, 'now is outside the exam window');
    need('exam details', Array.isArray(exam.my_attempts), 'details did not report the student\'s attempts');
    need(
      'exam details',
      exam.my_attempts.length === 0,
      `the fixture student already has ${exam.my_attempts.length} attempt(s); refusing to start. Reset the fixture on staging first (operator action): php artisan loadtest:seed --reset-attempts`
    );

    // 4. Start.
    const start = dataOf('start', await call('start', 'POST', `/student/exams/${examId}/start`, { rules_acknowledged: true, compact_response: true }), 201);
    need('start', Number.isInteger(start.id), 'no attempt id in the response');
    attemptId = start.id;
    need('start', start.exam_id === examId, 'attempt belongs to a different exam');
    need('start', start.status === 'in_progress', `new attempt is "${start.status}"`);
    need('start', start.already_open !== true, 'attempt was already open (dirty fixture)');
    need('start', !isNaN(Date.parse(start.expires_at)) && Date.parse(start.expires_at) > now(), 'attempt has no valid future deadline');

    // 5. Read the attempt and choose one single-choice answer.
    const attempt = dataOf('attempt', await call('attempt', 'GET', `/student/attempts/${attemptId}`));
    need('attempt', attempt.id === attemptId && attempt.status === 'in_progress', 'attempt is not the open attempt just started');
    need('attempt', Array.isArray(attempt.questions) && attempt.questions.length === exam.questions_count, 'attempt question count does not match the exam');
    const question = attempt.questions.find((q) => q && q.question_type !== 'multiple_choice' && Array.isArray(q.options) && q.options.length >= 2);
    need('attempt', !!question, 'no single-choice question with options found');
    const chosen = [question.options[0].id];
    need('attempt', Number.isInteger(chosen[0]), 'option has no numeric id');

    // 6. Save the answer; the response must echo the stored selection.
    const saved = dataOf('answer', await call('answer', 'POST', `/student/attempts/${attemptId}/answers`, { question_id: question.id, option_ids: chosen }));
    const echoed = Array.isArray(saved.questions) ? saved.questions.find((q) => q && q.id === question.id) : null;
    need('answer', !!echoed && sameSet(echoed.selected_option_ids, chosen), 'the saved selection was not echoed back as sent');

    // 7. Heartbeat.
    const beat = dataOf('heartbeat', await call('heartbeat', 'POST', `/student/attempts/${attemptId}/heartbeat`));
    need('heartbeat', beat.status === 'in_progress' && !!beat.last_heartbeat_at, 'heartbeat did not confirm an in-progress attempt');

    // 8. Submit; the response must name a final status.
    const submit = dataOf('submit', await call('submit', 'POST', `/student/attempts/${attemptId}/submit`));
    need('submit', submit.attempt_id === attemptId, 'submit response is for a different attempt');
    need('submit', FINAL_STATUSES.includes(submit.status), `attempt status after submit is "${submit.status}", expected one of ${FINAL_STATUSES.join('/')}`);
    need('submit', !!submit.submitted_at, 'submit response has no submitted_at');

    // 9. Read back: finalized, and exactly the submitted answer persisted.
    const final = dataOf('verify', await call('verify', 'GET', `/student/attempts/${attemptId}`));
    need('verify', final.id === attemptId, 'read-back is for a different attempt');
    need('verify', final.status !== 'in_progress', 'attempt is still in progress after submit');
    need('verify', FINAL_STATUSES.includes(final.status), `read-back status is "${final.status}"`);
    need('verify', Array.isArray(final.questions), 'read-back has no questions');
    const stored = final.questions.find((q) => q && q.id === question.id);
    need('verify', !!stored && sameSet(stored.selected_option_ids, chosen), 'the submitted answer was NOT persisted as sent');
    const extra = final.questions.filter((q) => q && q.id !== question.id && Array.isArray(q.selected_option_ids) && q.selected_option_ids.length > 0);
    need('verify', extra.length === 0, `${extra.length} unexpected answer(s) exist on questions the smoke test did not answer`);

    return { attemptId, examId, status: final.status, verified: ['login', 'exact fixture exam', 'clean state', 'start', 'answer echo', 'heartbeat', 'submit status', 'persisted answer'] };
  } finally {
    // Token cleanup through the existing logout endpoint (revokes only this token).
    if (token) {
      try {
        const out = await call('logout', 'POST', '/auth/logout');
        if (out.status !== 200) log('WARN token cleanup: logout did not return 200; the token remains until loadtest:clean');
      } catch {
        log('WARN token cleanup: logout request failed; the token remains until loadtest:clean');
      }
    }
  }
}
