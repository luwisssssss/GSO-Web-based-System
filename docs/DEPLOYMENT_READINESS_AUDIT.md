# Deployment readiness audit — 2026-10-11 (Asia/Manila)

**DEPLOYMENT READINESS: BLOCKED**

The original 2026-10-11 audit was committed as **`d8c8402`** (documentation only). The addendum below records subsequent authorized remediation. Sections after the addendum preserve the original audit evidence and recommendations; their original defect statuses and line numbers are historical. Nothing here authorizes deployment.

## Remediation addendum — 2026-10-12, Asia/Manila

**APPLICATION READINESS: READY. HOST READINESS: UNVERIFIED. Full deployment remains BLOCKED pending actual target staging.**

| Original finding | Current disposition |
|---|---|
| D1 raw exception disclosure | Application fix complete; technical catches use safe messages/private structured diagnostics. Actual controller/model persistence fault tests pass. |
| D2 engine compatibility | Exact concern documented; explicit empty staging checker passes locally, with all frozen actions/expressions/counts. Maintained MariaDB/MySQL target rehearsal still required; no schema change. |
| D3 configuration | Strict production/prod/staging policy implemented, including mandatory external key/HTTPS/DB/SMTP/storage and forbidden test switches. Real target configuration remains an operator gate. |
| D4 packaging/backup | Runtime manifest excludes every SQL/backup/nested development copy; server-private tools are separate. Byte-identical outside-web-root backup copy verified; original preserved. Durable backup retention/host archive review remain required. |
| D5 host protection | Local denial/canary tests pass; read-only HTTPS script and manual checklist prepared. Actual target protections remain UNVERIFIED. |

### Source behavior and logging

`config/error_handler.php` provides `gsoSafeApplicationError` and `gsoLogApplicationException`. Technical PDO/runtime/filesystem/SMTP failures produce a generic message with a `GSOERR-*` reference. Deliberately authored `DomainException` validations remain useful; known release/inspection validations are explicitly typed accordingly. Suspicious multiline/technical/path/credential-shaped Domain messages are suppressed too. Equipment-unit duplicate-index mapping reads driver text only internally and emits a fixed validation message.

Diagnostics use PHP `error_log` with JSON containing reference, static operation, exception class, file/line, up to 12 **argument-free** stack frames, and whitelisted SQLSTATE/numeric driver codes. **Exception messages and PDO errorInfo[2] are intentionally never logged**, because they can contain bound values or credentials. This preserves technical fault location/type/codes without logging passwords, SMTP secrets, tokens, session IDs, request bodies, documents or stack arguments. Logs belong in private host-managed storage. No logging framework was added. Mail configuration/delivery errors are generic publicly; production ignores local mail overrides and browser Host URL fallback.

Production bootstrap defaults to strict validation when environment is missing; production/prod/staging force display_errors/display_startup_errors Off and log_errors On and install warning/uncaught/shutdown handlers. Debug, root runtime user, missing/placeholder critical values, local/test transport, implicit migration flags, weak-format keys, HTTP/local canonical URLs and public/private-storage violations are rejected before DB connection. Administrators see missing field names in CLI/private logs; browsers receive generic 503 with no settings. Localhost DB hosting is allowed **only when explicitly configured with nonblank credentials**, not as a silent fallback. A separately scoped CLI schema-tool DBA account is permitted. Host startup/entrypoint errors before bootstrap remain a php.ini/server staging gate.

The existing **`GSO_APP_ENV=local`** is preserved as a development alias. Explicit local/development/test keep development behavior; active regressions additionally require CLI and isolated `gso_rN_test_*` names. Test mail capture still requires isolated DB + development/test + loopback. Strict staging/production reject it and the failure-marker route cannot activate there. The real `.env` and local secret files were not edited. Local root and `pages/landing.php` remain HTTP 200 with rendered HTML and no PHP error markers.

### Final complete run and data safety

One uninterrupted final pass, against **`gso_r13_test_20261011_074519`**, ran all 22 active suites followed by the focused suite. Results: **22 active / 3,034 actual checks**, **1 focused / 137 checks**, **23 suites / 3,171 total / zero failures**. The active count was calculated from this run, not assumed from the earlier 3,033 baseline. No partial-run counts were combined.

The focused suite covers real request/return/signup PDO execute faults, real work-order UPDATE fault/rollback, filesystem and SMTP exception privacy, an actual loopback-only SMTP connection failure, safe warning handling even with display_errors enabled, strict bootstrap missing settings/outbox refusal, canonical Host independence, secure cookie policy, private/internal denial, a known-existing PHP canary denied by actual upload rules, secret-free diagnostics and unsafe staging targets. It sends no real email. Authored business validation is also asserted unchanged. Development-only fault wrappers are generated outside the repository, guarded by nonproduction environment, isolated DB, loopback and explicit fixture context; never deploy them.

No unexpected PHP warning, notice, deprecation, fatal, cleanup warning or test failure appeared in suite outputs. Expected diagnostics occurred for simulated mail failure, duplicate-unit rejection and AI fallback; they contain safe metadata/fixed text. Isolated Apache logs also contain expected denied/nonexistent-route probes and a PID-file warning from force-stopping the owned temporary test server. Git emits ordinary LF→CRLF conversion notices. These are not user-visible production PHP faults.

Read-only, primary-key-ordered full-row hashes and SHOW CREATE hashes for **all 22 live tables / 588 rows match the pre-remediation snapshot exactly**, including BLOBs and table auto-increment metadata. Frozen column metadata has zero differences. Counts remain **22 tables / 275 columns / 57 FKs / 104 indexes / 18 UNIQUE / 11 CHECK / 3 generated**, version **20261010_008**. No SQL/migration/R11/R12/ERD file changed. Testing used isolated databases; no live fixture inserts/updates/deletes or real email were performed.

Local staging-tool import/repeat check passed on a newly provisioned `gso_stage_*` empty database with no business rows. Repeat invocation after import was refused (exit 2) because it was no longer empty. Unsafe names/opt-in refusal is tested before connection. Its metadata/expressions/FK actions match the freeze. See [DATABASE_HOSTING_COMPATIBILITY.md](DATABASE_HOSTING_COMPATIBILITY.md) for the exact `request_items.facility_guard` / `fk_request_items_request` / DELETE CASCADE, UPDATE RESTRICT concern and maintained MariaDB vs MySQL paths. Actual MySQL import remains untested.

### Static review, dependencies, packaging and remaining gates

- PHP lint: **277 tracked/new PHP files, zero failures**. `git diff --check` passes with repository line-ending settings.
- Case review: 633 tokenized production include expressions; 626 statically resolved include paths and 184 public URL references, zero missing literal paths/case mismatches. A further 1,279 filename-token case checks found zero mismatches. Dynamic local-key/mail override/sidebar includes reviewed separately. New config helper paths match case.
- Raw exception scan: no direct technical getMessage browser/flash/JSON output or raw-message logging remains in application/config/includes. The three occurrences are the guarded Domain formatter and two internal duplicate-index comparisons. Test scripts' detailed CLI failures are excluded from deployment and isolated-DB guarded.
- Windows runtime-path scan: no absolute Windows/XAMPP runtime constant in production source. Configurable defaults are blocked in strict production; synthetic paths in tests and examples/docs are classified development/reference. Browser Host inference is available only after explicit nonproduction mode; configured production URLs are used directly and missing canonical URL fails.
- Secret scan: literal candidates were reviewed; production candidates are verification/reset URL construction and CSRF markup, not embedded credentials. Four actual configured-secret sources were compared without printing values; zero tracked/new source/document matches. Focused response/log sentinel checks pass. This is current-source review, not exhaustive historical Git forensics.
- PHPMailer **7.1.1** remains vendored, no root Composer lock/install. Exact-tag PHP ≥5.5/ctype/filter/hash requirements and secure SMTP OpenSSL were verified; application's supported-PHP staging choice is separate. Reviewed upstream notices did not identify a P1 upgrade requirement for this version; no arbitrary upgrade occurred. [Tag requirements](https://raw.githubusercontent.com/PHPMailer/PHPMailer/v7.1.1/composer.json), [security notices](https://github.com/PHPMailer/PHPMailer/security).
- [PRODUCTION_FILE_MANIFEST.md](PRODUCTION_FILE_MANIFEST.md) records exact backup size/SHA-256/external copy, runtime allowlist/reject list, private tools, every writable area and permissions. No original backup was moved/deleted. The external Temp safety copy still needs durable encrypted institutional retention.

No application-side P0/P1 remains in the verified scope. **Remaining host-side P1 launch gates:** chosen maintained DB engine import/enforcement/restore; real production configuration/key/SMTP/private storage; approved runtime archive; actual HTTPS/cookies/canonical URLs; internal path denial/upload nonexecution/evidence authorization; actual PHP SAPI settings/private logs/proxy boundary. Host is unspecified, so these cannot be certified locally. No deployment, DNS change, push, ERD regeneration or new load-testing program occurred.

Reports/tools added: database hosting compatibility, production file manifest, staging database checker, staging HTTPS host checker, deployment fault fixture/focused suite and common test-environment guard. The two original audit/runbook documents were updated. Authorized remediation is committed separately from `d8c8402`; do not amend that baseline.

## Original audit baseline and environment

- Initial working tree: clean; `git status --short` produced no output.
- HEAD: `96042c9b8368e63bd9f3b9fd3904055b41ea0083` (Generate final as-built GSO ERD).
- Frozen implementation: `62fdff1c40391d247e7741536fd397c7832a07f8`; schema `20261010_008`.
- Previous commits: `8a0bd7c` schema freeze; `62fdff1` claim-slip restoration; `afd70fc` final cleanup; `0b022a4` transactional client email.
- PHP CLI: **8.2.12**, ZTS, Windows x64. MariaDB server (queried): **10.4.32-MariaDB**. Apache: **2.4.58**, Windows VS17 build.
- Local Apache has `rewrite_module`, `headers_module`, `authz_core_module`, and `ssl_module` enabled. Its document root is `C:/xampp/htdocs`; the application is mounted at `/GSO_WebSystem/`.
- The application language floor found is **PHP 8.0** (`match`, `str_contains`, `str_starts_with`, `str_ends_with`). PHP token inspection found eight `match` expressions and no application `array_is_list`, enums, readonly declarations, or `json_validate` calls. PHP 8.0 execution itself was not tested. This language floor is not a production support recommendation: select a patched, supported PHP branch, preferably 8.3/8.4, and validate it on staging. PHP 8.2 security support ends 2026-12-31. [PHP support policy](https://www.php.net/supported-versions.php)
- MariaDB 10.4 maintenance ended 2024-06-18. Do not copy the old XAMPP server stack into production. A maintained MariaDB 10.11 or 11.4 release is a candidate, subject to exact-engine staging verification. [MariaDB maintenance policy](https://mariadb.org/about/#maintenance-policy)

## Findings and launch gates

| ID / priority | Location | Evidence and risk | Exact recommended repair / closure |
|---|---|---|---|
| D1 — P1, confirmed source defect | `app/controllers/admin/requests.php:693`, `app/controllers/admin/review_return.php:286`, `app/controllers/borrower/return_submit.php:351`, `app/controllers/auth/signup.php:309`; `app/models/work_order_helper.php:20,32,44,50`; `app/models/preventive_maintenance_helper.php:111` | Broad exception catches expose `getMessage()` directly or return it in a message subsequently displayed by maintenance handlers. A synthetic PDO failure confirmed the work-order helper returns a private database detail unchanged. `display_errors=Off` does not suppress text deliberately rendered by the application. | Approve a focused error-handling patch: preserve explicitly trusted business-validation messages; log technical failures server-side with a correlation ID; return a generic message for PDO/runtime failures. Fix helper results before controllers wrap them as DomainExceptions. Test injected technical failures on an isolated database/session; do not provoke failures against production. No schema change is needed. |
| D2 — P1, database-host compatibility gate | `database/schema.sql`, `request_items.facility_guard` and its `request_id` FK | The stored generated column uses `request_id`, whose FK uses `ON DELETE CASCADE`. MySQL documents that cascading referential actions cannot be used on base columns of stored generated columns. This is a documented incompatibility inference, not a completed MySQL import test. Local MariaDB installation passes. | Choose a supported **MariaDB** host and rehearse import/upgrade/restore there. Do not certify MySQL-only hosting. If MySQL is mandatory, obtain separate approval for a compatibility investigation and reviewed schema/migration changes; do not remove the uniqueness guard or disable constraints as a workaround. [MySQL restriction](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html) |
| D3 — P1, production configuration gate | `config/bootstrap.php`, `config/db.php`, `config/mail.php`, `.env.example` | Current local configuration is development mode, has no configured application URL or external app key, uses root with an empty DB password, and PHP displays errors. Real SMTP credentials exist in an ignored local override; no values were printed. `mail.local.php` overrides environment mail settings. | Provision a separate production configuration: production mode/debug off; explicit HTTPS URL; persistent random app key; least-privilege DB credentials; production SMTP/TLS; Secure cookies; absolute private Linux storage paths; server-level display errors off/private logs. Exclude every local secret file and disable local/test mail transport. Verify actual FPM/Apache settings, not only CLI. |
| D4 — P1 for raw-folder deployment, backup-placement gate | `GSO_WebSystem/docs/db_cleanup_backup_before_cleanup.sql` inside the ignored nested copy | A historical backup exists physically beneath the current Apache document root. Its URL returns 403, but the requirement that backups live outside the web root is **not currently satisfied** for this file. `.gitignore` is not web access control. | Exclude the entire ignored nested copy from the release. Before any raw-directory transfer, preserve this backup outside the document root, verify its checksum, and remove the in-root copy only with explicit approval. Use the runbook's tracked-file allowlist package; never upload the development folder wholesale. No backup was moved/deleted in this audit. |
| D5 — P1, target-host protection gate | Root `.htaccess`, `uploads/.htaccess`, production virtual host | Current local protections pass, but target hosting is unspecified. Ignored `.htaccess` files or unsupported directives could expose backend/private files; the current structure depends on multiple public entrypoints. | Confirm Apache 2.4/mod_rewrite/authorized overrides and execute denial probes on staging. On nginx or hosting that ignores `.htaccess`, install equivalent server rules before exposure. Force HTTPS at the trusted web server/proxy. Prove private storage is outside every document root/alias. |

**P0:** no active critical exposure or data-loss defect was observed in the tested local HTTP surface. This is not a guarantee about an unconfigured production host.

**P2:** review server-side re-encoding of general image uploads (currently MIME/structure-checked and canonically renamed, not re-encoded); require EXIF support if GPS-metadata rejection is mandatory; define backup retention and restore drills; verify all runtime directories as the real PHP user; review current PHPMailer advisories before each release; provide DB TLS configuration if the selected host requires a remote TLS connection (the current PDO config has no SSL-attribute settings). Co-located/private trusted DB connectivity avoids introducing an untested remote transport.

**P3:** maintain a release/dependency checksum manifest and document the one-time legitimate MIS-account bootstrap. No Composer conversion, new queue, or application restructuring is required by this audit.

## Configuration and secrets

The existing `.env.example` is safe: placeholder values only, no real passwords or API keys. It is retained unchanged. Its Windows private-storage examples must be replaced in the production `.env`; they are not runtime constants. External process/environment values take precedence over `.env` in `gsoLoadEnvFile`. The ignored `config/mail.local.php` is an exception: it merges last and can override SMTP values, so it must not be deployed.

No real hardcoded credential was identified in the current tracked sources inspected. `.env`, `config/mail.local.php`, and `config/app_key.local.php` are present locally but ignored; their values were never included in output or documentation. `git log --all` found no history for those three paths. This was a current-source review, not an exhaustive credential scan of every historical Git blob.

The literal-secret scan produced 43 candidates: 38 test/fixture references (A), and five production false positives consisting of tokenized verification/reset URLs or CSRF markup (D). Actual keys/passwords are supplied by configuration or generated at runtime; test fixture passwords must never be seeded into production.

Runtime localhost/identity/path occurrences are classified as follows:

| Location | Classification | Reason |
|---|---|---|
| `config/db.php:4,6-8` | B | Configurable localhost/default database/root/empty-password development fallbacks; require explicit production credentials. |
| `app/models/mail_helper.php:14-17` | B | Explicit `GSO_APP_URL` overrides inferred URLs. |
| `app/models/mail_helper.php:21` | C when URL unset | Signup/password-reset fallback trusts `HTTP_HOST`; configure the canonical HTTPS URL and validate the virtual host. Transactional client links use a separate configured-base-only helper. |
| `app/models/mail_helper.php:22` | B | Development script-path fallback; overridden by configured application URL. |
| `app/models/mail_helper.php:157`, `incident_upload_helper.php:62`, `request_attachment_helper.php:8` | B | Portable default directory construction with a fixed application label, but deployment needs explicit private storage paths. |
| `app/models/client_notification_helper.php:84` | D with configured URL | Links validate configured base URL and never use browser Host. |
| `.env.example` Windows paths/host/credential placeholders | B | Safe examples; configure actual production values separately. |
| Tests/scripts/docs Windows paths, localhost ports, test DB names and fixture passwords | A | Development/documentation references, not request-runtime dependencies; exclude test tools from production package. |
| Mail test transport guards and AI API-key lookup | D | Test transport requires isolated test DB + nonproduction mode + loopback; AI key is a server-side environment lookup. |

No production dependency on PowerShell, XAMPP binaries, drive-letter filesystem constants, or Windows-only shell commands was found. Windows-path recognition and slash normalization in storage helpers support both platforms. The migration script's XAMPP command is documentation, not an executed path.

## HTTP surface and document root

The existing deployment architecture exposes the application directory as a mount, not `public/` alone. `public/` contains assets and is not a complete front controller. Public wrappers are `index.php`, `pages/`, `auth/`, `borrower/`, `admin/`, `gso_staff/`, `director/`, `chatbot/`, and the intentional `includes/mark_read.php` endpoint. Role/capability checks remain required on protected wrappers.

Root rules block dotfiles, nested copies, app/config/database/docs/routes/scripts/internal includes, PHPMailer/vendor, logs/storage/private paths, backup extensions, SQL, and return evidence. Upload rules allow only final image extensions and reject executable and double-extension script names. Apache must actually honor these rules; `AllowOverride` controls that. [Apache override documentation](https://httpd.apache.org/docs/2.4/mod/core.html#allowoverride)

All 15 representative internal/file probes returned **403**: `.env`, `.git/config`, `config/db.php`, schema SQL, a migration SQL, `scripts/migrate.php`, R11 JSON, an internal include, a model, return uploads, temporary uploads, nested project, PHPMailer source, and route map. Directory probes also returned 403. The nested historical SQL backup returns 403. Anonymous `borrower/request_document.php?attachment_id=1` redirects to login without delivering a document. No private evidence content was fetched.

Root returns 302 to the public landing entrypoint, whose final response is 200. Landing still renders; no warning/fatal was observed. Private incident photos/request letters are stored externally, and their stream controllers enforce authentication, account authorization and ownership/capability checks.

## Database installation, upgrade, and recovery

`scripts/migrate.php` is CLI-only. It uses `GSO_SCHEMA_TOOL` to avoid request-time schema checks and corrective-maintenance synchronization. Empty databases receive `database/schema.sql`; versioned installations receive lexically ordered pending migration identifiers, with version recorded only after each completed file. The required version is exactly `20261010_008` in `system_settings` (`schema_version` row). No request-time DDL is permitted.

Unversioned pre-R10 databases use the explicitly invoked guarded legacy baseline. Final cleanup and claim-slip migrations have data gates. A gate failure requires an operator decision; never purge historical evidence, fabricate codes, or manually advance the schema-version row to bypass it. DDL can implicitly commit: a failed migration is not guaranteed to roll back atomically. Stop, diagnose, and restore a verified snapshot before retrying.

Fresh-schema verification in a new isolated database passed: **22 tables, 275 columns, 57 FKs, 104 indexes, 11 CHECKs, 3 generated columns**, zero column-metadata discrepancies against R11, and **zero seeded users**. First migration imported 24 statements; a repeat run reported the schema already current. The upgrade-to-current no-op was tested; older version upgrades retain R11 evidence and must additionally be rehearsed against a copy of the actual target data.

PDO receives host/port/name/user/password externally, uses `utf8mb4`, exceptions, associative fetching, and native prepared statements. Dynamically selected identifiers/order clauses must remain server-controlled. Connection errors are generic when debug is off, with details in server logs; the separate application exception leakage is D1.

An actual full dump was restored into **`gso_deploy_audit_restore_20261011_065214`**. All 22 table row counts, complete data hashes, SHOW CREATE hashes, and version matched: **588 rows**, version `20261010_008`. Fresh database: **`gso_deploy_audit_fresh_20261011_065214`**. These audit databases were retained locally; they must never be imported into production.

Private audit dump: `%TEMP%/GSO_DEPLOYMENT_AUDIT/predeployment_restore_test.sql`; SHA-256 `24ba357710c8336222e4df326395d11a5d24ff3d033b07885c0c26875f300be3`. It is outside the web root and contains sensitive data; do not publish or commit it. Source/live database schema and data hashes were unchanged across all 22 tables. The final runbook specifies backup, file evidence backup, isolated restore, and rollback commands.

## Uploads and writable paths

| Flow/storage | Existing validation/access | Production permissions |
|---|---|---|
| Request letters: `GSO_REQUEST_LETTER_DIR` | Uploaded-file origin; actual size <=5 MiB/PHP limit; MIME + original extension match; PDF header/EOF and active-content checks; image dimensions/format/trailing-content validation; random canonical filename; traversal/symlink checks; authenticated authorized stream, CSP sandbox/no-store. | Outside app and public roots; PHP owner, directory 0750, files 0640. |
| Incident photos: `GSO_INCIDENT_PHOTO_DIR` | JPG/PNG only; MIME + image structure; <=5 MiB, <=4096/side and <=12M pixels; random canonical filename; symlink/path containment; authorized stream; GPS metadata rejection when EXIF is enabled. | Outside web/app roots; PHP owner, directory 0750, files 0640. |
| Return photos: `uploads/returns/` | JPG/PNG/GIF/WEBP, MIME + image structure, <=5 MiB; random generated filename; constrained resolver; direct HTTP denied; authorized stream. | PHP owner, directory 0750; umask 0027 yields files 0640. Keep deny rules active. |
| Resource images: `uploads/resources/` | Same general image MIME/structure checks and 5 MiB limit; generated canonical image extension; basename-based access. Intended public images. | PHP owner, directory 0750 or provider equivalent; web server read access; files 0640/0644 as appropriate. |
| User IDs/profile images | Validated image MIME/structure and size; stored as DB BLOBs rather than executable filesystem files; authenticated profile/account access. Original filenames do not become storage paths. | No application upload directory required; PHP upload temp writable. |
| PHP upload temp, session storage, error logs | Host/runtime services, not public application content. | Separate private paths; PHP-owned, 0700 for per-app sessions/temp; private logs 0640. |
| Config key cache / development outbox | Config cache writes only when `GSO_APP_KEY` is missing; outbox is nonproduction-only. | Set persistent app key so config stays read-only; disable production outbox, no runtime outbox directory needed. |

General image helpers do not check the original filename extension, but they generate a final extension from detected MIME and never store the original as a path. They do not reject every possible image polyglot; image-only serving/no execution is mandatory. Re-encoding is a P2 improvement, not an assertion that it already exists. Upload POSTs retain CSRF, capability and ownership checks from the existing controllers. Do not use 0777 or make the code tree writable.

## Mail, URLs, sessions, and error configuration

PHPMailer **7.1.1** is vendored under tracked `PHPMailer/src/`; no root Composer manifest/lock or vendor installation is used. Deploy the committed library files and license; no `composer install` is required. Review the upstream advisory list before choosing a future update, without changing dependency architecture during this audit. [PHPMailer advisories](https://github.com/PHPMailer/PHPMailer/security/advisories)

SMTP host/port/TLS/user/password/sender/name are configurable. The implementation expects email-form SMTP usernames; Gmail additionally expects its App Password format. Configure TLS/STARTTLS or SMTPS, valid CA trust, outbound provider access, and an authorized sender. No SMTP delivery test was run and no email was sent. Signup requires successful verification-mail delivery and can fail registration when SMTP is unavailable; this is distinct from post-commit request event delivery.

Transactional client events deliver synchronously **after business commit**. Delivery failures cannot roll back the request/return action. Configured client links never trust Host. Signup/reset links use the general URL helper, which trusts Host only when the canonical URL is missing. Synthetic URL checks passed for both root and subdirectory bases with an attacker-controlled Host. Production outbox refusal also passed even when fallback was explicitly enabled in the test process.

Sessions already use strict mode, cookies only, HttpOnly, SameSite Lax, login ID regeneration, idle/absolute timeout and logout cookie/session destruction. Configure `GSO_SESSION_SECURE=1` for HTTPS. Synthetic Secure/HttpOnly/Lax checks passed. Forwarded HTTPS is ignored by default and accepted only with explicit proxy trust; that flag does not validate a proxy IP by itself. Enable it only when direct backend access is blocked and the trusted proxy overwrites headers. No mixed-content localhost URLs were found in production assets. Force HTTP-to-HTTPS at the host; consider HSTS after the HTTPS configuration is proven.

Server/FPM must set `display_errors=Off`, `display_startup_errors=Off`, `log_errors=On`, and a private `error_log`. Production bootstrap repeats these settings when debug is off, but cannot hide parse/startup errors that precede execution. Current CLI has display errors on, 40M upload/post limits, 20 files, 512M memory, and no execution timeout; these are development observations, not proposed production defaults. D1 must also be fixed independently.

## Extensions, background work, and Linux compatibility

Required feature set: **PDO + pdo_mysql, mbstring, fileinfo, openssl** for SMTP TLS; standard **json, session, ctype, filter, hash, PCRE, date, SPL**, cryptographic random/password support and normal file-upload support. **curl** is required when external AI rephrasing is enabled; knowledge/live-data responses can operate without a provider. **exif** is recommended/required for the optional GPS-metadata rejection policy. GD, Imagick, mysqli, ZipArchive, and Composer are not required by the current request implementation. All listed extensions are enabled locally; GD is absent locally and is not relied on.

**NO BACKGROUND SERVICE REQUIRED.** Preventive occurrence generation/start/completion is explicit. Email is synchronous best-effort post-commit, without a queue worker/retry daemon. Corrective maintenance status synchronization runs during ordinary DB initialization and may update due schedules; overdue reminder delivery is user-action-driven. Therefore page viewing is not universally database-read-only. The production smoke plan first checks for pending corrective transitions and stops for an owner decision if any would run; it never creates inventory transactions.

Case audit: PHP-token inspection covered **231 production PHP files and 631 actual include/require expressions**. Literal resolver checked 624 include-like references and 184 public URL literals; a wider scan checked 1,234 filename tokens across PHP/JS/HTML/CSS, including navigation partials. **Zero missing resolved literal paths or filename-case mismatches were found.** The three actual dynamic include expressions are known local key/mail config paths and the return-page sidebar ternary; the latter's two paths exist with exact case. Caller-relative shared partials/JS URL construction were reviewed separately. This is static Windows-side evidence, not a Linux runtime certification. All **238 production/dependency PHP files linted successfully** on PHP 8.2.12.

Shared hosting must allow the required Apache protection directives, CLI migration access (or an operator-provided equivalent), external private writable storage, usable PDO privileges and SMTP egress. Configure per-file uploads >=5 MiB, a sufficient multipart POST limit for the form's multiple photos, memory for MIME/image/PDF validation, and request timeouts that accommodate synchronous SMTP (20s timeout) and AI (configured timeout). Do not point the document root at `public/` and assume wrappers will work.

## Production data safety and next step

Do not deploy/import any isolated test database, fake fixture users, mail captures, local key/SMTP configuration, private audit dump, or nested development copy. Never run the R1–R12 E2E suites or cleanup/seed/reset scripts against production. Back up before every migration; retain the code version matching that backup. A first real MIS account needs separately authorized provisioning using a legitimate registered/verified identity, not a fixture seed.

The ordered configuration, release, database, smoke-test and rollback procedure is in [PRODUCTION_DEPLOYMENT_RUNBOOK.md](PRODUCTION_DEPLOYMENT_RUNBOOK.md). D1 needs an approved source repair. D2–D5 need target-host/configuration/package evidence before the readiness result can become READY. No public hosting, DNS, email, or deployment action was performed.

## Classified reference inventory

Current tracked-baseline search covered hardcoded host/path/test-database patterns, configurable credential/base-URL identifiers, and Windows/development references. Each matched source line is listed below; values and secret-like literals are omitted. A = safe tests/docs/dependency examples; B = deployment configuration required; C = security-sensitive fallback; D = production-safe guarded/configured behavior. The C fallback is closed by explicit canonical URL configuration.

| File | Class | Matched line numbers |
|---|---|---|
| .env.example | B | 4, 20, 22, 23, 29, 32, 33, 51 |
| PHPMailer/src/PHPMailer.php | A | 243, 283, 2346, 2415, 2421, 3291, 3292, 4505, 4522 |
| README.md | A | 31, 35, 47, 51, 56, 58, 59, 69, 77, 88, 94, 96, 97, 99, 100, 118, 124 |
| app/models/ai_provider_helper.php | D | 88 |
| app/models/client_notification_helper.php | B | 84 |
| app/models/incident_upload_helper.php | B | 62 |
| app/models/mail_helper.php | B | 14, 22, 157 |
| app/models/mail_helper.php | C | 21 |
| app/models/mail_helper.php | D | 272 |
| app/models/request_attachment_helper.php | B | 8 |
| config/db.php | B | 4, 7, 8 |
| docs/R10_CLEANUP_CHECKPOINT.md | A | 8, 84 |
| docs/R11_SCHEMA_FREEZE.json | A | 13081, 13311, 13341 |
| docs/R11_SCHEMA_FREEZE.md | A | 1793, 1806 |
| docs/R8_CHATBOT_CHECKPOINT.md | A | 8, 51, 100 |
| docs/R9_EMAIL_CHECKPOINT.md | A | 8, 86, 137, 145 |
| docs/system_analysis.md | A | 102, 104 |
| scripts/cleanup_test_fixtures.php | A | 17, 20 |
| scripts/migrate.php | A | 5 |
| scripts/phase1_roles_e2e.php | A | 30, 32, 41 |
| scripts/phase35a_combined_borrowing_e2e.php | A | 19, 20, 58, 60 |
| scripts/phase35b_unit_tracking_e2e.php | A | 22, 23, 79, 81, 192 |
| scripts/r10_final_integration_e2e.php | A | 9, 10, 11, 63, 67, 114 |
| scripts/r10a_claim_slip_e2e.php | A | 9, 10, 11, 66, 70 |
| scripts/r1_return_lifecycle_e2e.php | A | 4, 10, 12, 13, 64, 73, 77 |
| scripts/r2a_role_director_cutover_e2e.php | A | 7, 9, 10, 58, 104 |
| scripts/r2b_manual_letter_adviser_retirement_e2e.php | A | 4, 5, 6, 26, 30, 33, 34, 35, 36, 46 |
| scripts/r3_ampm_facility_scheduling_e2e.php | A | 5, 6, 19 |
| scripts/r4_rescheduling_e2e.php | A | 5, 18, 19, 20 |
| scripts/r5_walkin_e2e.php | A | 5, 18, 19, 20 |
| scripts/r6_damage_loss_e2e.php | A | 5, 18, 19, 20 |
| scripts/r7_preventive_maintenance_e2e.php | A | 5, 9, 10, 44, 48 |
| scripts/r8_chatbot_live_inquiries_e2e.php | A | 6, 7, 39, 43, 124 |
| scripts/r9_email_notifications_e2e.php | A | 5, 6, 7, 42, 45, 98, 128 |
| scripts/request_letter_test_helper.php | A | 8 |
| scripts/seed_ai_knowledge.php | A | 9 |
| scripts/step10_live_data_e2e.php | A | 8, 84, 87, 99, 253, 531, 532 |
| scripts/step6_incident_e2e.php | A | 8, 287, 295, 303, 304, 306, 307, 308, 309, 352, 488 |
| scripts/step7_incident_reports_e2e.php | A | 8, 89, 91, 101, 102, 103, 120 |
| scripts/step8_core_e2e.php | A | 8, 259, 261, 267, 274, 329, 371, 381, 382, 869, 870 |
| scripts/step8_login_throttle_test.php | A | 8 |
| scripts/step9_chatbot_e2e.php | A | 8, 103, 106, 119, 178, 489, 635, 636, 640, 641, 656 |
| scripts/superseded/phase2_adviser_endorsement_e2e.php | A | 10, 20, 22, 43 |
| scripts/superseded/phase3_director_conflict_e2e.php | A | 18, 19, 34, 36, 78 |

Matched lines: 201. Class totals: A=181, B=17, C=1, D=2. These classifications describe the matched reference, not every operation on that line.

The separate literal-credential candidate scan was manually checked: the five production candidates are uth/forgot_password.php:91,122, uth/signup.php:270, pp/views/partials/admin_topbar.php:198, and orrower_topbar.php:188 (D: dynamic URL/CSRF interpolation, not embedded credentials). The other 38 candidates are isolated test fixtures (A). Ignored local secret files are B: provision separately, never transfer their real values into this report or a release archive.


## Remediation file inventory

The remediation commit contains these 69 reviewed files (no SQL or frozen ERD/schema metadata):

- `.env.example`
- `app/controllers/admin/create_walkin.php`
- `app/controllers/admin/maintenance.php`
- `app/controllers/admin/preventive_plans.php`
- `app/controllers/admin/requests.php`
- `app/controllers/admin/reschedules.php`
- `app/controllers/admin/review_return.php`
- `app/controllers/admin/view_incident.php`
- `app/controllers/admin/view_request.php`
- `app/controllers/admin/walkin_actions.php`
- `app/controllers/auth/signup.php`
- `app/controllers/borrower/report_issue.php`
- `app/controllers/borrower/request_letter.php`
- `app/controllers/borrower/request_resource.php`
- `app/controllers/borrower/reschedule.php`
- `app/controllers/borrower/return_submit.php`
- `app/controllers/chatbot/history.php`
- `app/controllers/chatbot/send.php`
- `app/models/ai_chat_helper.php`
- `app/models/ai_knowledge_helper.php`
- `app/models/ai_live_data_helper.php`
- `app/models/ai_provider_helper.php`
- `app/models/client_notification_helper.php`
- `app/models/equipment_unit_helper.php`
- `app/models/incident_upload_helper.php`
- `app/models/legacy_schema_upgrader.php`
- `app/models/login_throttle_helper.php`
- `app/models/mail_helper.php`
- `app/models/maintenance_helper.php`
- `app/models/preventive_maintenance_helper.php`
- `app/models/return_detail_helper.php`
- `app/models/schema_migrator.php`
- `app/models/work_order_helper.php`
- `config/bootstrap.php`
- `config/db.php`
- `config/error_handler.php`
- `config/mail.php`
- `config/production_policy.php`
- `docs/DATABASE_HOSTING_COMPATIBILITY.md`
- `docs/DEPLOYMENT_READINESS_AUDIT.md`
- `docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md`
- `docs/PRODUCTION_FILE_MANIFEST.md`
- `scripts/deployment_fault_fixture.php`
- `scripts/deployment_hardening_e2e.php`
- `scripts/phase1_roles_e2e.php`
- `scripts/phase35a_combined_borrowing_e2e.php`
- `scripts/phase35b_unit_tracking_e2e.php`
- `scripts/phase35c_workorder_accountability_e2e.php`
- `scripts/r10_final_integration_e2e.php`
- `scripts/r10a_claim_slip_e2e.php`
- `scripts/r1_return_lifecycle_e2e.php`
- `scripts/r2a_role_director_cutover_e2e.php`
- `scripts/r2b_manual_letter_adviser_retirement_e2e.php`
- `scripts/r3_ampm_facility_scheduling_e2e.php`
- `scripts/r4_rescheduling_e2e.php`
- `scripts/r5_walkin_e2e.php`
- `scripts/r6_damage_loss_e2e.php`
- `scripts/r7_preventive_maintenance_e2e.php`
- `scripts/r8_chatbot_live_inquiries_e2e.php`
- `scripts/r9_email_notifications_e2e.php`
- `scripts/staging_database_check.php`
- `scripts/staging_host_check.php`
- `scripts/step10_live_data_e2e.php`
- `scripts/step6_incident_e2e.php`
- `scripts/step7_incident_reports_e2e.php`
- `scripts/step8_core_e2e.php`
- `scripts/step8_login_throttle_test.php`
- `scripts/step9_chatbot_e2e.php`
- `scripts/test_environment_helper.php`
