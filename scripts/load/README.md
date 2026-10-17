# GSO Phase A load testing

This harness is limited to one user for 90 seconds and five users for 120 seconds, with up to 15 seconds to finish an active journey. It does not implement higher-user phases or dedicated race tests. Run only from the frozen project baseline `e2cbea932ed4073a814361c455c4f3086fd768a9`, using PHP 8.2, local MariaDB, Apache and k6 2.2.0.

The initial working tree must be clean. Creating this requested directory then produces untracked test assets; the runner rejects changes outside this directory and any tracked application changes.

From the repository root in PowerShell:

```powershell
& ./scripts/load/run_phase_a.ps1
```

Where local script execution policy prevents this, an authorized operator can review the file and execute its contents as a script block without changing execution policy:

```powershell
& ([scriptblock]::Create((Get-Content -Raw scripts/load/run_phase_a.ps1)))
```

The runner creates a **new** timestamped `gso_load_test_YYYYMMDD_HHMMSS` database, imports `database/schema.sql`, asserts the frozen schema, and seeds entirely synthetic data. It requires an existing local database administrator (XAMPP root defaults in the runner); adapt only the harness credentials if your local installation differs. It refuses database reuse. A new database account has SELECT/INSERT/UPDATE/DELETE permissions on that database alone. No normal application database is used.

A separate Apache listens only on `127.0.0.1:8120`. Its private PHP startup configuration disables `mail`, `fsockopen`, `pfsockopen`, and `stream_socket_client`. A request guard and health check verify this before any application load. Sessions, logs, fixture letters, manifests, passwords and raw evidence stay in a private timestamped directory under the current user's TEMP directory. `latest-evidence.txt` locates the latest run; do not commit private manifests or credentials. The owned Apache process tree is stopped at the end; databases and evidence are retained for review. Database creation/grants are confined to new test objects. No global MariaDB logging/configuration is changed.

`phase_a.js` uses independent cookie jars and CSRF login, then checks expected HTTP statuses, role redirects and page content. Think time is 2–4 seconds between reads and 2–5 seconds between journeys. Baseline rotates through the same five personas sequentially; the five-user run assigns a fixed independent session to each of two borrowers, GSO Head, Director and MIS. Seeded operational accounts also include two GSO Staff accounts. Successful baseline, integrity and PHP/Apache log gates are required before five users.

Reads cover Browse, My Requests, the real facility availability form/calendar, GSO dashboard/requests/active borrowings, Director schedules, and MIS account reads. Login creates expected activity log entries. There are no business mutation requests or mail endpoints. This is an HTTP/backend test: k6 does not execute browser JavaScript or download CSS/images. It measures a small synthetic fixture, not large production datasets or write contention.

`integrity.php` checks stock arithmetic, issuance, serialized allocation uniqueness and consistency, all 57 foreign keys for orphans, confirmed facility overlaps, Claim Slip format/uniqueness/status, request quantities/actors/status fields, and verified letters. Business tables and table definitions must match seed hashes; only normal login activity/attempt tables may differ. Run stages are `before`, `baseline` and `five`.

Any unexpected HTTP/content result aborts immediately. A failed workload, database invariant, PHP entry or Apache error prevents the next workload. Stop and report an application defect without editing production code. MariaDB's existing shared error log is captured from the pre-run offset; inspect that delta before approving further phases. Shutdown-related process termination messages occur after the workload and must be distinguished from request errors. No production code, schema files, deployment or Git history is changed by the harness.

Evidence includes seed summary, table hashes, private manifest and server health, machine specifications, before/after integrity JSON, k6 summaries and JSON samples, Apache access/error logs, PHP errors if any, MariaDB log delta and run metadata. Failed setup attempts use their own retained databases and evidence directories; do not mix their partial metrics with the completed run. `RESULTS.md` records the completed execution and its limitations.
