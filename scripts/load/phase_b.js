import http from 'k6/http';
import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { Counter } from 'k6/metrics';

const fixture = JSON.parse(open(__ENV.GSO_LOAD_MANIFEST));
const base = __ENV.GSO_LOAD_URL;
const mode = __ENV.GSO_LOAD_MODE;
if (!/^http:\/\/127\.0\.0\.1:\d+\/GSO_WebSystem$/.test(base || '') ||
    !/^gso_load_test_phase_b_\d{8}_\d{6}$/.test(fixture.database) || !['ten', 'fifteen'].includes(mode)) {
  throw new Error('Phase B requires its isolated manifest, loopback server, and ten/fifteen mode.');
}
const desiredVUs = mode === 'ten' ? 10 : 15;
const actors = mode === 'ten'
  ? fixture.users.slice(0, 6).concat(fixture.users[12], fixture.users[13], fixture.users[15], fixture.users[16])
  : fixture.users.slice(0, 10).concat(fixture.users.slice(12, 17));
if (actors.length !== desiredVUs || new Set(actors.map(a => a.username)).size !== desiredVUs) throw new Error('Unique account per VU required.');
export const options = {
  scenarios: { phase_b: { executor: 'constant-vus', vus: desiredVUs, duration: mode === 'ten' ? '300s' : '600s', gracefulStop: '30s' } },
  summaryTrendStats: ['min', 'avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  thresholds: { http_req_failed: ['rate==0'], unintended_errors: [{ threshold: 'count==0', abortOnFail: true, delayAbortEval: '0s' }], http_500: ['count==0'] },
  systemTags: ['status', 'method', 'name', 'scenario', 'expected_response'],
};
const unintended = new Counter('unintended_errors');
const server500 = new Counter('http_500');
const server5xx = new Counter('http_5xx');
const sessions = {};
const chatSessions = {};
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
  return { jar, redirects, timeout: '20s', tags: { name, role: actor.role }, headers: { 'User-Agent': 'GSO-Phase-B-k6/2.2.0' } };
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
  const homes = { Borrower: '../borrower/browse.php', Admin: '../admin/dashboard.php', 'GSO Staff': '../gso_staff/dashboard.php', Director: '../director/dashboard.php', 'Super Admin': '../admin/accounts_dashboard.php' };
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
  if (exec.vu.idInTest > desiredVUs || exec.instance.vusActive > 15) exec.test.abort('Phase B VU cap exceeded.');
  const index = exec.vu.idInTest - 1;
  if (exec.vu.iterationInScenario === 0) sleep(Math.random() * 3);
  const actor = actors[index]; const jar = login(actor);
  if (actor.role === 'Borrower') {
    read(actor, jar, 'borrower/browse.php', 'borrower_browse', 'Browse Resources');
    read(actor, jar, 'borrower/my_requests.php', 'borrower_my_requests', 'My Requests');
    read(actor, jar, `borrower/request_resource.php?resource_id=${fixture.resources.Facility[index % 3]}&facility_calendar_month=${fixture.calendar_month}`, 'facility_availability', 'Facility Availability');
    const owned = fixture.owned_requests[actor.user_id];
    read(actor, jar, `borrower/my_requests.php?search=${owned}&status=Approved`, 'borrower_owned_status', `REQ-${String(owned).padStart(3, '0')}`);
    chat(actor, jar, `Status of REQ-${owned}`, 'chat_owned_status', 'live:request_status', 'Approved');
    chat(actor, jar, `Available ba ang Synthetic Aggregate 1 on ${fixture.query_date} qty 1`, 'chat_availability', 'live:resource_availability', 'Synthetic Aggregate 1');
  } else if (actor.role === 'Admin' || actor.role === 'GSO Staff') {
    read(actor, jar, actor.role === 'Admin' ? 'admin/dashboard.php' : 'gso_staff/dashboard.php', 'gso_dashboard', actor.role === 'Admin' ? 'Dashboard' : 'Operational Dashboard');
    read(actor, jar, 'admin/requests.php', 'gso_requests', '<h2>Requests</h2>');
    read(actor, jar, 'admin/on_loan.php', 'gso_on_loan', 'Active Borrowings');
    read(actor, jar, 'admin/create_walkin.php', 'gso_walkin', '<h2>Create Walk-in</h2>');
    read(actor, jar, 'admin/maintenance.php', 'gso_maintenance', '<h2>Maintenance</h2>');
    read(actor, jar, 'admin/maintenance_history.php', 'gso_maintenance_history', '<h2>Maintenance History</h2>');
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
function chat(actor, jar, message, name, expectedSource, marker) {
  let state = chatSessions[actor.username];
  if (!state) {
    const form = http.get(`${base}/borrower/my_requests.php`, params(actor, jar, 'chat_csrf_form'));
    verify(form, 'chat CSRF form', 200, 'My Requests');
    const token = form.html().find('input[name="csrf_token"]').first().attr('value');
    if (!token) exec.test.abort('STOP: chatbot CSRF unavailable.');
    state = chatSessions[actor.username] = { token, conversation: null };
  }
  const payload = { csrf_token: state.token, message };
  if (state.conversation) payload.conversation_id = state.conversation;
  const response = http.post(`${base}/chatbot/send.php`, payload, params(actor, jar, name));
  verify(response, name, 200);
  let result; try { result = response.json(); } catch (_) { unintended.add(1); exec.test.abort('STOP: invalid chatbot JSON.'); }
  const ok = result.ok === true && result.source === expectedSource && result.message.includes(marker) && result.conversation_id > 0;
  check(response, { [`${name}: deterministic owned/live result`]: () => ok });
  if (!ok) { unintended.add(1); exec.test.abort(`STOP: ${name} unexpected deterministic reply source=${result.source}.`); }
  state.conversation = result.conversation_id;
  sleep(2 + Math.random() * 2);
}
export function handleSummary(data) {
  const path = `${__ENV.GSO_LOAD_EVIDENCE}/${mode}_summary.json`;
  return { [path]: JSON.stringify(data, null, 2), stdout: `${mode}: ${JSON.stringify(data.metrics.http_req_duration.values)}\nrequests=${data.metrics.http_reqs.values.count}; unintended=${data.metrics.unintended_errors.values.count}; HTTP500=${data.metrics.http_500.values.count}\n` };
}

