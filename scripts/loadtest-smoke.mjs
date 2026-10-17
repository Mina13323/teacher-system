// Single-student smoke check of the real exam flow (NOT a load test). STAGING ONLY.
//   LOADTEST_PASSWORD=<staging fixture secret> BASE_URL=https://staging.maherelmasry.com \
//     node scripts/loadtest-smoke.mjs [studentNumber]
// The URL, student number and password are validated BEFORE any request is sent.
// There is no default password. Exit code 0 only when every step, including the
// persisted answer and the final attempt status, was verified.
import { runSmoke, SmokeFailure, validateBaseUrl, validatePassword, validateStudentNumber } from './loadtest-smoke-lib.mjs';

let attemptNote = '';
try {
  const base = validateBaseUrl(process.env.BASE_URL);
  const n = validateStudentNumber(process.argv[2]);
  const password = validatePassword(process.env.LOADTEST_PASSWORD);
  const email = `loadtest.student.${n}@staging.maherelmasry.com`;

  const result = await runSmoke({ base, email, password, fetchImpl: fetch, log: (line) => console.log(line) });
  console.log(`SMOKE TEST PASSED for ${email}: attempt ${result.attemptId}, final status "${result.status}".`);
  console.log(`Verified: ${result.verified.join(', ')}.`);
  console.log('The attempt is now used. Before the next run, reset the fixture on staging (operator action): php artisan loadtest:seed --reset-attempts');
} catch (e) {
  if (e instanceof SmokeFailure) {
    console.error(`SMOKE TEST FAILED at "${e.step}": ${e.message}`);
    if (!['config', 'login', 'exam list', 'exam details'].includes(e.step)) {
      attemptNote = 'An attempt may have been started and left behind; reset the fixture on staging before retrying (operator action).';
    }
  } else {
    console.error(`SMOKE TEST FAILED: unexpected error (${e && e.name ? e.name : 'error'}).`);
  }
  if (attemptNote) console.error(attemptNote);
  process.exit(1);
}
