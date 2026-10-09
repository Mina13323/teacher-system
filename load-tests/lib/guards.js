// Pure decision helpers for the k6 suite. NO k6 imports on purpose: the same
// file is imported by k6 (load-tests/*.js) and by Vitest (guards.spec.js), so
// every abort rule below is unit-tested without running a load test.
//
// Each function returns a short fixed-text reason (or null / []) and never
// echoes response bodies, tokens or credentials.

/** Count-based, abort-on-first-failure threshold (k6 evaluates it every ~2 s). */
export const ABORT_ON_ANY = { threshold: 'count==0', abortOnFail: true, delayAbortEval: '0s' };

const DB_CONNECT_PATTERN = /SQLSTATE\[[A-Z0-9]+\]\s*\[2002\]|connection refused|DB_CONNECT_(FINAL|TRANSIENT)|service_busy/i;

/**
 * Critical-signal classifier for one HTTP response.
 * `status` 0 is a transport failure (k6 could not talk to the server at all).
 * @returns {string|null} abort reason, or null when the response is not critical
 */
export function criticalSignal({ status, body, endpoint }) {
  const where = endpoint ? ` on ${endpoint}` : '';
  const text = typeof body === 'string' && body.length < 20000 ? body : '';

  if (status === 0) return `transport failure (no HTTP response)${where}`;
  if (status >= 500) {
    if (DB_CONNECT_PATTERN.test(text)) {
      return `database connection failure (SQLSTATE 2002 / connection refused / service busy), HTTP ${status}${where}`;
    }
    return `HTTP ${status}${where}`;
  }
  if (status === 401) return `unexpected HTTP 401${where}`;
  if (text !== '' && /SQLSTATE\[[A-Z0-9]+\]\s*\[2002\]/i.test(text)) {
    return `SQLSTATE 2002 in a non-5xx response${where}`;
  }
  return null;
}

/**
 * The start response must describe one fresh, open attempt for the right exam.
 * @returns {string|null}
 */
export function startedAttemptProblem(attempt, { examId, expectedQuestions, nowMs }) {
  if (!attempt || typeof attempt !== 'object') return 'start returned no attempt';
  if (typeof attempt.id !== 'number') return 'start returned an attempt without a numeric id';
  if (attempt.exam_id !== undefined && attempt.exam_id !== examId) return 'attempt belongs to a different exam';
  if (attempt.status !== 'in_progress') return `new attempt is "${attempt.status}", expected in_progress`;
  if (attempt.already_open === true) return 'start returned an already-open attempt (duplicate attempt / dirty fixture)';
  if (attempt.attempt_number !== undefined && attempt.attempt_number !== 1) {
    return `attempt_number is ${attempt.attempt_number}, expected 1`;
  }
  const expires = Date.parse(attempt.expires_at);
  if (isNaN(expires)) return 'attempt has no valid expires_at';
  if (nowMs !== undefined && expires <= nowMs) return 'attempt is already past its deadline';
  if (expectedQuestions !== undefined && Array.isArray(attempt.questions) && attempt.questions.length !== expectedQuestions) {
    return `attempt has ${attempt.questions.length} questions, exam has ${expectedQuestions}`;
  }
  return null;
}

/**
 * Compare acknowledged answers with what the server returns afterwards.
 * @param {Object<string, number[]>} saved  question id -> option ids acknowledged by the server
 * @param {Object<string, number[]>} stored question id -> option ids read back
 * @returns {string[]} ids of questions whose stored selection differs
 */
export function mismatchedAnswers(saved, stored) {
  const sorted = (a) => JSON.stringify((a || []).slice().sort((x, y) => x - y));
  return Object.keys(saved).filter((qid) => sorted(saved[qid]) !== sorted(stored[qid]));
}

/**
 * Refuse a run whose virtual users could be killed before the exam is
 * finalized and verified (k6 maxDuration + gracefulStop would interrupt them
 * and leave attempts in progress).
 * @returns {string|null}
 */
export function examDurationProblem({
  durationMinutes,
  examMinutes,
  openAtSeconds,
  startPacingMaxSeconds,
  submitSpreadSeconds,
  unreadMaxSeconds,
  slackSeconds,
  maxDurationSeconds,
}) {
  if (typeof durationMinutes !== 'number' || !isFinite(durationMinutes) || durationMinutes <= 0) {
    return 'the exam details did not report a usable duration_minutes';
  }
  if (durationMinutes > examMinutes) {
    return `the exam lasts ${durationMinutes} min but EXAM_MINUTES=${examMinutes}; raise EXAM_MINUTES or re-seed a shorter exam (--duration)`;
  }
  const needed = Math.ceil(
    openAtSeconds + startPacingMaxSeconds + durationMinutes * 60 + submitSpreadSeconds + unreadMaxSeconds + slackSeconds
  );
  if (needed > maxDurationSeconds) {
    return `one student needs about ${needed}s (open ${openAtSeconds}s + start pacing + ${durationMinutes} min exam + submit spread + result + notification refresh) but the scenario allows ${maxDurationSeconds}s`;
  }
  return null;
}

/** A closed attempt (422) long before its deadline means the state is inconsistent. */
export function closedTooEarly(msUntilDeadline, toleranceMs) {
  return msUntilDeadline > toleranceMs;
}

/** Status values a submitted attempt may legitimately report. */
export const FINAL_STATUSES = ['submitted', 'grading', 'published', 'graded'];

export function submitProblem(status, data, attemptId) {
  if (status === 422) return null; // already finalized (deadline job or earlier submit)
  if (status !== 200) return `submit returned HTTP ${status}`;
  if (!data || data.attempt_id !== attemptId) return 'submit response is for a different attempt';
  if (FINAL_STATUSES.indexOf(data.status) === -1) return `submit left the attempt "${data.status}"`;
  return null;
}
