// Dashboard load (STAGING ONLY): the screens that showed "the server did not
// respond in time" in production.
//   each VU: login as its fixture student -> open the student dashboard
//   DASHBOARD_VIEWS times; VU 1 also logs in as the load-test teacher and
//   opens the teacher dashboard alongside.
// Read-only: it never starts attempts, so it needs no fixture reset and can be
// re-run straight away.
import {
  VUS, START_INDEX, RAMP_SECONDS, THINK_SCALE,
  studentForThisVu, oneShotScenario, rampDelay, think, buildThresholds, m,
  login, getDashboard,
} from './k6-config.js';

const DASHBOARD_VIEWS = parseInt(__ENV.DASHBOARD_VIEWS || '5', 10);
const TEACHER_EMAIL = 'loadtest.teacher@staging.maherelmasry.com';

export const options = {
  scenarios: {
    dashboard_flow: oneShotScenario(DASHBOARD_VIEWS * 20 + 60),
  },
  thresholds: buildThresholds(['login', 'student_dashboard', 'teacher_dashboard']),
  tags: { suite: 'dashboard-flow' },
};

export function setup() {
  console.log(
    `dashboard-flow: ${VUS} VU(s), students ${START_INDEX}..${START_INDEX + VUS - 1}, ` +
      `${DASHBOARD_VIEWS} views each, ramp ${RAMP_SECONDS}s, think scale ${THINK_SCALE}`
  );
}

export default function () {
  const isTeacherVu = __VU === 1;
  const email = isTeacherVu ? TEACHER_EMAIL : studentForThisVu().email;
  const role = isTeacherVu ? 'teacher' : 'student';
  const flowStart = Date.now();
  let ok = false;

  try {
    rampDelay();

    const token = login(email);
    if (!token) return;

    ok = true;
    for (let i = 0; i < DASHBOARD_VIEWS; i++) {
      ok = getDashboard(token, role) && ok;
      think(5, 15); // a user glancing at the dashboard, then coming back to it
    }
  } finally {
    m.flowFailed.add(!ok);
    if (ok) {
      m.flowCompleted.add(1);
      m.flow.add(Date.now() - flowStart);
    }
  }
}
