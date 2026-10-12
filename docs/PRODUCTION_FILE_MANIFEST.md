# Production file and storage manifest

Reviewed 2026-10-12, Asia/Manila. This defines a future package; nothing has been deployed or moved into production. Use a committed-source allowlist, never copy the development directory wholesale. `.gitignore` does not enforce access control.

## DEPLOY — runtime web payload

- `index.php`, root `.htaccess`, public PHP wrapper directories `admin/`, `auth/`, `borrower/`, `director/`, `gso_staff/`, `pages/`, `chatbot/`.
- `app/`, `config/` **tracked PHP only**, `includes/`, `routes/`, and `public/` tracked application assets.
- `PHPMailer/src/` and `PHPMailer/LICENSE`: vendored dependency, no Composer install required.
- `uploads/.htaccess`; provision the writable upload directories separately. Do not package existing users' uploads.
- `.env.example` is a placeholder template for the operator; it can stay in the private tools bundle rather than the runtime payload.

Backend PHP is required for includes but must be denied direct HTTP access. Preserve exact filename case and both `.htaccess` files. The application uses multiple public wrappers; pointing the document root to `public/` alone is not the current architecture.

## DO NOT DEPLOY — public runtime package

Exclude **every `*.sql`**, database backups/dumps, `database/`, `scripts/`, tests, docs/ERD/audit metadata, load-test artifacts, logs, local mail captures/outbox, temporary files, caches, editor/repository metadata, archives and the entire nested ignored `GSO_WebSystem/` copy. Exclude `.env`, `config/mail.local.php`, `config/app_key.local.php` and all other local secret/config overrides. Do not transfer development uploads, test users, test DB dumps, temp audit directories or source-folder backups.

The migration/rehearsal tools require SQL and frozen metadata. Keep these in a **separate server-private tools checkout** outside ALL document roots and aliases, with the same relative repository layout. That private checkout may contain `database/schema.sql`, reviewed migrations, `scripts/migrate.php`, staging checkers, their config/model/include dependencies and `docs/R11_SCHEMA_FREEZE.json`. It is not the public deployment package. Do not run migration from the SQL-free runtime directory. The ordinary application only checks its recorded version and does not import SQL.

Build example after commit/approval (commands are future operator instructions):

```sh
git archive --format=tar APPROVED_RELEASE_COMMIT \
  index.php .htaccess app config routes includes public pages auth admin \
  borrower director gso_staff chatbot PHPMailer uploads/.htaccess > runtime.tar
tar -tf runtime.tar > runtime-file-list.txt
```

Inspect the archive list before transfer. Reject any `.sql`, `.env`, `.local.php`, `database/`, `scripts/`, `docs/`, nested project, mail capture, audit/temp file or unexpected extension. Confirm all required wrappers, assets, dependency files and protection files exist. Source may evolve; an allowlist is not a permanent substitute for list review. Never include archive/list files themselves in the served tree.

## SERVER-PRIVATE — configuration, storage and tools

Provide secrets through the PHP process environment, or provision the ignored application-root `.env` privately with 0600/0640 permissions and proven HTTP denial. Do not put real values in the release archive, command arguments, transcripts or documentation. Keep tools/SQL, logs, backups, session files, upload temporary files and private evidence outside the served tree and every alias. The persistent app key is used for keyed login-throttle identifiers; preserve it between releases and disaster recovery.

| Writable area | Example production path | Public access / execution | Permissions and ownership |
|---|---|---|---|
| Resource images | Runtime `uploads/resources/`, preferably host-managed shared storage | Public generated image names only; uploads rules deny scripts/double extensions/nonimages | PHP runtime user owns writes; 0750/0755 directories and 0640/0644 files depending on web-reader group. No execution handler; apply upload deny rules to physical target too. |
| Return proof photos | Runtime `uploads/returns/`, preferably host-managed shared storage | Direct URL denied; authorized application photo endpoint only | PHP user + restricted evidence-reader group; 0750 directories, 0640 files where supported; no executable handler. Existing helper's mkdir mode is 0755, so provision directories and a suitable umask/ACL before use. |
| Incident photos | `GSO_INCIDENT_PHOTO_DIR=/srv/gso/shared/private/incident_photos` | Outside every document root/alias; authorized controller only | Precreate 0750 directory, 0640 files; PHP user with limited service group. |
| Facility request letters | `GSO_REQUEST_LETTER_DIR=/srv/gso/shared/private/request_letters` | Outside every document root/alias; ownership/role authorization required | Precreate 0750 directory, 0640 files; PHP user + restricted evidence group. |
| IDs/profile images | DB BLOBs, not filesystem uploads | Through existing authorization and image/document response logic | DB DML account; no public folder or executable filename. |
| PHP sessions | `/srv/gso/shared/sessions` | Server-private, never served/executed | 0700 directory, PHP service user; provider-managed session permissions. |
| Multipart temporary files | `/srv/gso/shared/upload_tmp` | Server-private, never served/executed | 0700 directory; PHP service user; provider-managed lifecycle. |
| Error logs | `/srv/gso/shared/private/logs/php_error.log` | Private diagnostics only, never served | 0750 directory / 0640 file; service writer, administrator reader; rotate/retain securely. |
| Backup/config/key/tools | `/srv/gso/backups`, `/srv/gso/secrets`, `/srv/gso/tools/RELEASE` | Server-private, never aliased | Operator-owned; 0700/0750 dirs, secrets 0600/0640; runtime has no backup/DDL write privileges. |
| Test mail capture | No production path | Forbidden in production/staging | Do not provision or package a test outbox. |

Code/config should be operator-owned, read-only to PHP. Do not use 0777. Use Windows ACL equivalents on Windows. Prove real PHP/FPM identity, group access, umask, symlink resolution and aliases on staging. Neither PHP path checks nor a nominal `/private` name can discover every host alias; server configuration review remains mandatory.

## Preserved local backups

Historical source: `C:\xampp\htdocs\GSO_WebSystem\GSO_WebSystem\docs\db_cleanup_backup_before_cleanup.sql`, **21,396,407 bytes**, SHA-256 **`CE4571F406AB3F4FDC45162CB3892DC5DC61E9968D0D8AFAB81074BD6F547740`**.

A byte-identical external safety copy exists at `C:\Users\ACER\AppData\Local\Temp\GSO_REMEDIATION\backups\db_cleanup_backup_before_cleanup.sql`; source and copy SHA-256 were verified. The separate predeployment full dump remains under `%TEMP%\GSO_DEPLOYMENT_AUDIT\predeployment_restore_test.sql`, with its earlier restore/hash verification retained. **Temp is a verified safety copy, not durable institutional backup storage.** Recommend encrypted durable storage outside the web root, e.g. `C:\xampp\private\GSO_WebSystem\backups\` locally and an institutionally managed off-host copy. Preserve the original until durable copies/restoreability are confirmed and cleanup is separately approved. No backup or nested project was deleted, moved or overwritten.

## Target protection contract

Apache 2.4 must honor root and uploads `.htaccess`: mod_rewrite, authz support, permitted Options/FileInfo/AuthConfig overrides (or equivalent explicit virtual-host directives), and the application's symlink option when relevant. Root rewrite denial is intentionally fail-closed; missing support may cause HTTP 500 and must be fixed by the host before opening access. Optional mod_headers adds headers; it does not replace path denial. Nginx/other servers need equivalent deny/nonexecution rules installed by the operator. Do not blindly paste unsupported Apache `php_flag` directives into shared hosting.

Execute `scripts/staging_host_check.php` from the private tools checkout over verified HTTPS. It probes without printing bodies, cookies or documents and leaves manual gates UNVERIFIED. A real target must prove known-existing upload PHP denial, unrelated-user evidence denial, private storage layout, canonical links, Secure/HttpOnly/SameSite cookies, private safe logs and runtime PHP settings. See the runbook. No actual target host has passed these gates.
