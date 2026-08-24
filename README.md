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
* Import `database/schema.sql`. It contains schema only and no users, password hashes, requests, or evidence.
* Existing pre-Incident installations can apply `database/migrations/20260810_001_create_incident_reporting.sql`; new installations using the baseline schema already include that table.
* Never deploy database backups from `docs/`, an export folder, or another web-accessible location.
* Run schema migrations with a separate deployment account. Create a dedicated `gso_app` MySQL account for the application, limit it to the required runtime operations on `gso_database`, and set `GSO_RUN_MIGRATIONS=0` after migration. Do not run the web application as MySQL `root`.

### 5. Configure Environment Variables

* Copy `.env.example` to `.env`; never commit or serve the resulting file.
* Replace every placeholder value and use an HTTPS application URL in production.
* Keep `GSO_INCIDENT_PHOTO_DIR` and `GSO_MAIL_OUTBOX_DIR` as absolute paths outside `htdocs`.

```dotenv
GSO_APP_ENV=production
GSO_APP_DEBUG=0
GSO_APP_URL=https://your-gso-host.example/GSO_WebSystem
GSO_RUN_MIGRATIONS=0
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
