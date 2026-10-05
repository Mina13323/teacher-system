// Concurrent start-attempt behaviour (STAGING ONLY).
// Every VU logs in (staggered over the ramp), waits at a shared barrier time, then fires
// POST /student/exams/{id}/start at the same moment. With DOUBLE_START=1 (default) each
// student sends two simultaneous start requests (double-click / second tab): the real
// unique active_key rule must yield ONE attempt and the same id for both responses.
// Attempts are left in progress (not submitted); reset before the next run (see README).
import { check, sleep } from 'k6';
import http from 'k6/http';
import { Counter } from 'k6/metrics';
import {
  BASE_URL, VUS, START_INDEX, RAMP_SECONDS,
  studentForThisVu, oneShotScenario, rampDelay, buildThresholds, m, json,
  login, findExam, getExamDetails, startAttempt, checkStart, warnFixture,
} from './k6-config.js';

const DOUBLE_START = (__ENV.DOUBLE_START || '1') !== '0';
// Seconds after the end of the ramp at which every VU fires its start request.
const BARRIER_GRACE_SECONDS = parseInt(__ENV.BARRIER_GRACE_SECONDS || '15', 10);

export const options = {
  scenarios: {
    start_storm: oneShotScenario(BARRIER_GRACE_SECONDS + 180),
  },
  thresholds: Object.assign(buildThresholds(['login', 'exams', 'exam_details', 'start']), {
    start_double_mismatch: ['count==0'],
  }),
  tags: { suite: 'exam-start-storm' },
};

const doubleMismatch = new Counter('start_double_mismatch');

export function setup() {
  const fireAt = Date.now() + (RAMP_SECONDS + BARRIER_GRACE_SECONDS) * 1000;
  console.log(
    `exam-start-storm: ${VUS} VU(s), students ${START_INDEX}..${START_INDEX + VUS - 1}, ` +
      `ramp ${RAMP_SECONDS}s, barrier +${BARRIER_GRACE_SECONDS}s, double start ${DOUBLE_START}`
  );
  return { fireAt };
}

export default function (data) {
  const student = studentForThisVu();
  let ok = false;

  try {
    rampDelay();

    const token = login(student.email);
    if (!token) return;
    const exam = findExam(token);
    if (!exam) return;
    const details = getExamDetails(token, exam.id);
    if (!details.ok) return;
    if (!details.clean) {
      warnFixture(student.email, details.prior);
      return;
    }

    // Barrier: wait until the shared fire time (fires immediately if already past).
    const waitMs = data.fireAt - Date.now();
    if (waitMs > 0) sleep(waitMs / 1000);

    if (!DOUBLE_START) {
      ok = checkStart(startAttempt(token, exam.id), 'start');
      return;
    }

    // Two simultaneous starts for the SAME student, issued in parallel.
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    };
    const req = () => ({
      method: 'POST',
      url: `${BASE_URL}/api/v1/student/exams/${exam.id}/start`,
      body: JSON.stringify({ rules_acknowledged: true, compact_response: true }),
      params: { headers, timeout: '30s', tags: { endpoint: 'start', name: 'POST /student/exams/:id/start' } },
    });
    const [a, b] = http.batch([req(), req()]);
    [a, b].forEach((r) => {
      m.http5xx.add(r.status >= 500);
      m.start.add(r.timings.duration);
    });

    const da = json(a);
    const db = json(b);
    const both201 = check(null, {
      'start x2: both responses 201': () => a.status === 201 && b.status === 201,
    });
    const sameAttempt = !!(da && db && da.data && db.data && da.data.id === db.data.id);
    const exactlyOneNew =
      !!(da && db && da.data && db.data) && [da.data.already_open, db.data.already_open].filter((x) => x === false).length === 1;
    check(null, {
      'start x2: both return the same attempt id': () => sameAttempt,
      'start x2: exactly one created, one restored (already_open)': () => exactlyOneNew,
    });
    if (!(both201 && sameAttempt && exactlyOneNew)) doubleMismatch.add(1);
    ok = both201 && sameAttempt && exactlyOneNew;
  } finally {
    m.flowFailed.add(!ok);
    if (ok) m.flowCompleted.add(1);
  }
}
