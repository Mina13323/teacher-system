// Realistic whole-exam run (STAGING ONLY): the same request schedule as the
// real exam screen, from sign-in to the result, so the measured new-DB-
// connection rate matches what a class produces.
//
// Per student (one fixture student per VU, one iteration):
//   arrive during the ramp: login -> dashboard -> exam list -> exam details
//   wait for the common opening moment, then the client's start pacing (0-45 s)
//   start (compact) -> exam loop until the deadline:
//     /time ping every 12-18 s (no DB), status check every ~2 min,
//     an answer every ANSWER_GAP_MIN..MAX s (default 40-80 s, about 1/min),
//     an integrity event at INTEGRITY_PER_MIN (default 0.3/min)
//   deadline: submit at a random point 0-50 s after it (the client's spread)
//   result: read the attempt once and check every saved answer is stored
//   unread badge refresh 60-180 s after the submit
//
// Seed a short exam first so the run reaches the deadline, e.g. on staging:
//   php artisan loadtest:seed --students=374 --reset-attempts --duration=15
// Run (one step at a time):
//   BASE_URL=https://staging.maherelmasry.com STAGE=50 k6 run load-tests/exam-realistic.js
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import {
  VUS, START_INDEX, RAMP_SECONDS,
  studentForThisVu, rampDelay, think, buildThresholds, m, json, request,
  login, getDashboard, findExam, getExamDetails, warnFixture,
} from './k6-config.js';

const num = (name, dflt) => (__ENV[name] === undefined || __ENV[name] === '' ? dflt : parseFloat(__ENV[name]));

// Mirrors resources/js/utils/examRequestPacing.js.
const PING_MIN_S = 12;
const PING_MAX_S = 18;
const STATUS_GAP_S = 115;
const START_PACING_MAX_S = num('START_PACING_MAX_S', 45);
const SUBMIT_SPREAD_S = 50;
const UNREAD_MIN_S = 60;
const UNREAD_MAX_S = 180;
const FINAL_ANSWER_MARGIN_S = 10;

const ANSWER_GAP_MIN = num('ANSWER_GAP_MIN', 40);
const ANSWER_GAP_MAX = num('ANSWER_GAP_MAX', 80);
const INTEGRITY_PER_MIN = num('INTEGRITY_PER_MIN', 0.3);
// A zero-risk return-to-page event by default, so the warning threshold never
// ends attempts during the run (TAB_SWITCH counts toward it).
const INTEGRITY_EVENT = __ENV.INTEGRITY_EVENT || 'WINDOW_FOCUS';
// Seconds after the run starts at which the exam "opens" for everyone.
const OPEN_AT_S = num('OPEN_AT_S', RAMP_SECONDS + 15);
// Upper bound for one student's whole run (arrival + exam + deadline + result).
const EXAM_MINUTES = num('EXAM_MINUTES', 15);

const t = {
  time: new Trend('step_time_ping_ms', true),
  integrity: new Trend('step_integrity_ms', true),
  result: new Trend('step_result_ms', true),
  unread: new Trend('step_unread_ms', true),
};
const answersLost = new Counter('answers_lost');
const submitNotFinal = new Counter('submit_not_final');
const serviceBusy = new Counter('service_busy_503');

export const options = {
  scenarios: {
    exam_realistic: {
      executor: 'per-vu-iterations',
      vus: VUS,
      iterations: 1,
      maxDuration: `${Math.ceil(OPEN_AT_S + START_PACING_MAX_S + EXAM_MINUTES * 60 + SUBMIT_SPREAD_S + UNREAD_MAX_S + 120)}s`,
      gracefulStop: '60s',
    },
  },
  thresholds: Object.assign(
    buildThresholds(['login', 'exams', 'exam_details', 'start', 'answer', 'heartbeat', 'submit', 'student_dashboard']),
    {
      // The acceptance criteria for a step (see the 500-student report).
      'http_req_duration{endpoint:answer}': ['p(95)<1000'],
      'http_req_duration{endpoint:heartbeat}': ['p(95)<1000'],
      'http_req_duration{endpoint:submit}': ['p(95)<1000'],
      answers_lost: ['count==0'],
      submit_not_final: ['count==0'],
      service_busy_503: [{ threshold: 'count==0', abortOnFail: true, delayAbortEval: '10s' }],
    }
  ),
  tags: { suite: 'exam-realistic' },
};

export function setup() {
  console.log(
    `exam-realistic: ${VUS} VU(s), students ${START_INDEX}..${START_INDEX + VUS - 1}, ramp ${RAMP_SECONDS}s, ` +
      `exam opens at +${OPEN_AT_S}s, answers every ${ANSWER_GAP_MIN}-${ANSWER_GAP_MAX}s, integrity ${INTEGRITY_PER_MIN}/min`
  );
  return { t0: Date.now() };
}

const between = (a, b) => a + Math.random() * (b - a);
const expo = (perMin) => (perMin > 0 ? (-Math.log(1 - Math.random()) * 60) / perMin : Infinity);

function track(res) {
  if (res.status === 503) serviceBusy.add(1);
  return res;
}

function timePing(offset) {
  const sent = Date.now();
  const res = request('GET', '/time', null, null, 'time', t.time);
  const body = json(res);
  if (res.status === 200 && body && body.data && body.data.server_time_ms) {
    offset.ms = body.data.server_time_ms - (sent + Date.now()) / 2;
  }
}

function statusCheck(token, attemptId) {
  const res = track(request('POST', `/student/attempts/${attemptId}/heartbeat`, null, token, 'heartbeat', m.heartbeat));
  const body = json(res);
  check(res, { 'heartbeat: 200 or closed (422)': (r) => r.status === 200 || r.status === 422 });
  return body && body.data ? body.data : null;
}

function saveAnswer(token, attemptId, question, saved) {
  const ids = question.options.map((o) => o.id);
  const want = question.question_type === 'multiple_choice' ? 2 : 1;
  const chosen = [];
  while (chosen.length < Math.min(want, ids.length)) {
    const id = ids[Math.floor(Math.random() * ids.length)];
    if (chosen.indexOf(id) === -1) chosen.push(id);
  }
  const res = track(request(
    'POST',
    `/student/attempts/${attemptId}/answers`,
    { question_id: question.id, option_ids: chosen, compact_response: true },
    token,
    'answer',
    m.answer
  ));
  const body = json(res);
  const q = body && body.data && body.data.question;
  const ok = check(res, {
    'answer: 200': (r) => r.status === 200,
    'answer: stored selection acknowledged': () =>
      !!q && Array.isArray(q.selected_option_ids) &&
      q.selected_option_ids.length === chosen.length &&
      chosen.every((id) => q.selected_option_ids.indexOf(id) !== -1),
  });
  if (ok) {
    saved[question.id] = chosen.slice().sort((a, b) => a - b);
    m.answersSaved.add(1);
  }
  return res.status;
}

function integrityEvent(token, attemptId) {
  const res = track(request(
    'POST',
    `/student/attempts/${attemptId}/integrity-events`,
    { event_type: INTEGRITY_EVENT },
    token,
    'integrity',
    t.integrity
  ));
  check(res, { 'integrity: recorded (201) or closed (422)': (r) => r.status === 201 || r.status === 422 });
  return res.status;
}

export default function (data) {
  const student = studentForThisVu();
  const flowStart = Date.now();
  let ok = false;

  try {
    rampDelay();

    const token = login(student.email);
    if (!token) return;
    think(1, 3);
    getDashboard(token, 'student');
    think(2, 6);
    const exam = findExam(token);
    if (!exam) return;
    think(1, 3);
    const details = getExamDetails(token, exam.id);
    if (!details.ok) return;
    if (!details.clean) {
      warnFixture(student.email, details.prior);
      return;
    }

    // Everyone presses Start at the opening moment; the client then waits
    // a random 0-45 s before sending it (start pacing).
    const openAt = data.t0 + OPEN_AT_S * 1000;
    if (Date.now() < openAt) sleep((openAt - Date.now()) / 1000);
    sleep(between(0, START_PACING_MAX_S));

    const startRes = track(request('POST', `/student/exams/${exam.id}/start`, { rules_acknowledged: true }, token, 'start', m.start));
    const startBody = json(startRes);
    const attempt = startBody && startBody.data;
    if (!check(startRes, {
      'start: 201': (r) => r.status === 201,
      'start: in progress with questions': () => !!attempt && attempt.status === 'in_progress' && Array.isArray(attempt.questions),
    })) return;
    if (attempt.already_open) {
      warnFixture(student.email, 1);
      return;
    }

    const offset = { ms: 0 };
    timePing(offset);
    const serverNow = () => Date.now() + offset.ms;
    const deadline = new Date(attempt.expires_at).getTime();
    const questions = attempt.questions.filter((q) => Array.isArray(q.options) && q.options.length >= 2);
    const saved = {};

    let nextPing = Date.now() + between(0, PING_MAX_S) * 1000;
    let lastStatus = Date.now();
    let nextAnswer = Date.now() + between(ANSWER_GAP_MIN, ANSWER_GAP_MAX) * 1000;
    let nextIntegrity = Date.now() + expo(INTEGRITY_PER_MIN) * 1000;
    let qIndex = 0;
    let closed = false;

    while (!closed && serverNow() < deadline - FINAL_ANSWER_MARGIN_S * 1000) {
      const now = Date.now();
      if (now >= nextAnswer && questions.length) {
        // Mostly new questions, sometimes a change to an earlier one.
        const q = qIndex < questions.length && Math.random() > 0.3
          ? questions[qIndex++]
          : questions[Math.floor(Math.random() * Math.max(1, Math.min(qIndex, questions.length)))];
        closed = saveAnswer(token, attempt.id, q, saved) === 422;
        nextAnswer = now + between(ANSWER_GAP_MIN, ANSWER_GAP_MAX) * 1000;
      } else if (now >= nextIntegrity) {
        closed = integrityEvent(token, attempt.id) === 422;
        nextIntegrity = now + expo(INTEGRITY_PER_MIN) * 1000;
      } else if (now >= nextPing) {
        if (now - lastStatus >= STATUS_GAP_S * 1000) {
          const s = statusCheck(token, attempt.id);
          lastStatus = now;
          closed = !!s && s.status !== undefined && s.status !== 'in_progress';
        } else {
          timePing(offset);
        }
        nextPing = now + between(PING_MIN_S, PING_MAX_S) * 1000;
      }
      const wakeAt = Math.min(nextAnswer, nextIntegrity, nextPing, deadline - FINAL_ANSWER_MARGIN_S * 1000 - offset.ms);
      sleep(Math.max(0.2, (wakeAt - Date.now()) / 1000));
    }

    // The screen locks at the deadline; the submit goes out 0-50 s later.
    const untilDeadline = (deadline - serverNow()) / 1000;
    if (untilDeadline > 0) sleep(untilDeadline);
    sleep(between(0, SUBMIT_SPREAD_S));
    const submitRes = track(request('POST', `/student/attempts/${attempt.id}/submit`, null, token, 'submit', m.submit));
    const sub = json(submitRes);
    const finalStatus = sub && sub.data && sub.data.status;
    const finalized = check(submitRes, {
      'submit: 200 (or 422 when already finalized)': (r) => r.status === 200 || r.status === 422,
    });
    if (submitRes.status === 200 && finalStatus === 'in_progress') submitNotFinal.add(1);

    // Result: read the attempt once and compare every acknowledged answer.
    const resultRes = track(request('GET', `/student/attempts/${attempt.id}`, null, token, 'result', t.result));
    const result = json(resultRes);
    const stored = {};
    ((result && result.data && result.data.questions) || []).forEach((q) => {
      stored[q.id] = (q.selected_option_ids || []).slice().sort((a, b) => a - b);
    });
    const finalOk = check(resultRes, {
      'result: 200 and no longer in progress': (r) => r.status === 200 && !!result && result.data.status !== 'in_progress',
    });
    if (!finalOk) submitNotFinal.add(1);
    Object.keys(saved).forEach((qid) => {
      if (JSON.stringify(saved[qid]) !== JSON.stringify(stored[qid] || [])) answersLost.add(1);
    });

    sleep(between(UNREAD_MIN_S, UNREAD_MAX_S));
    track(request('GET', '/notifications/unread-count', null, token, 'unread', t.unread));

    ok = finalized && finalOk;
  } finally {
    m.flowFailed.add(!ok);
    if (ok) {
      m.flowCompleted.add(1);
      m.flow.add(Date.now() - flowStart);
    }
  }
}
