# 📦 Web-Based General Services Office (GSO) Borrowing and Inventory Management System

## 📖 Overview

The Web-Based GSO Borrowing and Inventory Management System is a centralized application developed to automate and streamline the borrowing and inventory processes of the General Services Office. The system replaces manual operations with a digital platform that enhances efficiency, accuracy, and transparency in managing organizational resources.

## 🎯 Objectives

* Automate borrowing request submission and approval processes
* Provide real-time tracking of inventory and borrowed items
* Improve monitoring and reporting of resource usage
* Implement notification mechanisms for system updates
* Ensure secure, role-based access control

## ✨ Key Features

* User Authentication and Authorization (Admin and Borrower roles)
* Borrowing Request Management (submit, approve, reject, release)
* Inventory Management System (resource tracking and stock monitoring)
* Return Processing Module
* Notification System (real-time alerts and updates)
* Low Stock Alert Mechanism
* Activity Logs and Reports Generation

## 🛠️ Technologies Used

* Frontend: HTML, CSS, JavaScript
* Backend: PHP
* Database: MySQL
* Email Service: PHPMailer
* Server Environment: XAMPP (Apache)

## ⚙️ System Requirements

* XAMPP (Apache & MySQL)
* Modern web browser (Chrome, Edge, Firefox)

## 🚀 Installation Guide

### 1. Clone the Repository

git clone https://github.com/luwisssssss/GSO-Web-based-System.git

### 2. Move the Project Folder

Place the project inside:
C:\xampp\htdocs\

### 3. Start the Server

* Open XAMPP Control Panel
* Start Apache and MySQL

### 4. Setup Database

* Open http://localhost/phpmyadmin or use the MySQL command-line client.
* Create the application database (the example configuration uses `gso_database`).
* New installation: run `C:\xampp\php\php.exe scripts\migrate.php` against the empty database, or import `database/schema.sql` into it. The file is the complete current schema and records its version. It contains no users, password hashes, requests, or evidence, and it fails on purpose if the tables already exist.
* Existing installation: run `C:\xampp\php\php.exe scripts\migrate.php` with the deployment database account after deploying new code. It applies the ordered files in `database/migrations/` that are newer than the version recorded in `system_settings.schema_version`, and stops without dropping anything if a cleanup migration would destroy data. Do not import `database/schema.sql` into an existing database.
* Never deploy database backups from `docs/`, an export folder, or another web-accessible location.
* The web application never alters, backfills, or migrates the schema. Each request only checks that the recorded schema version matches the version the code requires, and answers HTTP 503 until `scripts/migrate.php` has been run. Create a dedicated `gso_app` MySQL account for the application, limit it to the required runtime operations on `gso_database`, and run migrations with a separate deployment account. Do not run the web application as MySQL `root`.

## Phase 1 roles and permissions

Authorization roles are `Borrower`, `GSO Staff`, `Admin` (the GSO Head), `Director` (the Campus Director), and `Super Admin` (MIS account management only). Borrower classification is stored separately in `users.account_type` as `Student`, `Faculty`, or `University Staff`; legacy Borrowers remain safely unclassified until verified.

Role landing pages are `borrower/browse.php`, `gso_staff/dashboard.php`, `admin/dashboard.php`, `admin/accounts_dashboard.php` (MIS), and `director/dashboard.php`. Permissions are centralized in `app/models/role_helper.php`. The Director has a read-only facility schedule and no approval, rejection, or prioritisation action. There is no online Adviser workflow: required signatures are obtained manually on the facility request letter, which GSO verifies.

Run `C:\xampp\php\php.exe scripts\phase1_roles_e2e.php` with local Apache/MySQL to verify role login routing and direct endpoint isolation.

## Phase 3.5C maintenance and accountability

`maintenance_schedules` is the authoritative work-order record. Incident work links through `incident_id`; repair work arising from an inspected return links to the exact `return_submission_items.return_item_id` and, for serialized equipment, its `equipment_units.unit_id`. A planned end date never restores repair inventory: an authorized GSO user must explicitly complete the assigned work order, after which only its recorded aggregate quantity or physical unit is restored once.

Only the Admin/GSO Head may confirm a repair work order from a damaged, needs-repair, or missing-parts return. Lost items cannot enter the repair workflow. `borrower_liabilities` records separate, non-financial GSO findings for damage, loss, or missing parts; it neither presumes borrower fault nor changes stock.

Equipment-unit mutation requires `inventory.manage_units`. GSO Staff and Admin may register and maintain eligible units, while activation of Serialized tracking remains an exact Admin/GSO Head action. Run `C:\xampp\php\php.exe scripts\phase35c_workorder_accountability_e2e.php` to verify the Phase 3.5C schema, authorization, repair-restoration, idempotency, and accountability controls.

### 5. Configure Environment Variables

* Copy `.env.example` to `.env`; never commit or serve the resulting file.
* Replace every placeholder value and use an HTTPS application URL in production.
* Keep `GSO_INCIDENT_PHOTO_DIR` and `GSO_MAIL_OUTBOX_DIR` as absolute paths outside `htdocs`.

```dotenv
GSO_APP_ENV=production
GSO_APP_DEBUG=0
GSO_APP_URL=https://your-gso-host.example/GSO_WebSystem
GSO_SESSION_SECURE=1
GSO_SESSION_IDLE_TIMEOUT=1800
GSO_SESSION_ABSOLUTE_TIMEOUT=28800
GSO_TRUST_PROXY_HEADERS=0

GSO_DB_HOST=localhost
GSO_DB_NAME=gso_database
GSO_DB_USERNAME=gso_app
GSO_DB_PASSWORD=replace-with-a-strong-random-password

GSO_INCIDENT_PHOTO_DIR=C:/xampp/private/GSO_WebSystem/incident_photos
GSO_MAIL_OUTBOX_DIR=C:/xampp/private/GSO_WebSystem/mail_outbox
GSO_ALLOW_LOCAL_MAIL_FALLBACK=0

GSO_SMTP_HOST=smtp.gmail.com
GSO_SMTP_USERNAME=your-email@gmail.com
GSO_SMTP_PASSWORD=replace-with-a-provider-app-password
GSO_SMTP_PORT=587
GSO_SMTP_SECURE=tls
```

Provision the private storage parent explicitly. Grant read/write access only to the Apache/PHP service identity and trusted administrators, disable inherited access for general local users, and retain the trusted owner account so the folder remains maintainable. On Windows, confirm the Apache identity with `sc.exe qc Apache2.4` before setting ACLs. Do not add an Apache alias for either private directory. Incident photos are streamed only through authenticated PHP endpoints; the local outbox is a development artifact that may contain recipient data and account links.

Local mail fallback is disabled by default. To test it, use a non-production environment and explicitly set `GSO_ALLOW_LOCAL_MAIL_FALLBACK=1`; the code still accepts only a loopback request (or a direct CLI run). It does not trust the HTTP `Host` header. Review and securely relocate any legacy files under `uploads_temp/email_outbox` before removing them according to the project's retention policy.

The project currently vendors PHPMailer 7.1.1 under `PHPMailer/` because it has no Composer bootstrap. Keep the bundled version patched and review upstream security releases before deployment. Do not remove this directory unless the application is first migrated and tested with a reproducible Composer installation.

### 6. Configure Apache

The root `.htaccess` and `uploads/.htaccess` files are required deployment files. Apache must load `mod_rewrite` and `mod_authz_core`, and the project directory must permit the required overrides (for XAMPP, `AllowOverride All`). The root rules deliberately fail closed when `mod_rewrite` is unavailable.

Before exposing the application, run `httpd.exe -t` and verify that sensitive URLs such as `/.env`, `/.git/HEAD`, `/config/db.php`, `/database/`, `/docs/`, `/uploads/returns/`, and `/uploads_temp/` return `403`. Verify that the login page and a real image under `/uploads/resources/` still return `200`.

### 7. Run the Application

Use `http://localhost/GSO_WebSystem` only for isolated local development. Production must use the HTTPS URL configured in `GSO_APP_URL`.

## R7 preventive maintenance and maintenance history

`maintenance_schedules` stays the only work-order table. `maintenance_type` separates `Corrective` from `Preventive` occurrences. Rows that predate the column keep `NULL` unless a return or incident link proves they were corrective.

`preventive_maintenance_plans` stores the recurring policy: target resource, optional real equipment unit or aggregate quantity, recurrence (`Once`, `Monthly`, `Quarterly`, `Semiannual`, `Annual`), `next_due_date`, duration, and assignee. Recurrence uses calendar months and clamps to the last day of short months. Consumables and Lost targets are rejected.

There is no background scheduler. The GSO Head generates the due occurrence from **Work Orders > Preventive Plans**, which creates one `maintenance_schedules` row linked by `preventive_plan_id` and `plan_occurrence_date`. A unique key on that pair makes generation idempotent. No page load generates work.

A Scheduled occurrence reduces availability only for its own dates, unit, or quantity, and does not change current stock. Starting it holds exactly that unit or quantity, and completing it restores exactly what was held. Completion and cancellation are explicit actions. A passed end date never completes maintenance, and `next_due_date` advances only when an occurrence is completed.

Maintenance History (`admin/maintenance_history.php`) lists Completed and Cancelled rows from `maintenance_schedules`. Plan management requires `maintenance.plan.manage` (Admin/GSO Head). GSO Staff may start and complete occurrences assigned to them. Existing installations apply `database/migrations/20261010_006_preventive_maintenance.sql`.

## R10 final cleanup and explicit migrations

Migration `20261010_007_final_cleanup.sql` removes structures retired by earlier revisions: `request_adviser_history`, `request_director_history`, the Adviser and Director routing columns of `resource_requests`, and the legacy header mirror `resource_requests.resource_id` / `quantity`. `request_items` is the only store of a request's resources and quantities. The final schema has 22 tables and 57 foreign keys; `docs/R10_FINAL_SCHEMA_CANDIDATE.md` lists every table.

`scripts/migrate.php` runs a data gate before that migration. It refuses to continue if either history table has rows, if any routing column holds a non-default value, or if a request's header resource and quantity are not reproduced by one of its request lines. The SQL file repeats the essential check so that a manual import also stops.

Schema changes are explicit. `config/db.php` no longer runs any schema initialisation, backfill, or `ALTER TABLE` during a request. The guarded upgrader that used to run on every page load now lives in `app/models/legacy_schema_upgrader.php`, is reachable only from the command line, and runs only for a database with no recorded version. The `GSO_RUN_MIGRATIONS` setting is retired and ignored.

The remaining request-time synchronisation only moves a corrective work order between Scheduled and In Progress when its start date arrives. It never completes or cancels maintenance and never touches preventive occurrences or maintenance history.

The retired Adviser and Director endpoints and helpers are deleted, so those URLs answer 404. The two suites that tested the retired workflows are kept for reference in `scripts/superseded/` and are not part of the active regression.

## 📁 Project Structure

```text
/app
  /controllers
  /models
  /views
/config
/includes
/public/assets
  /css
  /images
  /js
/routes
/uploads
/scripts
/docs
```

Public URL folders such as `/admin`, `/borrower`, `/auth`, and `/pages` are retained as compatibility route wrappers.

## 🔒 Security Implementation

* Loads credentials from an ignored `.env` file and denies direct HTTP access to dotfiles and internal directories.
* Stores incident evidence outside the web root and serves it through role- and ownership-checked PHP endpoints.
* Denies direct return-evidence access and executable or non-image content under `/uploads`.
* Uses secure session settings, CSRF validation, prepared statements, role guards, and server-validated image uploads.
* Enforces configurable idle and absolute authenticated-session timeouts.
* Uses a least-privilege PHP-lint workflow and Dependabot updates for GitHub Actions.

## Public repository safety

This repository contains application source and a schema-only installation file. It must not contain operational exports or deployment data.

Before every push, verify that `.env`, local mail overrides, logs, mail previews, database backups, uploaded IDs, profile photos, incident evidence, return evidence, resource uploads, sessions, and generated reports remain untracked. Run `git status --ignored` and inspect staged changes with `git diff --cached`.

If private data or a credential enters Git history, removing it from the latest commit is insufficient. Revoke or rotate credentials, invalidate active reset/verification tokens when applicable, coordinate a history rewrite, and require collaborators to clone the sanitized history again.

See [SECURITY.md](SECURITY.md) for private vulnerability-reporting guidance.

## Incident analytics definitions

The existing Admin **Reports** page includes Incident Reporting analytics and uses the shared report date range against `incident_reports.reported_at`. Incident status, category, priority, and a case-insensitive "location contains" filter are available in the same form and are preserved in Excel and print/PDF exports.

* **Open** means Submitted, Under Review, or In Progress. **Closed** means Resolved or Rejected.
* **Resolution time** is measured from `reported_at` to `resolved_at`. Only Resolved incidents with a present, nonnegative timestamp pair are included in the average, minimum, and maximum.
* **Trend grouping** is daily for spans up to 31 days, weekly for spans up to 180 days, and monthly for longer spans.
* **Location totals** group the trimmed free-text location value. Inconsistent spelling, abbreviations, or capitalization can create separate location groups.
* Incident exports contain operational report fields only. They exclude descriptions, reporter contact details, photo paths, Admin remarks, resolution notes, and other private storage data.

### Deployment security checklist

* Use a valid TLS certificate, redirect HTTP to HTTPS, enable secure session cookies, and add HSTS only after HTTPS is verified for every production hostname.
* Keep application debug output disabled. Do not expose `.env`, source/config directories, SQL files, backups, logs, local mail previews, or repository metadata.
* Restrict filesystem ACLs for the project, `.env`, private evidence, and mail outbox. General authenticated operating-system users must not be able to modify application code or private files.
* Move retained SQL backups outside both the repository and web root. Adding a path to `.gitignore` does not remove an already tracked file or its history.
* If a sensitive dump was committed, coordinate a repository-history purge, force-push, and fresh clone for collaborators. Rotate affected SMTP/database credentials, invalidate outstanding verification/reset tokens, and review whether user password resets are required.
* Audit the tracked tree and full Git history for secrets and personal data before every release. Never assume `.gitignore` proves that prior commits are clean.

## ⚠️ Notes

* Ensure Apache and MySQL are running
* Do not upload or commit `.env`
* Use a provider-issued app password for email and rotate it after suspected exposure

## 👨‍💻 Developers

Contributor identities are intentionally omitted from this public-release document.



## 📌 Academic Context

This system is developed as part of the BSIT program.

## 📷 Screenshots

(Add screenshots here)

## 📜 License

For academic purposes only.
