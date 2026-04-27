# System Features

This document summarizes the implemented features of the GSO Borrowing and Inventory Management System based on the current PHP, MySQL, and UI codebase. The feature set is supported by the main system tables for users, resources, resource requests, return submissions, return submission photos, maintenance schedules, notifications, and activity logs.

## 1. User Management

The system provides separate user handling for administrators and borrowers. Borrowers register through a self-service process, while administrators manage approvals and access control through dedicated account management pages.

- Borrower self-registration is implemented through an online signup form.
- Registration captures full name, department, university ID, email address, username, password, and uploaded identification.
- ID upload is validated and accepts JPG, PNG, and PDF files.
- Borrower accounts are created with a pending status after registration.
- Email verification is required before the account can proceed to normal login.
- Borrower access is controlled by administrators through approval, rejection, and disable actions.
- Admin users can manage borrower accounts from a dedicated account management page.
- Admin users can view uploaded identification records submitted by borrowers.
- Admin users can create additional administrator accounts directly from the system.
- Admin accounts can be enabled or disabled by other administrators.
- The system uses role-based user grouping with two implemented roles: `Admin` and `Borrower`.
- Both roles have profile pages that display account information, approval status, and basic summaries.
- Both roles can edit their profile details, including full name, department, university ID, email, and username.
- Both roles can upload profile images, with fallback display from the uploaded ID image when no separate avatar is available.
- Both roles can change their password from their profile area.
- Logout is implemented and records a successful logout activity entry.

## 2. Authentication & Security

Authentication and access control are implemented through login validation, session-based role protection, email-driven account confirmation, and several input safeguards. The code also includes controls to reduce abuse during login, signup, and password recovery.

- Login accepts either email address or username.
- The system validates password correctness before access is granted.
- Email verification is checked during login.
- Borrower login is blocked until the account status becomes `Approved`.
- Login is blocked for accounts marked as `Disabled` or `Rejected`.
- Successful login regenerates the session ID before redirecting the user to the proper workspace.
- Session-based access checks protect admin and borrower pages.
- Role checks automatically redirect unauthorized users away from restricted modules.
- Passwords are stored using hashed values rather than plain text.
- Password changes require the current password and prevent reuse of the same password.
- Password reset uses email-based token verification.
- Reset tokens are stored in hashed form and include an expiration period.
- Email verification uses a token-based confirmation link.
- A login guard is implemented to limit repeated failed login attempts.
- After three failed login attempts, the system temporarily locks login for 30 seconds.
- After the temporary lock ends, the user receives one protected retry before being redirected to password recovery.
- Signup attempts are rate-limited within a fifteen-minute window.
- Password reset requests are also rate-limited within a fifteen-minute window.
- CSRF tokens are used in forms and notification actions to reduce unauthorized requests.
- Uploaded files are validated by type and size for IDs, profile images, resource images, and return proof photos.
- Notification read actions are protected by authentication and CSRF checking.

## 3. Resource Management

Resource management covers both items and facilities. The system allows administrators to maintain complete inventory records, manage status and condition, and organize resources for borrowing and reservation.

- Administrators can add new resources from the inventory module.
- Resource records support two implemented types: `Item` and `Facility`.
- Resource details include name, category, description, image, location, stock, capacity, status, condition, and condition notes.
- Resource image upload is supported for inventory records.
- Resource images can be replaced or removed during editing.
- Duplicate resource validation is applied to the combination of resource name and resource type.
- Item resources support total stock and available stock values.
- Facility resources support capacity values.
- Resource condition tracking is implemented through `Good`, `Damaged`, `Missing Parts`, `Needs Repair`, and `Lost`.
- Resource status tracking is implemented through `Available`, `Unavailable`, and `Maintenance`.
- Resources can be edited after creation.
- Resources can be archived instead of permanently removed.
- Archived resources can be restored.
- Permanent deletion is only allowed when no borrowing request references exist.
- The inventory page supports searching, filtering, and pagination.
- Resources can be filtered by type, category, status, and archive state.
- A detailed resource view page is implemented for full record inspection.
- Facility resources have a calendar-based availability view that shows active reservations and maintenance blocks.
- Resource operational status is automatically refreshed based on stock, maintenance state, and condition state.

## 4. Borrowing & Reservation System

The borrowing module manages the full request lifecycle for both item borrowing and facility reservation. It combines borrower submission, schedule validation, administrative decision-making, and controlled release of approved requests.

- Borrowers can browse all active resources from a dedicated resource page.
- Resource browsing supports search, filtering, and sorting.
- Borrowers can open a request form directly from an available resource.
- Item requests allow quantity entry.
- Facility requests use schedule-based reservation with fixed quantity handling.
- Contact number is required when submitting a request.
- Date and time validation is enforced during request submission.
- The system blocks past dates and invalid time ranges.
- Facility reservations require both start time and end time.
- Item requests support optional time windows for schedule-aware stock checking.
- Duplicate request checking is implemented for the same borrower, resource, and overlapping schedule.
- Availability validation checks archived resources, unavailable resources, maintenance state, and unusable condition state.
- Stock validation checks overlapping item requests within the same borrowing window.
- Schedule conflict checking prevents overlapping facility reservations.
- Maintenance conflict checking blocks requests during overlapping maintenance periods.
- Request submission stores the transaction as a pending request.
- Administrators can review requests from a dedicated request management page.
- The request management page supports status filtering, searching, and pagination.
- A detailed request view page is implemented for borrower, resource, schedule, and review information.
- Borrower contact numbers can be used directly from request and active borrowing pages.
- First-come, first-served processing is enforced for competing requests on the same resource and schedule.
- Administrators can approve pending requests.
- Administrators can reject pending requests.
- Borrowers receive notifications when requests are approved or rejected.
- Approved requests can be released by administrators.
- Release is separated from approval, allowing formal confirmation before the resource is issued.
- When an item is released, the system deducts the released quantity from available stock.
- When a request is released, the system generates the due date automatically.
- Item due dates are generated from the release time using the configured loan duration.
- Facility due dates are aligned with the approved reservation end time.
- Borrowers can track requests through status tabs and filtered request tables.
- Borrowers can cancel requests that are still pending.
- Active borrowings are tracked separately from request history.
- Overdue status is derived from released requests whose due date has already passed.

## 5. Return Management

The return management module supports borrower-submitted return proof and administrator-controlled final confirmation. It also connects return approval to inventory and condition updates.

- Borrowers can submit returns only for active released requests.
- Return submission includes condition notes from the borrower.
- Borrowers must select a reported return condition.
- Borrowers can upload photo evidence for the return.
- Up to three proof photos are supported per submission.
- Photo validation is implemented for type and file size.
- Return submissions can be updated and resubmitted if a previous submission was rejected.
- When a return is resubmitted, earlier proof photos are removed and replaced.
- Administrators receive notifications when return proof is submitted.
- Administrators can review submitted return details, borrower notes, due date, and proof photos.
- Administrators can approve return submissions.
- Administrators can reject return submissions and request resubmission.
- Return rejection stores administrator remarks for borrower guidance.
- Return approval changes the borrowing record to `Returned`.
- Return approval records the return date in the main request record.
- Final inspection condition is stored during return review.
- Inventory updates are triggered after return approval.
- Good-condition item returns restore available stock.
- Lost items reduce stock totals during return processing.
- Resource condition notes and condition status can be updated from the inspection result.
- Borrowers receive notifications when returns are approved or rejected.
- Borrowers can review final return outcomes through history and request records.

## 6. Notification System

The system includes a stored in-application notification module for both administrators and borrowers. Notifications are displayed through the topbar interface and linked to related system pages.

- Notifications are stored in a database table.
- Separate notifications are generated for different events in the borrowing lifecycle.
- Administrators receive notifications for new resource requests.
- Administrators receive notifications for submitted return proof.
- Administrators receive low-stock alerts when item availability becomes critically low after release.
- Borrowers receive notifications when requests are approved.
- Borrowers receive notifications when requests are rejected.
- Borrowers receive notifications when approved requests are officially released.
- Borrowers receive notifications when return submissions are approved.
- Borrowers receive notifications when return submissions are rejected.
- Borrowers receive overdue notifications for late active borrowings.
- Administrators can send overdue reminder notifications to borrowers.
- Reminder sending is limited so the same overdue borrowing cannot be reminded again within twenty-four hours.
- Notifications support read and unread states.
- Individual notifications can be marked as read.
- All notifications can be marked as read at once.
- Notification badges show unread counts in the topbar.
- Notification items include title, message, timestamp, icon styling, and destination link.
- Notification links direct users to the related request, borrowing, return, history, or report page.
- The implementation is based on stored notifications rather than a separate real-time push service.

## 7. Activity Logging

Activity logging is implemented as an audit trail for major system actions. The logs support monitoring of user activity, administrative actions, and report-related operations.

- Activity logs store the acting user, action label, details, and timestamp.
- Successful login events are logged.
- Successful logout events are logged.
- Borrower account approval, rejection, and disable actions are logged.
- Admin account creation, enabling, and disabling are logged.
- Resource creation, update, archive, restore, and deletion actions are logged.
- Borrower request submission and cancellation are logged.
- Request approval, rejection, and release actions are logged.
- Return submission, approval, and rejection actions are logged.
- Maintenance scheduling, completion, and cancellation actions are logged.
- Overdue reminder actions are logged.
- Password reset request and reset completion events are logged.
- Report export actions are logged.
- The activity log page supports searching by action, details, actor, role, and log ID.
- The activity log page supports filtering by action type.
- The activity log page includes pagination and summary counts for recent activity.

## 8. Inventory & Maintenance

Inventory control and maintenance management are closely connected in the system. The design ensures that maintenance schedules affect resource availability and that borrowing actions update stock records automatically.

- Inventory tracking stores both total stock and available stock for item resources.
- Available stock is reduced when approved requests are released.
- Available stock is restored or adjusted after approved returns, depending on inspection results.
- Maintenance schedules can be created for resources from the maintenance module.
- Maintenance schedules include start date, duration, end date, reason, remarks, and status.
- Maintenance validation prevents scheduling for archived resources.
- Maintenance validation prevents past-date schedules.
- Maintenance validation prevents overlapping maintenance records for the same resource.
- Maintenance validation also prevents maintenance from being scheduled over active borrowing or reservation periods.
- Maintenance states include `Scheduled`, `In Progress`, `Completed`, and `Cancelled`.
- Maintenance schedules can be marked as completed.
- Maintenance schedules can be cancelled.
- Schedule synchronization updates maintenance status automatically according to current dates.
- Resources under active maintenance are automatically marked unavailable for borrowing.
- Maintenance schedules appear on facility availability calendars.
- Condition-based blocking prevents damaged, incomplete, repair-needed, or lost resources from being selected for new requests.
- The system includes low-stock detection and low-stock admin alerts.

## 9. Reporting System

The reporting module provides administrative summaries and exportable records for operational monitoring. Reports are based on actual borrowing, return, maintenance, and inventory data.

- Reports can be filtered by date range.
- Reports can be filtered by request status.
- Reports can be filtered by resource type.
- The reporting page displays summary totals for key operational categories.
- Borrowing transaction reports are generated from the request records.
- Returned item reports are included through filtered transaction results.
- Overdue item reports are included through derived borrowing status.
- Facility reservation records are included in reports.
- Maintenance records are included in reports.
- Inventory status reports are included in reports.
- Damaged or lost item reports are included using inspection condition data.
- Reports are displayed in table format for on-screen review.
- Excel export is implemented for the report data.
- Print-friendly PDF output is implemented through a report print layout.
- Report export actions are recorded in activity logs.

## 10. User Interface Features

The system includes a complete web interface for public access, borrower tasks, and administrative work. The interface uses role-based navigation, summary cards, filtered tables, and form-driven workflows.

- A public landing page presents the system overview, core features, house rules, and access links.
- Login and signup pages are implemented for public access.
- Admin users have a dedicated dashboard with request, inventory, overdue, maintenance, trend, and recent activity summaries.
- Borrower users are directed to the resource browsing page as their main workspace.
- Admin workspace pages include dashboard, request management, active borrowings, inventory, maintenance, borrower accounts, admin accounts, reports, activity logs, and profile management.
- Borrower workspace pages include browse resources, request tracking, active borrowings, return submission, history, and profile management.
- Role-based sidebars are implemented for admin and borrower navigation.
- Topbars display a live date and time area.
- Topbars include quick action menus for common tasks.
- Topbars include a notification dropdown with unread counts.
- Topbars include a user menu with profile and logout access.
- A global back button is implemented across protected pages.
- Inventory records are displayed through card-based and table-based layouts.
- Request, borrowing, history, accounts, reports, and logs are displayed through searchable and paginated tables.
- Row action menus are used for approval, rejection, release, return review, archive, restore, and other record actions.
- Status badges are used throughout the interface for request state, resource status, condition state, archive state, and loan state.
- Summary cards and metric strips are used on admin, inventory, maintenance, profile, and borrowing pages.
- Facility availability calendars are available in borrower request pages, admin request pages, and facility detail pages.
- Request forms include client-side schedule validation before submission.
- Server-side validation is also applied to all major transactional forms.
- Dedicated detail pages are implemented for viewing full request records and full resource records.
- Profile pages provide user information, quick links, summary panels, avatar upload, and security options.
