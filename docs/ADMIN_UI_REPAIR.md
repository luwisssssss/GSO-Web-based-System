# Admin rendering and component audit — 2026-10-13 (Asia/Manila)

Baseline: `37eb41fd73f6176460d863e0ae90d758e16b00ca`. Schema: `20261010_008`.
This repair changes presentation only. No model, permission, SQL, migration,
R11/R12 document, stock calculation, approval, return, email, or scheduling rule
was changed. No load testing or push was performed.

## Root cause and repair

`admin/create_walkin.php` directly requires its controller; the route table records
the same mapping. The controller correctly renders the walk-in form. Its last
include was `includes/footer.php`, which required `app/views/layouts/footer.php`:
the public marketing footer, including its large logo, borrowing-system heading,
quick links and contact details. It was appended after `.main-content` inside the
still-open application wrapper. The landing controller and hero were never
executed by this authenticated request. Authenticated HTTP reproduction returned
200 without a redirect and contained both the form and that footer.

The shared `includes/footer.php` now ends the authenticated shell and preserves
the authorized chat widget using `require_once`. The public landing controller
directly requires its existing public footer template with an anchored path.
Public footer sections are preserved. This fixes every authenticated caller;
there is no CSS hiding or page-specific footer suppression.

The wrong include already existed in walk-in commit `304a696`; the public footer
dates to `7079210`. The deployment-hardening commit changed the walk-in POST
exception formatter, not this include. Its error/configuration handlers terminate
with safe errors rather than falling back to the landing page.

## Navigation and runtime inventory

Every `/admin/X.php` entry below directly requires
`app/controllers/admin/X.php`; `gso_staff/dashboard.php` similarly requires
`app/controllers/gso_staff/dashboard.php`. `routes/web.php` is a route inventory,
not the dispatch mechanism for these physical PHP endpoints.

The common shell is `includes/header.php` → `app/views/layouts/header.php`,
`includes/admin_sidebar.php` → `app/views/partials/admin_sidebar.php`, and
`includes/admin_topbar.php` → `app/views/partials/admin_topbar.php`.
Controllers render their page content inline. The common styles are
`pages/admin.css`, `base.css`, `layout.css`, `components.css`, and
`pages/chatbot.css`; the table records additional page styles. All use
`ui-redesign.js` plus the existing inline Back, topbar/notification and authorized
chat scripts. Page-specific inline scripts remain unchanged, including walk-in
row cloning, request/release interactions and dashboard charts.

| Sidebar item / related page | Entry PHP | Rendered content | Extra CSS | Public footer before repair |
|---|---|---|---|---|
| Dashboard (Head) | `admin/dashboard.php` | GSO dashboard, metrics/charts/activity | `dashboard.css` | No |
| Requests | `admin/requests.php` | Queue, review/approval/release actions and Claim Slip verification | `admin-requests.css` | No |
| Create Walk-in | `admin/create_walkin.php` | External client identity, resource lines and schedule/document form | None | Yes |
| Reschedule Review | `admin/reschedules.php` | Proposals, original/proposed schedules and decisions | None | Yes |
| Release / Return | `admin/on_loan.php` | Active borrowings, overdue information and return links | `admin-on-loan.css` | No |
| Incident Reports | `admin/incidents.php` | Incident filters, list and review links | `borrower-incidents.css`, `admin-incidents.css` | No |
| Inventory | `admin/inventory.php` | Resource catalogue and management actions | `admin-inventory.css` | No |
| Equipment Units | `admin/equipment_units.php` | Tracking catalogue and per-resource registration/update forms | None | Yes |
| Work Orders / Maintenance | `admin/maintenance.php` | Corrective scheduling, assignments, work-order actions | None | No |
| AI Knowledge Base | `admin/ai_knowledge.php` | Add/edit entry, filters, entries and status/delete actions | None | No; raw controls instead |
| Reports | `admin/reports.php` | Report filters/results/exports | `admin-reports.css` | No |
| Activity Logs | `admin/activity_logs.php` | Audit filters and activity records | `admin-activity-logs.css` | No |
| Notifications | `admin/notifications.php` | Own notification list/actions | `notifications.css` | No |
| Dashboard (Staff) | `gso_staff/dashboard.php` | Staff operational shortcuts | `dashboard.css` | Yes |
| Dashboard (MIS) | `admin/accounts_dashboard.php` | Account-management shortcuts | None | Yes |
| Borrower Accounts (MIS) | `admin/borrowers_accounts.php` | Borrower account administration | `admin-borrowers-accounts.css` | No |
| System Accounts (MIS) | `admin/admin_accounts.php` | System account administration | `admin-admin-accounts.css` | No |
| Preventive Plans (Work Orders subnavigation) | `admin/preventive_plans.php` | Plans, occurrences and assigned-personnel actions | None | Yes |
| Maintenance History (Work Orders subnavigation) | `admin/maintenance_history.php` | Completed/cancelled work, provenance and people | None | Yes |
| Manage Walk-in (request detail action) | `admin/walkin_actions.php` | Client cancellation, letters and reschedule actions | None | Yes |

Staff navigation displays Dashboard, Requests, Create Walk-in, Reschedule Review,
Release / Return, Inventory, Equipment Units and Work Orders. Head navigation
displays the first thirteen rows. MIS displays its Dashboard, Borrower Accounts,
System Accounts and Notifications. The sidebar's Sign Out definition is visible
to Director; it invokes `auth/logout.php` → its auth controller → an intentional
redirect to the public landing page. Head/Staff use the topbar's existing Sign Out
link. No navigation capability was changed.

Other shared-footer callers, including Director Dashboard, borrower document/
reschedule/return-history views, inherit the same separation of public and
authenticated layout endings. Request Detail, Return Review and Walk-in Return
keep their existing controllers/guards/components; their workflows are exercised
by the full functional regression suites.

Exact rendered stylesheet/script URLs and sidebar links are recorded in the
private `sidebar_inventory.json` and `sidebar_{head,staff,mis,director,borrower}.json`.

## Component repairs

- Knowledge Base uses existing `settings-form-grid`, `form-row`, full-width rows,
  `form-actions-bar`, `table-toolbar`, `request-table`, `admin-btn`, status badges
  and the established JavaScript row-action dropdown. All field names, values,
  validation attributes, CSRF fields, action values and POST/GET handlers remain.
- Walk-in uses the same two-column form grid, a responsive resource row, existing
  button styles, checkbox row, help text and file-selector styling. Resource
  addition/removal and all scheduling/upload fields remain.
- Reschedule Review separates the old snapshot and proposal, uses existing status
  badges/action buttons and a compact filter. A nested toolbar flex rule that
  stretched its select vertically was corrected using a shared nested-control rule.
- Equipment registration has labeled form rows and existing controls/buttons;
  unit-update forms use the existing request form styling.
- Maintenance, Preventive Plans and History group their heading/description beside
  the existing subnavigation. Populated personnel/notes controls reuse
  `form-control-block`; history links reuse `admin-btn`.
- Back is an intentional authenticated header component, not public landing
  content. Its markup is extracted to `back_button.php`; admin/Staff topbars place
  it above page content. Existing history/fallback behavior and per-page opt-outs
  are preserved. It no longer floats over admin form fields.
- Chat permission checks, fetching, conversation behavior and panel are unchanged.
  Admin/Staff reuse a compact 44px launcher in the outside content gutter, with
  an accessible Ask GSO label/title. Bottom clearance and small-screen right
  clearance keep it away from controls. Role body-class values are normalized so
  a role with spaces is a single usable CSS class.

All extensions live in the existing `components.css`. They use existing colors,
radius tokens, forms, tables and buttons; no competing component system or
page-local inline stylesheet was added.

Populated maintenance menus also exposed clipping at the bottom of scrollable
tables. The shared `ui-redesign.js` measures only the clearance needed by an open
row menu and sets the existing stylesheet's state through a CSS custom property.
The clearance is removed on close/Escape and remeasured on resize. Horizontal
table scrolling is retained; controls and action handlers are not replaced.
The shared menu text style also overrides the full-size primary button's white
text, which otherwise disappeared against a generated menu item's white surface.
Action-label contrast is checked in the populated render smoke test.

## Verification and evidence

Evidence is outside the web root in `%TEMP%/GSO_UI_REPAIR`. Private credentials,
database snapshots, mail captures and screenshot data are not committed.

The Playwright smoke test uses the existing installed Chrome in headless mode.
It tests all fifteen Head-accessible sidebar/subnavigation pages at 1440×900,
1366×768 and 1920×1080. It also tests core operational forms at widths 768 and
390, and Staff walk-in rendering. Checks cover HTTP, PHP error markers, local
assets, uncaught JavaScript errors, shell/card bounds, document overflow, public
footer absence, control styling, nested filter sizing, Back placement and chat
overlap at top/middle/bottom scroll positions. Overlap measurements intersect
control rectangles with ancestor scrollports, so invisible table overflow is
not mistaken for painted content.

Interaction checks include walk-in row addition/removal, opening the chat panel,
Knowledge Base dropdown/edit values, equipment registration layout, populated
work-order/plan action controls and retention of the public landing footer.
Five roles are signed in normally with isolated fixture accounts. Eighteen admin
routes per role are checked against existing capabilities. Head/Staff retain
authorized operations; Director and Borrower are redirected away from admin
operations; MIS retains only account administration/own notifications. Chat
appears only for authorized roles. No permissions are edited by the test.

Screenshots cover all seven requested key pages at all three desktop widths,
walk-in bottoms, knowledge actions/edit, equipment details, Staff walk-in,
responsive forms and populated maintenance menus. Recorded pre-repair control measurements prove
the previously raw populated maintenance controls. Final page/action screenshots
were visually reviewed; wide tables keep their established local scrolling.

The final run passed **22 active suites / 3,035 actual checks**, **137 deployment-
hardening checks**, and **961 browser/role/render checks**, with zero failures.
There are 40 screenshot artifacts, including intermediate populated-menu evidence.
An explicit console-event audit of 28 authorized Head/Staff/MIS pages also
recorded zero console errors (`console_results.json`).

The final results/counters are in `render_results.json`, `regression_summary.json`,
the individual 22 active suite logs and `deployment_hardening_e2e.log`.
The complete functional pass uses an isolated `gso_r14_test_*` database, loopback
Apache and a fresh private test-mail outbox; no assertions were weakened.
Expected injected duplicate-unit, AI and mail-failure diagnostics are retained.
No unexpected PHP warning/fatal or repaired-page asset/JavaScript failure remains.

Read-only pre/post hashes match for every live table's rows and SHOW CREATE.
Schema counts are unchanged: **22 tables, 57 FKs, 104 indexes, 18 UNIQUE,
11 CHECK, 3 generated columns**, version **20261010_008**.
PHP lint covers 278 files, with final changed-file lint repeated. Python syntax
and `git diff --check` pass. The focused commit and final counters are reported
with the delivery message; nothing is pushed.

## Repeating the browser smoke test

Install Playwright into a private virtual environment or temporary package
directory, then run `python scripts/admin_ui_render_smoke.py` with:

- `GSO_UI_BASE_URL`: isolated loopback application URL.
- `GSO_UI_EVIDENCE_DIR`: directory outside the repository/web root.
- `GSO_UI_CREDENTIALS`: private fixture JSON containing `database` matching
  `gso_rN_test_*`, and `roles` with `head`, `staff`, `director`, `mis`, `borrower`;
  each has normal test `username`, `password`, `role` and existing `permissions`.
- `GSO_UI_CHROME`: optional installed Chrome executable path.

Populate one pending reschedule and an active preventive occurrence in that
isolated database to exercise the populated states. Never use production users
or mail transport for fixtures. The browser test submits only logins; operational
actions are opened/inspected rather than submitted.

Remaining limits: this is representative desktop/responsive browser evidence,
not screenshots of every historical record or native upload/print dialog. Wide
operational tables intentionally scroll within their existing table wrapper.
Concurrent-user/load testing has not begun.
