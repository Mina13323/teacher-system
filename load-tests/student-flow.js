// Realistic end-to-end student journey (STAGING ONLY).
//   login -> exam list -> exam details -> start -> get attempt -> answers (+heartbeats) -> submit
// One fixture student per VU, one iteration per VU (the exam allows a single attempt).
import { sleep } from 'k6';
import {
  VUS, START_INDEX, RAMP_SECONDS, THINK_SCALE,
  studentForThisVu, oneShotScenario, rampDelay, think, buildThresholds, m,
  login, findExam, getExamDetails, startAttempt, checkStart, getAttempt,
  saveAnswer, heartbeat, submitAttempt, warnFixture,
} from './k6-config.js';

const ANSWERS_PER_ATTEMPT = parseInt(__ENV.ANSWERS_PER_ATTEMPT || '8', 10);
// A heartbeat is sent after every Nth saved answer, plus once before submitting.
const HEARTBEAT_EVERY = parseInt(__ENV.HEARTBEAT_EVERY || '2', 10);

export const options = {
  scenarios: {
    student_flow: oneShotScenario(900),
  },
  thresholds: buildThresholds([
    'login', 'exams', 'exam_details', 'start', 'attempt', 'answer', 'heartbeat', 'submit',
  ]),
  tags: { suite: 'student-flow' },
};

export function setup() {
  console.log(
    `student-flow: ${VUS} VU(s), students ${START_INDEX}..${START_INDEX + VUS - 1}, ` +
      `ramp ${RAMP_SECONDS}s, think scale ${THINK_SCALE}`
  );
}

export default function () {
  const student = studentForThisVu();
  const flowStart = Date.now();
  let ok = false;

  try {
    rampDelay();

    const token = login(student.email);
    if (!token) return;
    think(1, 3);

    const exam = findExam(token);
    if (!exam) return;
    think(1, 2);

    const details = getExamDetails(token, exam.id);
    if (!details.ok) return;
    if (!details.clean) {
      // Never resume or reuse an old attempt: it would not be a clean start.
      warnFixture(student.email, details.prior);
      return;
    }
    const questionCount = details.detail.questions_count;
    think(2, 5); // reading the rules before acknowledging

    const start = startAttempt(token, exam.id);
    if (!checkStart(start, 'start')) return;
    const attemptId = start.body.data.id;
    if (start.body.data.already_open) {
      warnFixture(student.email, 1);
      return;
    }

    const attempt = getAttempt(token, attemptId, questionCount);
    if (!attempt) return;

    const questions = attempt.questions.slice(0, Math.min(ANSWERS_PER_ATTEMPT, attempt.questions.length));
    let allSaved = true;
    for (let i = 0; i < questions.length; i++) {
      think(3, 12); // reading + choosing
      allSaved = saveAnswer(token, attemptId, questions[i]) && allSaved;
      if ((i + 1) % HEARTBEAT_EVERY === 0) allSaved = heartbeat(token, attemptId) && allSaved;
    }
    allSaved = heartbeat(token, attemptId) && allSaved;
    think(1, 4);

    ok = submitAttempt(token, attemptId) && allSaved;
  } finally {
    m.flowFailed.add(!ok);
    if (ok) {
      m.flowCompleted.add(1);
      m.flow.add(Date.now() - flowStart);
    }
  }
}
