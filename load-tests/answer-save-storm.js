// Concurrent answer-save behaviour (STAGING ONLY).
// Setup phase (staggered over the ramp): login -> details -> start -> get attempt.
// Then every VU waits at a shared barrier and saves answers back-to-back with a short gap,
// so POST /student/attempts/{id}/answers is hit by all VUs at once. Each save is verified by
// the persisted selection echoed in the response. Optionally submits at the end (SUBMIT=1).
import { sleep } from 'k6';
import {
  VUS, START_INDEX, RAMP_SECONDS,
  studentForThisVu, oneShotScenario, rampDelay, buildThresholds, m,
  login, findExam, getExamDetails, startAttempt, checkStart, getAttempt,
  saveAnswer, heartbeat, submitAttempt, warnFixture,
} from './k6-config.js';

// Saves per VU. Above the question count the VU re-saves earlier questions (update path).
// Hard cap 90 keeps one student under the app's 120 requests/min per-user API limit.
const ANSWERS_PER_VU = Math.min(parseInt(__ENV.ANSWERS_PER_VU || '20', 10), 90);
const ANSWER_GAP_MS = parseInt(__ENV.ANSWER_GAP_MS || '250', 10);
const SUBMIT = (__ENV.SUBMIT || '1') !== '0';
const BARRIER_GRACE_SECONDS = parseInt(__ENV.BARRIER_GRACE_SECONDS || '20', 10);

export const options = {
  scenarios: {
    answer_storm: oneShotScenario(BARRIER_GRACE_SECONDS + 300),
  },
  thresholds: buildThresholds(
    SUBMIT
      ? ['login', 'exams', 'exam_details', 'start', 'attempt', 'answer', 'heartbeat', 'submit']
      : ['login', 'exams', 'exam_details', 'start', 'attempt', 'answer', 'heartbeat']
  ),
  tags: { suite: 'answer-save-storm' },
};

export function setup() {
  const fireAt = Date.now() + (RAMP_SECONDS + BARRIER_GRACE_SECONDS) * 1000;
  console.log(
    `answer-save-storm: ${VUS} VU(s), students ${START_INDEX}..${START_INDEX + VUS - 1}, ramp ${RAMP_SECONDS}s, ` +
      `barrier +${BARRIER_GRACE_SECONDS}s, ${ANSWERS_PER_VU} saves/VU, gap ${ANSWER_GAP_MS}ms, submit ${SUBMIT}`
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

    const start = startAttempt(token, exam.id);
    if (!checkStart(start, 'start')) return;
    const attemptId = start.body.data.id;
    const attempt = getAttempt(token, attemptId, details.detail.questions_count);
    if (!attempt) return;

    const waitMs = data.fireAt - Date.now();
    if (waitMs > 0) sleep(waitMs / 1000);

    let allSaved = true;
    for (let i = 0; i < ANSWERS_PER_VU; i++) {
      const question = attempt.questions[i % attempt.questions.length];
      allSaved = saveAnswer(token, attemptId, question) && allSaved;
      if (ANSWER_GAP_MS > 0) sleep((ANSWER_GAP_MS * (0.5 + Math.random())) / 1000);
    }
    allSaved = heartbeat(token, attemptId) && allSaved;

    ok = allSaved;
    if (SUBMIT) ok = submitAttempt(token, attemptId) && ok;
  } finally {
    m.flowFailed.add(!ok);
    if (ok) m.flowCompleted.add(1);
  }
}
