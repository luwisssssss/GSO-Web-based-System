# GSO production deployment runbook

Prepared 2026-10-11, Asia/Manila. Audited baseline: `96042c9b8368e63bd9f3b9fd3904055b41ea0083`; frozen schema: **20261010_008**. See [DEPLOYMENT_READINESS_AUDIT.md](DEPLOYMENT_READINESS_AUDIT.md).

**Deployment is blocked until the audit's P1 findings/gates are closed.** The commands below are a future operator procedure, not commands executed by this audit. Substitute actual approved host paths, identities, release commit and database names. Production deployment, real email tests, and any one-time account provisioning require explicit authorization. Do not change the frozen schema to fit a hosting package without a separately reviewed compatibility decision.

## 1. Select and rehearse the hosting environment

1. Select patched Apache 2.4/PHP and a supported **MariaDB** engine. PHP language floor is 8.0, but use a supported 8.3/8.4 branch with the exact release rehearsed on staging. Do not deploy the local PHP 8.2.12/MariaDB 10.4.32/Apache 2.4.58 stack. MySQL-only hosting is not certified: the frozen stored generated-column/cascading-FK combination conflicts with documented MySQL restrictions.
2. Require PDO/pdo_mysql, mbstring, fileinfo, OpenSSL and standard JSON/session/ctype/filter/hash/PCRE/date/SPL facilities. Enable curl for external AI; enable EXIF if GPS rejection is required. No current GD/Imagick/Composer dependency exists.
3. Confirm CLI PHP, database migration privileges, SMTP TLS egress/CA trust, and private storage outside the document root. If SSH is unavailable, require the hosting operator to execute the same checked CLI procedure; do not expose migration PHP as a web installer.
4. Rehearse fresh import or an upgrade on a copy of the real target database, then restore it. Confirm all 22 tables, 275 columns, 57 FKs, 104 indexes, 11 CHECKs and 3 generated columns. Test on the actual database version; the local restore/import proves MariaDB 10.4 behavior only.
5. Close D1 with the approved error-message patch and isolated fault tests. Recheck the approved release's hashes, syntax, access rules and smoke results. Do not deploy the currently audited source while D1 remains.

## 2. Pre-deployment backup and recovery preparation

Put an existing installation into host-level maintenance mode so application writers are stopped. Keep CLI/operator access; there is no application maintenance environment flag. Stop concurrent DDL during backup. Record the running code commit, DB version, schema version and row counts.

Keep DB client options in a private file, e.g. `/srv/gso/secrets/backup.cnf`, mode 0600, readable only by the backup operator. Do not put passwords in argv, Git, shell history or the web root. Use the matching MariaDB client (`mysql`/`mysqldump` names may be compatibility aliases):

```sh
umask 077
BACKUP_DIR=/srv/gso/backups/APPROVED_CUTOVER_TIMESTAMP
DB_NAME=APPROVED_PRODUCTION_DATABASE
mkdir -p "$BACKUP_DIR"
mysqldump --defaults-extra-file=/srv/gso/secrets/backup.cnf \
  --single-transaction --hex-blob --routines --triggers --events \
  --default-character-set=utf8mb4 "$DB_NAME" > "$BACKUP_DIR/database.sql"
sha256sum "$BACKUP_DIR/database.sql" > "$BACKUP_DIR/database.sql.sha256"
sha256sum -c "$BACKUP_DIR/database.sql.sha256"
```

`--defaults-extra-file` must be the first client option. Include BLOB data (IDs/profile images) with `--hex-blob`. Backup all existing routines/triggers/events even though the frozen application does not require background jobs. Use a dedicated backup credential with sufficient privileges, not the web application's DML-only credential.

Also archive the release/configuration securely and capture its checksum. During the same writer pause, back up the actual `uploads/resources`, `uploads/returns`, configured incident directory and request-letter directory. For the example layout used below:

```sh
tar -C /srv/gso -cf "$BACKUP_DIR/evidence.tar" \
  shared/uploads shared/private/incident_photos shared/private/request_letters
sha256sum "$BACKUP_DIR/evidence.tar" > "$BACKUP_DIR/evidence.tar.sha256"
```

Adapt paths to the existing site; check every source exists before proceeding. Secure/encrypt backups according to institutional policy, set retention, and keep an additional recoverable copy outside the hosting account. Preserve the previous app key and session configuration privately.

The local ignored `GSO_WebSystem/docs/db_cleanup_backup_before_cleanup.sql` is physically inside the local web root. Preserve it externally and verify its checksum before any separately approved cleanup. Never include that ignored nested tree in a release. This audit did not move or delete it.

Before changing production, restore the new DB dump into a **new empty isolated database**, not the live database:

```sh
RESTORE_DB=gso_restore_review_APPROVED_TIMESTAMP
mysql --defaults-extra-file=/srv/gso/secrets/migration.cnf \
  -e "CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql --defaults-extra-file=/srv/gso/secrets/migration.cnf \
  --default-character-set=utf8mb4 "$RESTORE_DB" < "$BACKUP_DIR/database.sql"
```

The operator must verify the database name is newly created and distinct from production. Compare per-table row counts, primary-key-ordered full-row hashes (including BLOBs), normalized SHOW CREATE definitions, schema version, FK orphan checks, and storage-file manifests before accepting the backup. The audit's local restore passed these schema/data hash checks for 22 tables and 588 rows. Do not copy any audit/test database into production.

## 3. Provision production configuration

Use the existing bootstrap/environment mechanism. Do not introduce another configuration framework. `.env.example` is verified placeholder-only and is unchanged. Host process variables override `.env`; `config/mail.local.php` overrides mail values after that, so **exclude every local override file** from the release.

Create the real secret configuration privately, then install it with mode 0640 or 0600 so the PHP user can read it. The app loads `.env` from its application root; alternatively supply the values through the hosting PHP process environment. Apache rules must deny `.env`. Never copy the development `.env` or key cache.

```dotenv
GSO_APP_ENV=production
GSO_APP_DEBUG=0
GSO_APP_URL=https://YOUR_APPROVED_HOST/GSO_WebSystem
GSO_APP_TIMEZONE=Asia/Manila
GSO_APP_KEY=REPLACE_WITH_A_PERSISTENT_RANDOM_32_BYTE_SECRET_IN_HEX

GSO_SESSION_SECURE=1
GSO_SESSION_SAMESITE=Lax
GSO_SESSION_IDLE_TIMEOUT=1800
GSO_SESSION_ABSOLUTE_TIMEOUT=28800
GSO_TRUST_PROXY_HEADERS=0

GSO_DB_HOST=YOUR_DATABASE_HOST
GSO_DB_PORT=3306
GSO_DB_NAME=YOUR_PRODUCTION_DATABASE
GSO_DB_USERNAME=YOUR_RUNTIME_DML_ACCOUNT
GSO_DB_PASSWORD=YOUR_PRIVATE_RUNTIME_PASSWORD

GSO_INCIDENT_PHOTO_DIR=/srv/gso/shared/private/incident_photos
GSO_REQUEST_LETTER_DIR=/srv/gso/shared/private/request_letters
GSO_MAIL_OUTBOX_DIR=/srv/gso/shared/private/disabled_mail_outbox
GSO_ALLOW_LOCAL_MAIL_FALLBACK=0

GSO_SMTP_HOST=YOUR_SMTP_HOST
GSO_SMTP_PORT=587
GSO_SMTP_SECURE=tls
GSO_SMTP_USERNAME=YOUR_AUTHORIZED_SMTP_EMAIL
GSO_SMTP_PASSWORD=YOUR_PRIVATE_PROVIDER_PASSWORD
GSO_SMTP_FROM=YOUR_AUTHORIZED_SENDER_EMAIL
GSO_SMTP_FROM_NAME=GSO Management System

AI_ENABLED=true
AI_PROVIDER=none
AI_API_KEY=
AI_API_BASE_URL=https://api.deepseek.com
AI_MODEL=deepseek-chat
AI_TIMEOUT_SECONDS=20
AI_MAX_TOKENS=600
```

This example contains placeholders, not usable credentials. For root hosting set `GSO_APP_URL=https://YOUR_APPROVED_HOST` instead. Configure external AI only after institutional approval and proper provider credentials; `none` retains knowledge/live-data responses without an external call. Do not set `GSO_MAIL_TEST_TRANSPORT` or test DB names in production.

Generate the persistent app key into a private file instead of a terminal transcript:

```sh
umask 077
php -r 'file_put_contents("/srv/gso/secrets/new-app-key", bin2hex(random_bytes(32)).PHP_EOL);'
```

Transfer its value privately into production configuration. Keep the key stable across releases. Supplying it prevents `config/app_key.local.php` generation and lets config remain read-only.

Use separate database credentials: runtime account with SELECT/INSERT/UPDATE/DELETE on the application database; migration/backup account with reviewed DDL/reference/backup privileges. Supply migration credentials only to the migration CLI process. The current PDO setup does not expose SSL attribute configuration; select co-located/private trusted DB connectivity or approve and validate the required remote-TLS support first.

## 4. Build and transfer only the approved release

Build a new release directory, never overwrite the running code in place. These example paths intentionally keep backups, secrets and private evidence outside the eventual application document root.

```sh
RELEASE=APPROVED_REMEDIATED_RELEASE_COMMIT
APP=/srv/gso/releases/$RELEASE
test ! -e "$APP" || { echo 'Release directory already exists; stop.'; exit 1; }
mkdir -p "$APP"
git archive --format=tar "$RELEASE" \
  index.php .htaccess .env.example app config routes includes public pages \
  auth admin borrower director gso_staff chatbot PHPMailer database \
  scripts/migrate.php uploads/.htaccess | tar -xf - -C "$APP"
```

The allowlist includes reviewed SQL/migrations and the migration CLI but not E2E/cleanup/seed/reset tools, docs, Git metadata, ignored nested copies, uploads, local credentials, logs or audit artifacts. Deployment tools must transfer dotfiles, especially both `.htaccess` files. Preserve file-name case. Verify release checksums and the three required PHPMailer source files after transfer.

The application uses direct PHP wrappers and assets. Point the host's application mount to the release **application directory**, not `public/` alone. For subfolder hosting map `/GSO_WebSystem/` there; for root hosting map `/` there. Host-provided nginx/Apache aliases must not expose `/srv/gso/shared/private`, `/srv/gso/backups`, or `/srv/gso/secrets`.

## 5. Dependencies and write permissions

No root `composer.json`/`composer.lock` exists. PHPMailer 7.1.1 is tracked and deployed directly with its license; do not invent a `composer install` step. Install required PHP extensions through the hosting provider/system administrator, then verify them in both CLI and web PHP.

Create writable paths with the PHP worker as owner and a web-server-readable group, adapting user/group names to the hosting account. Code remains owned by the deployment operator and readable, not writable, by PHP. Typical values: code directories 0755/files 0644 (or tighter provider-compatible values); private/writable directories 0750, evidence files 0640, per-app session/temp directories 0700; umask 0027 for new evidence. Never use 0777.

```sh
install -d -m 0750 -o PHP_USER -g WEB_GROUP \
  /srv/gso/shared/uploads/resources /srv/gso/shared/uploads/returns \
  /srv/gso/shared/private/incident_photos /srv/gso/shared/private/request_letters
install -d -m 0700 -o PHP_USER -g WEB_GROUP \
  /srv/gso/shared/sessions /srv/gso/shared/upload_tmp
```

During initial release preparation, use the included `uploads/.htaccess` at the real upload root and attach persistent uploads to each release. One option is a persistent shared upload directory:

```sh
install -m 0644 "$APP/uploads/.htaccess" /srv/gso/shared/uploads/.htaccess
ln -s /srv/gso/shared/uploads/resources "$APP/uploads/resources"
ln -s /srv/gso/shared/uploads/returns "$APP/uploads/returns"
```

These symlinks are upload **directory** links, not uploaded files. `resolveReturnPhotoPath` rejects symlink files and checks the resolved directory containment. Verify the actual Apache symlink policy and denial rules on staging; do not use symlinks for private letter/incident storage roots because their helpers reject them. If symlinks are forbidden, use the provider's persistent mount/copy mechanism under `uploads/` while preserving the same URL protections and backed-up evidence.

Keep the return-photo directory denied to direct HTTP. Resource images are intentionally public; Apache must treat allowed image extensions only as static content. Verify both storage directories as the real PHP worker, not just the SSH user. User IDs/profile images are DB BLOBs; no filesystem directory is required for those.

## 6. Database installation or migration

Provision production configuration privately first. Use scoped environment values for the CLI migration account; do not overwrite runtime credentials or put passwords on argv. A private approved wrapper/hosting secret injector may export `GSO_DB_USERNAME` and `GSO_DB_PASSWORD` only for this CLI process.

For a shell-capable host, the operator can use this scoped function. `/srv/gso/secrets/migration.env` must be an operator-authored, mode-0600 **shell-format** secret file containing only the reviewed GSO database host/port/name/username/password assignments. Its database must match the intended production target. Do not source an application `.env`, downloaded file, or unreviewed content as shell code. The subshell prevents deployment credentials from replacing runtime settings afterward.

```sh
run_gso_migration() (
  set -a
  . /srv/gso/secrets/migration.env
  set +a
  cd "$APP"
  php scripts/migrate.php
)
```

**Brand-new database:** create an empty UTF-8 database using the DBA account; confirm no tables exist; run the migration CLI once. Do not import a test database or fixture dump.

```sh
mysql --defaults-extra-file=/srv/gso/secrets/migration.cnf \
  -e 'CREATE DATABASE `YOUR_PRODUCTION_DATABASE` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
run_gso_migration
```

The CLI detects the empty database and imports `database/schema.sql`, recording `20261010_008`. Do not also import schema.sql manually afterward. It contains no users, password hashes, requests or evidence: only structure, two operating-hour defaults and the version row. Confirm institutional scheduling settings through GSO; do not invent AM/PM boundaries.

**Existing database:** keep writers in maintenance mode, verify backup/restore and schema version, and run `php scripts/migrate.php` against the configured existing database with migration credentials. Never import `database/schema.sql` over existing tables. Lexically ordered newer migrations run once, recording each successful version. Review the retired-history and claim-slip gates against a staging copy before the cutover. Do not bypass gates by changing the version row or deleting records.

```sh
mysql --defaults-extra-file=/srv/gso/secrets/migration.cnf YOUR_PRODUCTION_DATABASE \
  -e "SELECT setting_value FROM system_settings WHERE setting_key='schema_version'"
run_gso_migration
```

Stop on any failed statement/gate. DDL may have committed partially; do not continue blindly. Use the verified pre-change restore procedure below, or a specifically reviewed recovery plan.

After either path, check the version and frozen counts, plus FK integrity and first-real-account readiness. A new database has zero users. Use registration/verification of a legitimate MIS operator and an institutionally approved one-time DBA role provision; do not seed fixture users or share a default password. Account bootstrap is a separately authorized security operation. Preserve proof of identity/approval and retain account audit records; ordinary accounts are then managed through MIS.

## 7. HTTPS, base URL, server protection, and PHP settings

Configure a valid certificate and force HTTP-to-HTTPS at the trusted host/proxy. Root `.htaccess` requires mod_rewrite and authorized directive overrides; enable equivalent deny rules if the host does not read `.htaccess`. Apache also needs its permitted symlink option when using the layout above. Header-module hardening is recommended. [Apache rewrite requirements](https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html)

Set these in the actual server/FPM configuration, not only CLI:

```ini
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /srv/gso/shared/private/php_error.log
session.save_path = /srv/gso/shared/sessions
upload_tmp_dir = /srv/gso/shared/upload_tmp
upload_max_filesize = 5M
post_max_size = 32M
max_file_uploads = 20
```

The values for multipart POST size/file count must fit the actual deployment's forms and provider limits; 32M is a starting operational example, not an application-enforced aggregate limit. Configure memory and execution/FPM timeouts based on staging load, including synchronous SMTP's 20s timeout and the AI timeout. Configure private log rotation/retention. Apply D1's safe user-facing error patch; server-level error suppression alone does not fix it.

Use `GSO_APP_URL` as the canonical HTTPS root/subfolder. Configure virtual-host hostname acceptance; test email-link construction with an attacker Host in a no-send harness. Enable `GSO_TRUST_PROXY_HEADERS=1` only behind a controlled proxy that overwrites forwarded headers and prevents direct backend access. It is not an IP allowlist. Prove Secure/HttpOnly/Lax cookies over the deployed HTTPS URL. Add HSTS only after HTTPS and recovery access are confirmed.

## 8. SMTP and provider configuration

Set host, provider port, `tls`/STARTTLS or `ssl`/SMTPS, email-form SMTP username, private password, sender email/name and canonical app URL. Confirm valid CA trust and authorized sender/provider restrictions. Gmail configuration expects an App Password, not a normal account password. Remove/exclude local mail overrides and leave local/test outbox transports disabled. Verify production guard behavior without sending mail.

A real email smoke test requires separate authorization identifying an approved recipient. Do not trigger signup/reset/reminder/request-action mail just to test SMTP during an unapproved audit. Transactional request mail is synchronous best-effort after commit, with no worker or retry queue; account registration additionally requires verification mail to succeed.

## 9. Activate release and handle caches/sessions

After the approved database step and pre-activation checks, point the host's application mount/current-release link to the new release atomically, using the provider's release-switch mechanism. Do not change public DNS during this procedure unless separately approved.

Reload PHP-FPM or invalidate OPcache through the hosting control panel so files from the previous release cannot remain cached. No application build cache, Redis cache or queue process is required. Preserve the app key. Keep session paths/cookie names consistent unless a planned session invalidation is required; use a separately configured `GSO_SESSION_NAME` or an operator-approved session reset, not deletion of unrelated host session files.

**NO BACKGROUND SERVICE REQUIRED:** preventive occurrences are explicitly generated/started/completed; email is post-commit synchronous; overdue reminders are user actions. Corrective schedules can synchronize during DB initialization. Before non-destructive smoke tests on existing data, run this read-only check:

```sql
SELECT COUNT(*) AS pending_automatic_transitions
FROM maintenance_schedules
WHERE (maintenance_type IS NULL OR maintenance_type = 'Corrective')
  AND ((status = 'Scheduled' AND CURDATE() BETWEEN start_date AND end_date)
    OR (status = 'In Progress' AND start_date > CURDATE()));
```

If the count is nonzero, stop for the GSO owner's operational decision before loading database-backed pages. Those visits could perform normal automatic state changes; the application has no general read-only smoke-mode flag. Do not fabricate cron jobs or flip internal constants to change this architecture.

## 10. Non-destructive production smoke tests

Use real, existing, approved accounts with their owners' authorization. Do not run E2E suites, create fake users, send chat messages, upload evidence, approve/release/return resources, generate preventive occurrences or create walk-ins during this smoke pass. Open forms and lists without submitting changes. Logout is normal session handling.

| Area | Checks |
|---|---|
| Public | Root redirects to landing and final HTTP 200; landing renders; login/registration forms open (if registration enabled); no warnings, fatal errors or local/mixed-content links. |
| Borrower | Authorized login; Browse; My Requests; chatbot opens without sending; profile opens; correct user's records only. |
| GSO Staff / GSO Head | Login and own dashboard; request list; Walk-in form without saving; maintenance/work orders; Head-only preventive plans and maintenance history; Staff cannot access Head-only actions. |
| Director | Read-only facility schedule; attempts to access operational approval/creation routes denied. |
| MIS / Super Admin | Account management opens; operational inventory/request privileges are not inherited. |
| Private evidence | Anonymous/private-document request redirects or denies without a document; unrelated borrower gets no evidence; authorized role may view a known approved sample only when its owner authorizes the check. |
| Backend exposure | `.env`, `.git/config`, config, schema/migrations, scripts, docs, internal includes/models, PHPMailer/vendor, backups and direct return uploads must return 403/404, never contents. Use known denied paths; do not request private file bodies when a probe unexpectedly returns 200—treat exposure as a blocker. |
| Session/HTTPS | HTTPS-only canonical links; Secure + HttpOnly + SameSite cookies; approved session redirects; unauthorized role denial; logout invalidates session; forwarded headers cannot be spoofed from the public backend. |
| Runtime | Private directories writable by PHP; code/config not writable; private logging works; no unwanted mail capture; schema `20261010_008`, expected metadata, no new orphan rows. |

Record results, deployment commit, timestamps, host PHP/DB versions and response codes without passwords/tokens/private document content. Request separate authorization before any real email or inventory transaction. Only reopen public access after all P1 gates and this checklist pass.

## 11. Rollback procedure

1. Put the site back into host-level maintenance mode; stop writers and record the failure. Preserve private logs and the failed release for diagnosis.
2. Verify backup checksums. Identify the code release and exact schema/data snapshot that belong together. Never run old code against a newer schema merely by changing the release pointer.
3. If the DB was unchanged, switch back to the previous approved release, reload PHP/OPcache, handle sessions as planned, and rerun read-only smoke checks.
4. If migration/data changed, restore the verified pre-cutover dump into a **new empty recovery database** using the same isolated restore commands in section 2. Compare table counts/data hashes/schema version/FKs. Do not drop or overwrite the failed production database as a first recovery step.
5. Restore matching file evidence to a new recovery storage location and verify its manifest, so DB filenames point to the correct files. Do not overwrite unrelated storage or restore mail captures.
6. Point the previous release's private configuration to the recovery database/storage, preserve its app key, and switch the host mount after operator approval. Reload PHP and invalidate/rotate sessions if required to avoid stale authorization.
7. Recheck unauthorized routes/private evidence denial, public landing, role views and the zero-pending-corrective-transition gate. Reopen public access only when the institution accepts the recovery state. Reconcile any legitimate transactions after the backup separately; never silently discard them.

## Local audit evidence and restrictions

Local fresh migration, repeat migration, full restore and frozen metadata comparison passed. Test databases and sensitive dump are identified in the audit report and remain local/outside the web root. All 22 source-table schema/data hashes remained unchanged. No production email, DNS change, public deployment or application repair was performed. These results do not replace staging certification of the actual hosting engine, filesystem, web server, PHP SAPI, SMTP path and private-storage layout.
