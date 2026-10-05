// Single-student smoke check of the real exam flow (NOT a load test).
//   BASE_URL=https://staging.maherelmasry.com node scripts/loadtest-smoke.mjs [studentNumber]
// Password: LOADTEST_PASSWORD env var, or the documented default in LOAD_TEST_FIXTURES.md.
const base = (process.env.BASE_URL || '').replace(/\/$/, '');
if (!base) throw new Error('Set BASE_URL (e.g. https://staging.maherelmasry.com)');
const n = String(process.argv[2] || 1).padStart(4, '0');
const email = `loadtest.student.${n}@staging.maherelmasry.com`;
const password = process.env.LOADTEST_PASSWORD || 'LoadTest#Staging-2026';

let token = null;
async function call(label, method, path, body) {
  const res = await fetch(`${base}/api/v1${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const json = await res.json().catch(() => ({}));
  console.log(`${res.ok ? 'OK  ' : 'FAIL'} ${res.status} ${label}`);
  if (!res.ok) {
    console.log(JSON.stringify(json).slice(0, 500));
    process.exit(1);
  }
  return json.data;
}

const login = await call('login', 'POST', '/auth/login', { email, password });
token = login.token;
const exams = await call('GET student exams', 'GET', '/student/exams');
const list = Array.isArray(exams) ? exams : exams.data ?? [];
const exam = list.find((e) => /load-test-exam/.test(e.title)) ?? list[0];
if (!exam) throw new Error('No exam visible to the student');
await call('GET exam', 'GET', `/student/exams/${exam.id}`);
const start = await call('POST start', 'POST', `/student/exams/${exam.id}/start`, {
  rules_acknowledged: true,
  compact_response: true,
});
const attempt = await call('GET attempt', 'GET', `/student/attempts/${start.id}`);
const q = attempt.questions[0];
await call('POST answer', 'POST', `/student/attempts/${start.id}/answers`, {
  question_id: q.id,
  option_ids: [q.options[0].id],
});
await call('POST heartbeat', 'POST', `/student/attempts/${start.id}/heartbeat`);
const done = await call('POST submit', 'POST', `/student/attempts/${start.id}/submit`);
console.log(`Smoke test passed for ${email} (attempt ${start.id}, status ${done?.status ?? 'n/a'}).`);
