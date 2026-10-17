# Phase B: 10 and 15 concurrent users

Phase A assets were reviewed and checkpointed in commit `92e0242077675a842420d1def52bff2e883b22f8` (`Add Phase A concurrent load-test harness`). All production files must still match `e2cbea932ed4073a814361c455c4f3086fd768a9`. Phase A's RESULTS.md and private evidence are preserved.

Run from the project root, after reviewing the test assets:

```powershell
& ./scripts/load/run_phase_b.ps1
```

If local execution policy prevents script invocation, the authorized operator can execute the reviewed content as in Phase A:

```powershell
& ([scriptblock]::Create((Get-Content -Raw scripts/load/run_phase_b.ps1)))
```

The runner requires k6 2.2.0, the Phase A checkpoint HEAD, and no changes outside scripts/load/. It imports the frozen schema into a fresh `gso_load_test_phase_b_YYYYMMDD_HHMMSS` database using the Phase A synthetic seed. No normal application database is connected by this harness. It refuses reuse. A runtime account has only SELECT/INSERT/UPDATE/DELETE on the fresh test database. The existing local XAMPP database-administrator defaults are used for preparation only. Runtime credentials, sessions, uploaded synthetic letter fixtures and evidence remain under a private TEMP folder. The ignored `latest-phase-b-evidence.txt` records its local location. No secrets are embedded in test assets.

Apache listens only on loopback port 8121, using private configuration and PHP startup settings. SMTP functions are disabled and guarded before application requests. AI_PROVIDER is disabled; live chatbot answers use the existing deterministic production templates. No production authorization, mail, database or provider code is changed. Apache status is enabled only on this isolated server, accessible from loopback.

The 10-user workload runs constant 10 VUs for 300 seconds: six Borrowers, GSO Head, one GSO Staff, Director, and MIS. Only after full integrity and PHP/Apache/MariaDB log gates pass does the runner start 15 constant VUs for 600 seconds: ten Borrowers, GSO Head, two GSO Staff, Director, and MIS. Each VU owns a distinct synthetic account and cookie jar. Login checks CSRF and role redirect. Initial login is staggered by up to three seconds. Think time is 2 to 4 seconds between actions and 2 to 5 seconds between journeys, with 30 seconds of graceful completion.

Borrowers read Browse, My Requests, facility availability, and a filtered owned Approved request/status. Each journey asks two live chatbot questions: its own request status and aggregate resource availability. Expected source, response content and status are asserted. The status answer must be Approved; the availability answer must name the intended synthetic resource. Chat conversations are private per VU. This produces expected conversation/message rows, without business mutation requests. GSO users read their permitted dashboard, request queue, active borrowings, Create Walk-in, maintenance and maintenance history. Director reads facility schedule/calendar; MIS reads account management and account lists. GSO Staff uses the staff dashboard; shared operational pages use their existing permission gates.

HTTP failures/content mismatches immediately abort and block progression. The complete integrity audit runs before load and immediately after each run, including stock, issuance, all 57 orphan checks, allocation consistency, confirmed facility overlaps, Claim Slips, quantities/actors/statuses and verified letters. All business rows must match seed hashes. Only login/activity and chatbot tables may receive expected writes. Chat conversation owners and complete user/assistant message pairs are audited. Every table definition is fingerprinted, excluding the advancing AUTO_INCREMENT counter (data state). Frozen table/constraint/index/version counts are rechecked. Any genuine application or integrity defect must be reported without repair.

Private evidence includes k6 summaries and JSON samples, before/ten/fifteen integrity JSON, execution durations, seed hashes, server-health/grants, machine specs, Apache access/error logs, PHP log if any, cumulative MariaDB error-log deltas per gate, and telemetry. MariaDB global logging settings are not changed. Every five seconds the optional monitor samples host CPU/free RAM, aggregate Apache process working set, isolated Apache busy/idle worker counts and MariaDB connection/row-lock/deadlock counters. CPU, RAM, Apache process memory and database global counters may include other local applications; the telemetry connection itself contributes to connected/running counts. Monitor status requests are excluded from k6 metrics.

This tests HTTP/backend behavior against a small synthetic fixture, with chat/login writes. Browser JavaScript/assets, business mutation contention, dedicated races, 20-user spikes, deployment and production-scale datasets are outside Phase B. A p95 above 2000 ms is performance degradation, not evidence of an integrity failure. No second commit is made without an explicit request; Phase B harness/report changes stay reviewable under scripts/load/.
