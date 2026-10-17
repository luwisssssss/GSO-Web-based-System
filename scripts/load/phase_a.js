import http from 'k6/http';
import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { Counter } from 'k6/metrics';

const fixture = JSON.parse(open(__ENV.GSO_LOAD_MANIFEST));
const base = __ENV.GSO_LOAD_URL;
const mode = __ENV.GSO_LOAD_MODE;
if (!/^http:\/\/127\.0\.0\.1:\d+\/GSO_WebSystem$/.test(base || '') ||
    !/^gso_load_test_\d{8}_\d{6}$/.test(fixture.database) || !['baseline', 'five'].includes(mode)) {
  throw new Error('Phase A requires its isolated manifest, loopback server, and baseline/five mode.');
}
const desiredVUs = mode === 'baseline' ? 1 : 5;
export const options = {
  scenarios: { phase_a: { executor: 'constant-vus', vus: desiredVUs, duration: mode === 'baseline' ? '90s' : '120s', gracefulStop: '15s' } },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  thresholds: { http_req_failed: ['rate==0'], unintended_errors: [{ threshold: 'count==0', abortOnFail: true, delayAbortEval: '0s' }], http_500: ['count==0'] },
  systemTags: ['status', 'method', 'name', 'scenario', 'expected_response'],
};
const unintended = new Counter('unintended_errors');
const server500 = new Counter('http_500');
const server5xx = new Counter('http_5xx');
const sessions = {};
const errorMarkers = /(?:<b>\s*(?:Warning|Fatal error|Notice|Deprecated)\s*<\/b>|PHP Fatal|Uncaught (?:PDOException|Error|Exception)|GSOERR-|temporarily unavailable|Invalid username or password|Invalid request token)/i;

function verify(response, label, expectedStatus, marker) {
  server500.add(response.status === 500 ? 1 : 0);
  server5xx.add(response.status >= 500 ? 1 : 0);
  const statusOK = response.status === expectedStatus;
  // Chat-widget JavaScript contains a dormant error message; inspect visible HTML.
  const visibleHTML = (response.body || '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
  const bodyOK = !errorMarkers.test(visibleHTML) && (!marker || response.body.includes(marker));
  check(response, { [`${label}: HTTP ${expectedStatus}`]: () => statusOK, [`${label}: content/session`]: () => bodyOK });
  const bad = !statusOK || !bodyOK;
  unintended.add(bad ? 1 : 0);
  if (bad) exec.test.abort(`STOP: ${label} status=${response.status}; inspect private HTTP/log evidence before further testing.`);
}
function params(actor, jar, name, redirects = 0) {
  return { jar, redirects, timeout: '20s', tags: { name, role: actor.role }, headers: { 'User-Agent': 'GSO-Phase-A-k6/2.2.0' } };
}
function login(actor) {
  if (sessions[actor.username]) return sessions[actor.username];
  const jar = new http.CookieJar();
  const form = http.get(`${base}/auth/login.php`, params(actor, jar, 'login_form'));
  verify(form, 'login form', 200, 'name="csrf_token"');
  const csrf = form.html().find('input[name="csrf_token"]').first().attr('value');
  if (!csrf) exec.test.abort('STOP: login form has no CSRF token.');
  const response = http.post(`${base}/auth/login.php`, { login_input: actor.username, password: actor.password, csrf_token: csrf }, params(actor, jar, 'login_submit'));
  verify(response, 'login submit', 302);
  const homes = { Borrower: '../borrower/browse.php', Admin: '../admin/dashboard.php', Director: '../director/dashboard.php', 'Super Admin': '../admin/accounts_dashboard.php' };
  const location = response.headers.Location || '';
  if (location !== homes[actor.role]) { unintended.add(1); exec.test.abort(`STOP: unexpected role redirect for ${actor.role}.`); }
  sessions[actor.username] = jar;
  return jar;
}
function read(actor, jar, path, name, marker) {
  const response = http.get(`${base}/${path}`, params(actor, jar, name));
  verify(response, name, 200, marker);
  // Sequential navigation with human pacing; no batch requests or forced races.
  sleep(2 + Math.random() * 2);
}
export default function () {
  if (exec.vu.idInTest > desiredVUs || exec.instance.vusActive > 5) exec.test.abort('Phase A VU cap exceeded.');
  const index = mode === 'baseline' ? exec.vu.iterationInScenario % 5 : exec.vu.idInTest - 1;
  const actor = fixture.actors[index]; const jar = login(actor);
  if (actor.role === 'Borrower') {
    read(actor, jar, 'borrower/browse.php', 'borrower_browse', 'Browse Resources');
    read(actor, jar, 'borrower/my_requests.php', 'borrower_my_requests', 'My Requests');
    read(actor, jar, `borrower/request_resource.php?resource_id=${fixture.resources.Facility[index % 3]}&facility_calendar_month=${fixture.calendar_month}`, 'facility_availability', 'Facility Availability');
  } else if (actor.role === 'Admin') {
    read(actor, jar, 'admin/dashboard.php', 'gso_dashboard', 'Dashboard');
    read(actor, jar, 'admin/requests.php', 'gso_requests', '<h2>Requests</h2>');
    read(actor, jar, 'admin/on_loan.php', 'gso_on_loan', 'Active Borrowings');
  } else if (actor.role === 'Director') {
    read(actor, jar, 'director/dashboard.php', 'director_schedule', 'Facility Schedules');
    read(actor, jar, `director/dashboard.php?facility_calendar_month=${fixture.calendar_month}&facility_calendar_resource_id=${fixture.resources.Facility[0]}`, 'director_filtered_schedule', 'Facility Reservations');
  } else {
    read(actor, jar, 'admin/accounts_dashboard.php', 'mis_dashboard', 'MIS Account Management');
    read(actor, jar, 'admin/admin_accounts.php', 'mis_system_accounts', 'System Accounts');
    read(actor, jar, 'admin/borrowers_accounts.php', 'mis_borrower_accounts', 'Borrowers Accounts');
  }
  sleep(2 + Math.random() * 3);
}
export function handleSummary(data) {
  const path = `${__ENV.GSO_LOAD_EVIDENCE}/${mode}_summary.json`;
  return { [path]: JSON.stringify(data, null, 2), stdout: `${mode}: ${JSON.stringify(data.metrics.http_req_duration.values)}\nrequests=${data.metrics.http_reqs.values.count}; unintended=${data.metrics.unintended_errors.values.count}; HTTP500=${data.metrics.http_500.values.count}\n` };
}
