# Web-Based General Services Office (GSO) Borrowing and Inventory Management System

## 1. System Users and Roles

### Admin

- Description: The Admin is the authorized office personnel responsible for supervising the overall operation of the system and ensuring that borrowing, return, maintenance, and inventory activities are properly managed.
- Responsibilities and permissions:
- Manage borrower accounts by reviewing, approving, rejecting, or disabling access requests.
- Create and manage administrator accounts for authorized office personnel.
- Add, edit, archive, restore, and monitor inventory records for items and facilities.
- Review borrowing requests and decide whether to approve or reject them.
- Release approved requests and assign the official due date of borrowed resources.
- Monitor active borrowings, overdue records, and submitted return proofs.
- Review return submissions, inspect uploaded evidence, and confirm the final condition of returned resources.
- Update stock quantity, condition status, and availability of resources after release and return.
- Schedule and monitor maintenance activities that affect resource availability.
- View notifications, receive low-stock alerts, and send overdue reminders to borrowers.
- Generate reports for borrowing transactions, overdue items, maintenance records, inventory status, and damaged or lost resources.
- Review activity logs for accountability, monitoring, and administrative audit purposes.

### Borrower (Student/Guest)

- Description: The Borrower is an approved end user, such as a student or authorized guest, who uses the system to request, borrow, and return institutional resources.
- Responsibilities and permissions:
- Register an account by providing personal details, uploading an identification document, and verifying an email address.
- Log in only after email verification and administrative approval.
- Browse available items and facilities and review their details before making a request.
- Submit borrowing or reservation requests with quantity, schedule, contact number, and notes.
- Monitor the status of submitted requests.
- Cancel pending requests that are no longer needed.
- View active borrowings, due dates, and overdue notices.
- Submit return records together with notes, condition declaration, and supporting photos.
- Resubmit return evidence when a previous return submission is rejected.
- View personal notifications, request history, and borrowing records.
- Maintain and update personal profile information and password.

## 2. Functional Requirements

The system shall allow users to register borrower accounts through an online form that captures personal information, username, password, email address, and uploaded identification. After registration, the system shall send an email verification link so that only verified users may continue to the login stage. Borrower access shall remain inactive until an administrator approves the account. The system shall also support secure login, logout, and password recovery through email-based reset procedures. Once authenticated, borrowers shall be able to browse available resources, including items and facilities, search and filter records, review stock, location, category, condition, and availability status, and then submit borrowing or reservation requests. During request submission, the system shall validate the selected date, time, quantity, duplicate requests, maintenance conflicts, and schedule overlap in order to reduce errors and double-booking.

For request processing, the system shall provide administrators with a request management module where submitted requests can be reviewed, approved, rejected, and officially released. The system shall apply a first-come, first-served process for competing requests and shall generate the borrowing due date upon release. After release, the system shall track active borrowings, monitor due dates, and identify overdue records. Borrowers shall be able to submit returns together with condition notes and photo evidence, while administrators shall review the submitted proof and either approve or reject the return. Once a return is approved, the system shall update stock levels, condition status, and the final request record. The system shall provide notifications for important events such as new requests, approvals, rejections, releases, return decisions, overdue reminders, and stock alerts. In addition, the system shall maintain activity logs for major user actions, support inventory management for items and facilities, allow maintenance scheduling that temporarily blocks affected resources, and generate administrative reports for borrowing transactions, maintenance records, overdue items, damaged resources, and inventory status.

## 3. Non-Functional Requirements

The system shall be designed with usability as a primary consideration so that administrators and borrowers can complete their tasks through a simple and understandable web interface. Menus, forms, search tools, status labels, and summary panels shall help users move through the process with minimal confusion. In terms of performance, the system shall provide fast page loading, prompt form submission, and timely retrieval of request, inventory, and report records so that office work is not delayed. With respect to security, the system shall require authenticated access, apply role-based restrictions, protect user credentials, support email verification and password reset, and limit access to authorized records only. The system shall also manage uploaded files carefully in order to support safe return evidence and resource image handling.

The system shall be reliable enough to support daily office operations without frequent interruption. It shall preserve transaction records accurately and maintain stable behavior when users register accounts, submit requests, review returns, or generate reports. The system shall also be scalable so that future features, additional users, and expanded resource categories can be accommodated without redesigning the entire platform. In terms of maintainability, the system shall be organized into clear modules for authentication, requests, returns, maintenance, inventory, reporting, and notifications so that future revisions can be implemented more easily. Finally, the system shall be accessible because it is web-based and can be reached through common internet browsers on desktops, laptops, and other internet-capable devices.

## 4. Project Description

### Purpose of the System

The Web-Based General Services Office Borrowing and Inventory Management System was developed to provide a centralized and organized platform for managing the borrowing and monitoring of institutional resources. Its main purpose is to help the General Services Office handle account approval, resource requests, inventory records, release procedures, return verification, maintenance scheduling, and reporting within a single system. Through this platform, the office can perform its responsibilities more efficiently while giving borrowers a clearer and more convenient way to request resources.

### Problems Addressed by the System

Before the development of a centralized web-based system, borrowing activities are commonly handled through paper forms, verbal coordination, scattered spreadsheets, or isolated records. These manual practices create several operational problems. First, request handling becomes slow because staff need to review records one by one and manually confirm availability. Second, the risk of scheduling conflict increases because the same item or facility may be promised to more than one borrower. Third, monitoring of due dates and return conditions becomes difficult, especially when the office needs to check who borrowed a resource, when it was released, whether it has already been returned, and what its physical condition is after use. Fourth, the preparation of reports takes more time because data must be gathered from different sources. Finally, accountability is reduced when notifications, activity records, and maintenance schedules are not properly documented.

The proposed system addresses these problems by converting the borrowing process into a structured digital workflow. Registration, approval, request review, release, return submission, and reporting are connected in one platform. As a result, records become easier to monitor, resource availability becomes clearer, and office actions can be traced more accurately.

### Scope of the System

The scope of the system covers two major user groups: administrators and borrowers. For borrower users, the system includes account registration, email verification, login, browsing of available resources, submission of item or facility requests, tracking of request status, monitoring of active borrowings, submission of return evidence, and review of personal borrowing history. For administrators, the system includes borrower account approval, creation and management of admin accounts, review of resource requests, approval or rejection of requests, release of approved requests, monitoring of active and overdue borrowings, review of return submissions, maintenance scheduling, inventory management, notification handling, and report generation.

The system focuses on the operational processes of the General Services Office related to borrowing and inventory control. It covers institutional items and facilities, including their stock quantity, availability, condition, location, and maintenance status. However, the system does not cover financial billing, procurement, payroll, or other office functions outside borrowing, return, and inventory supervision.

### Benefits to Users and the Institution

The system offers significant benefits to both users and the institution. For borrowers, it provides a faster and more transparent process because requests can be submitted online, statuses can be checked easily, and return evidence can be sent without repeated physical follow-up. For administrators, the system reduces repetitive manual work, improves request processing, simplifies monitoring of overdue items, and helps maintain accurate resource records. For the institution, the system supports better accountability, improved resource utilization, stronger documentation, and more reliable reporting for planning and administrative review.

Because all important transactions are recorded, the institution gains a stronger basis for decision-making. The office can identify which resources are frequently borrowed, which items are overdue, which records require maintenance action, and which returned resources need repair or replacement. This improves not only daily operations but also long-term planning and service quality.

### Key Features and Innovations

One of the key strengths of the system is the integration of borrowing management and inventory management in one web-based platform. Instead of treating requests and stock records as separate processes, the system connects them directly. When a request is released, the system updates the borrowing record and the available stock. When a return is approved, the system updates the final condition and restores or adjusts the stock based on the inspection result.

Another important feature is schedule-aware validation. The system checks for overlapping reservations, duplicate requests, maintenance conflicts, and stock limitations before a request is accepted. This reduces scheduling problems and improves fairness in processing. The inclusion of a return submission process with photo evidence also strengthens accountability because borrowers are required to provide proof and condition notes, while administrators confirm the final inspection result before closing the transaction.

The system further improves administration through notifications, overdue reminders, low-stock alerts, maintenance scheduling, activity logs, and exportable reports. These features provide a more responsive and traceable office process. In this way, the project introduces a practical innovation not by adding unnecessary complexity, but by organizing existing office activities into a more accurate, timely, and manageable digital environment.

## 5. Hardware and Software Requirements

### A. Hardware Requirements

#### For Admin

- Minimum processor: Intel Core i3, AMD equivalent, or any dual-core processor with at least 2.0 GHz speed
- Minimum RAM: 4 GB
- Minimum storage: At least 20 GB of free disk space for the local server package, database files, uploaded images, and return evidence
- Internet requirement: Stable broadband or local network connection, preferably at least 5 Mbps for smooth web access and report generation

#### For Borrower

- Minimum processor: Dual-core processor at 1.8 GHz or equivalent mobile processor
- Minimum RAM: 2 GB
- Minimum storage: At least 1 GB of free space for browser use and temporary file uploads
- Internet requirement: Stable internet connection, preferably at least 2 Mbps for browsing resources, submitting requests, and uploading return evidence

### B. Software Requirements

- Operating System: Windows 10 or later is suitable for local deployment through XAMPP; other modern desktop operating systems may also be used for browser access
- Web Browser: Google Chrome, Microsoft Edge, Mozilla Firefox, Safari, or any updated standards-compliant browser
- Server: XAMPP package with Apache web server
- Database: MySQL or MariaDB managed through phpMyAdmin
- Programming Languages: PHP, HTML, CSS, and JavaScript
- Additional support software: Email sending capability for account verification and password reset functions

## 6. Executive Summary

The Web-Based General Services Office Borrowing and Inventory Management System is a centralized information system developed to improve the way institutional resources are requested, released, returned, tracked, and reported. The system combines account management, inventory control, request processing, return verification, maintenance scheduling, notifications, and reporting in one web-based environment. It is intended to support the daily operations of the General Services Office and to provide a more organized service experience for borrowers.

The system offers several important features, including online registration with email verification, administrator-controlled account approval, browsing of items and facilities, conflict-aware request submission, approval and release workflow, return submission with supporting photos, maintenance-based availability control, overdue monitoring, notification support, activity logging, and report export. These features are designed to reduce manual work, improve record accuracy, and strengthen accountability in the handling of institutional assets.

The main purpose of the system is to replace slow and fragmented manual procedures with a structured digital process. Its expected impact includes faster request handling, improved transparency, better inventory monitoring, stronger documentation of office transactions, and more reliable information for administrative planning and decision-making. Overall, the system is expected to enhance operational efficiency while promoting responsible and traceable use of university resources.

## 7. Conclusion

The Web-Based General Services Office Borrowing and Inventory Management System demonstrates that borrowing and inventory operations can be managed more effectively through an organized web-based platform. The system supports the complete transaction cycle from user registration and account approval to resource request, release, return verification, maintenance scheduling, and report generation. It also strengthens monitoring through notifications, activity logs, overdue tracking, and inventory condition updates.

Its importance lies in its ability to transform a manual and time-consuming process into a more accurate, transparent, and efficient workflow. By automating key office tasks and preserving complete records of resource movement, the system helps the General Services Office deliver better service while maintaining accountability and control over institutional assets. In this way, the system serves as both an operational tool and a practical step toward improved administrative automation.

## 8. Recommendations

For future enhancement, the following improvements are recommended:

- Integrate a chatbot support feature to answer common borrower questions, provide request guidance, and reduce repetitive inquiries to office staff.
- Develop a mobile application version so that borrowers and administrators can access major functions more conveniently through smartphones.
- Add advanced analytics and reporting tools that can present trends, high-demand resources, seasonal borrowing patterns, and resource utilization summaries.
- Implement real-time notifications through email, SMS, or push alerts so that approvals, reminders, and return decisions can reach users more quickly.
- Adopt cloud-based deployment to improve accessibility, backup reliability, and scalability for wider institutional use.

## 9. Appendices

### Appendix A. Sample Screenshot Descriptions

- Login Page: This screenshot should show the email or username field, password field, login button, password recovery link, and security message area for failed login attempts or account restrictions.
- Dashboard Page: This screenshot should show the administrator dashboard or summary area that presents request counts, inventory status, urgent items such as overdue borrowings or return proofs, and recent system activity.
- Request Page: This screenshot should show the borrower request form with resource details, quantity, contact number, date needed, time selection, notes field, and the submit request button. For facilities, the screenshot may also include the availability calendar.

### Appendix B. Database Structure Summary

The database is organized to support the complete borrowing process. The users table stores borrower and administrator records, including account status and login details. The resources table stores the master list of items and facilities together with stock, capacity, status, condition, location, and image information. The resource_requests table records every borrowing or reservation transaction from submission to release and return. The return_submissions table stores return details submitted by borrowers, while the return_submission_photos table stores the related evidence images. The maintenance_schedules table records periods when a resource is under maintenance. The notifications table stores system alerts for both admins and borrowers, and the activity_logs table preserves major actions performed in the system for monitoring and audit purposes.

### Appendix C. List of System Modules

- Landing page and public access page
- User registration and email verification module
- Login, logout, and password recovery module
- Borrower account approval module
- Admin account management module
- Resource browsing and search module
- Borrowing and facility request submission module
- Request management, approval, rejection, and release module
- Active borrowing and overdue monitoring module
- Return submission and return review module
- Inventory management module
- Maintenance scheduling module
- Notifications module
- Activity logs module
- Reports generation and export module
- Profile and password management module

### Appendix D. User Interface Components

- Login and registration forms
- Navigation sidebar for admin and borrower users
- Top bar with notification panel
- Search boxes and filter controls
- Resource cards and inventory record cards
- Request, return, and maintenance forms
- Status badges for request, condition, and availability states
- Tables for requests, borrowings, reports, accounts, and logs
- Summary cards and dashboard metrics
- Facility availability calendar
- Action menus for approval, release, return review, and inventory management

---

## 10. Enhanced System Overview

### 10.1 System Purpose and Mission

The Web-Based General Services Office (GSO) Borrowing and Inventory Management System serves as a comprehensive digital platform designed to modernize and streamline the traditional paper-based processes of resource borrowing and inventory control within institutional settings. The system addresses the critical need for centralized management of institutional assets, providing a structured workflow that connects borrowers, administrators, and resources in a unified environment.

The primary mission of the system is to transform manual, fragmented borrowing procedures into an automated, transparent, and accountable digital workflow. By integrating account management, resource tracking, request processing, return verification, maintenance scheduling, and reporting capabilities into a single web-based platform, the system eliminates the inefficiencies associated with traditional methods while ensuring data integrity and operational reliability.

### 10.2 Target Users and User Characteristics

The system serves two distinct user categories, each with specific roles, responsibilities, and access requirements:

**Administrative Users (Admin)**
Administrative users are authorized personnel from the General Services Office who oversee the entire borrowing and inventory operation. These users possess elevated privileges that enable them to manage borrower accounts, process resource requests, monitor active transactions, schedule maintenance activities, and generate analytical reports. Administrative users are responsible for maintaining the integrity of the resource inventory, ensuring fair and timely processing of requests, and upholding accountability through comprehensive activity logging.

**Borrower Users (Students/Guests)**
Borrower users represent the end consumers of the system services, primarily including registered students and authorized guests of the institution. These users interact with the system to discover available resources, submit borrowing requests, track request statuses, manage active borrowings, and complete return procedures. Borrower access is subject to email verification and administrative approval, ensuring that only authorized individuals can utilize institutional resources.

### 10.3 Core System Capabilities

The system provides a comprehensive set of capabilities that support the complete resource borrowing lifecycle:

**Account Management**: The system implements a robust account management framework that supports self-service borrower registration, email-based verification, and administrative approval workflows. Both borrower and administrator accounts maintain detailed profile information, role-based access controls, and activity tracking.

**Resource Management**: Administrators can maintain a comprehensive inventory of items and facilities, including stock quantities, availability status, condition ratings, location information, and associated images. Resources can be added, edited, archived, and restored as needed to reflect the current state of institutional assets.

**Request Processing**: The system facilitates the complete request lifecycle from initial submission through final disposition. This includes validation of request parameters, conflict detection for scheduling overlaps, administrative review and approval, and official release with due date assignment.

**Return Processing**: Borrowers submit return evidence including condition declarations and supporting photographs. Administrators review submitted returns, inspect the evidence, and approve or reject the return based on their assessment. Approved returns restore resource stock and update condition status.

**Maintenance Scheduling**: The system supports scheduling of maintenance activities for resources, temporarily blocking affected items from the borrowing pool during maintenance periods. This ensures that borrowers are not assigned resources that are unavailable due to maintenance.

**Notification and Alerting**: The system generates notifications for important events including request submissions, approvals, rejections, releases, return decisions, overdue reminders, and low-stock alerts. This keeps all stakeholders informed of relevant system activities.

**Activity Logging**: Comprehensive activity logging captures major user actions, providing an audit trail for accountability, monitoring, and administrative review purposes.

---

## 11. Database Overview (Expanded)

### 11.1 Database Architecture

The system utilizes MySQL (or MariaDB) as its relational database management system, with the database named `gso_database`. The database employs the InnoDB storage engine with utf8mb4 character set to properly support international characters and emoji symbols. The schema is designed to maintain referential integrity through foreign key constraints and to optimize query performance through strategic indexing.

### 11.2 Core Tables and Their Purposes

#### 11.2.1 Users Table

The `users` table serves as the central repository for all user accounts within the system, storing both borrower and administrator records.

| Column | Type | Description |
|--------|------|-------------|
| user_id | INT (PK) | Unique identifier for each user |
| username | VARCHAR(50) | Unique login username |
| password | VARCHAR(255) | Hashed password for authentication |
| email | VARCHAR(100) | User's email address (used for verification) |
| full_name | VARCHAR(100) | User's full legal name |
| role | ENUM | User role: 'Admin' or 'Borrower' |
| department | VARCHAR(100) | User's department or affiliation |
| university_id | VARCHAR(50) | Institutional ID number |
| id_image | VARCHAR(255) | Path to uploaded identification document |
| avatar | VARCHAR(255) | Path to profile avatar image |
| status | ENUM | Account status: 'Pending', 'Approved', 'Rejected', 'Disabled' |
| email_verified | TINYINT | Email verification status (0 or 1) |
| verification_token | VARCHAR(255) | Token for email verification |
| reset_token | VARCHAR(255) | Token for password reset |
| reset_expires | DATETIME | Expiration time for reset token |
| created_at | DATETIME | Account creation timestamp |
| updated_at | DATETIME | Last profile update timestamp |

**Key Relationships**: The users table participates in foreign key relationships with resource_requests (as borrower), resource_requests (as reviewed_by), maintenance_schedules (as created_by), activity_logs (as user_id), and notifications (as user_id).

#### 11.2.2 Resources Table

The `resources` table maintains the master inventory of all borrowable items and facilities within the system.

| Column | Type | Description |
|--------|------|-------------|
| resource_id | INT (PK) | Unique identifier for each resource |
| name | VARCHAR(200) | Name of the resource |
| type | ENUM | Resource type: 'Item' or 'Facility' |
| category | VARCHAR(100) | Resource category classification |
| description | TEXT | Detailed description of the resource |
| quantity | INT | Available quantity for items |
| capacity | INT | Capacity for facilities |
| available | INT | Currently available quantity/capacity |
| location | VARCHAR(200) | Physical location of the resource |
| condition | ENUM | Current condition: 'New', 'Good', 'Fair', 'Poor' |
| status | ENUM | Availability status: 'Available', 'Unavailable', 'Archived' |
| image | VARCHAR(255) | Path to resource image |
| created_at | DATETIME | Resource creation timestamp |
| updated_at | DATETIME | Last update timestamp |

**Key Relationships**: The resources table connects to resource_requests (via resource_id), maintenance_schedules (via resource_id), and return_submissions (via resource_id through resource_requests).

#### 11.2.3 Resource Requests Table

The `resource_requests` table is the central transaction record for all borrowing activities, tracking each request from initial submission through final completion.

| Column | Type | Description |
|--------|------|-------------|
| request_id | INT (PK) | Unique identifier for each request |
| user_id | INT (FK) | Reference to the borrower |
| resource_id | INT (FK) | Reference to the requested resource |
| quantity | INT | Quantity requested |
| contact_number | VARCHAR(30) | Borrower's contact number |
| date_needed | DATE | Date the resource is needed |
| time_needed | TIME | Time the resource is needed |
| due_date | DATE | Official due date after release |
| notes | TEXT | Additional notes from borrower |
| status | ENUM | Request status: 'Pending', 'Under Review', 'Approved', 'Rejected', 'Cancelled', 'Released', 'Returned' |
| reviewed_by | INT (FK) | Admin who reviewed the request |
| reviewed_at | DATETIME | Timestamp of review |
| released_at | DATETIME | Timestamp of release |
| returned_at | DATETIME | Timestamp of return approval |
| last_reminded_at | DATETIME | Last overdue reminder sent |
| reminder_count | INT | Number of reminders sent |
| created_at | DATETIME | Request submission timestamp |
| updated_at | DATETIME | Last update timestamp |

**Status Flow**: Pending → Under Review → Approved/Rejected → Released → Returned

**Key Relationships**: Connects to users (as borrower and reviewer), resources, and return_submissions.

#### 11.2.4 Return Submissions Table

The `return_submissions` table stores return records submitted by borrowers, including condition declarations and inspection results.

| Column | Type | Description |
|--------|------|-------------|
| return_id | INT (PK) | Unique identifier for each return |
| request_id | INT (FK) | Reference to the original request |
| resource_id | INT (FK) | Reference to the resource |
| user_id | INT (FK) | Reference to the borrower |
| condition | ENUM | Borrower's declared condition: 'Good', 'Fair', 'Poor', 'Damaged', 'Lost' |
| notes | TEXT | Return notes from borrower |
| inspection_condition | ENUM | Admin's inspected condition after review |
| inspection_notes | TEXT | Admin's inspection notes |
| inspected_by | INT (FK) | Admin who inspected the return |
| inspected_at | DATETIME | Timestamp of inspection |
| status | ENUM | Return status: 'Pending', 'Approved', 'Rejected' |
| created_at | DATETIME | Return submission timestamp |
| updated_at | DATETIME | Last update timestamp |

**Key Relationships**: Connects to resource_requests, resources, users (as borrower and inspector), and return_submission_photos.

#### 11.2.5 Return Submission Photos Table

The `return_submission_photos` table stores photographic evidence submitted with return requests.

| Column | Type | Description |
|--------|------|-------------|
| photo_id | INT (PK) | Unique identifier for each photo |
| return_id | INT (FK) | Reference to the return submission |
| file_path | VARCHAR(255) | Path to the uploaded photo file |
| uploaded_at | DATETIME | Photo upload timestamp |

**Key Relationships**: Connects to return_submissions (via return_id).

#### 11.2.6 Maintenance Schedules Table

The `maintenance_schedules` table records scheduled maintenance activities that temporarily affect resource availability.

| Column | Type | Description |
|--------|------|-------------|
| maintenance_id | INT (PK) | Unique identifier for each schedule |
| resource_id | INT (FK) | Reference to the resource under maintenance |
| start_date | DATE | Start date of maintenance |
| end_date | END_DATE | End date of maintenance |
| duration_days | INT | Duration in days |
| reason | VARCHAR(255) | Reason for maintenance |
| remarks | VARCHAR(500) | Additional remarks |
| status | ENUM | Maintenance status: 'Scheduled', 'In Progress', 'Completed', 'Cancelled' |
| created_by | INT (FK) | Admin who created the schedule |
| updated_by | INT (FK) | Admin who last updated the schedule |
| created_at | DATETIME | Schedule creation timestamp |
| updated_at | DATETIME | Last update timestamp |

**Key Relationships**: Connects to resources and users (as creator and updater).

#### 11.2.7 Notifications Table

The `notifications` table stores system-generated notifications for both administrators and borrowers.

| Column | Type | Description |
|--------|------|-------------|
| notification_id | INT (PK) | Unique identifier for each notification |
| user_id | INT (FK) | Recipient of the notification |
| type | VARCHAR(50) | Notification type |
| title | VARCHAR(200) | Notification title |
| message | TEXT | Notification message |
| link | VARCHAR(255) | Optional link to related content |
| is_read | TINYINT | Read status (0 or 1) |
| created_at | DATETIME | Notification creation timestamp |

**Key Relationships**: Connects to users (as recipient).

#### 11.2.8 Activity Logs Table

The `activity_logs` table maintains a comprehensive audit trail of all significant user actions within the system.

| Column | Type | Description |
|--------|------|-------------|
| log_id | INT (PK) | Unique identifier for each log entry |
| user_id | INT (FK) | User who performed the action |
| action | VARCHAR(100) | Description of the action |
| details | TEXT | Additional details about the action |
| ip_address | VARCHAR(45) | User's IP address |
| created_at | DATETIME | Timestamp of the action |

**Key Relationships**: Connects to users (as actor).

### 11.3 Data Flow Between Tables

The complete borrowing lifecycle demonstrates how data flows through the database tables:

1. **Registration Flow**: A new user record is created in the `users` table with status 'Pending' and email_verified = 0.

2. **Verification Flow**: Upon email verification, the email_verified flag is updated to 1. Upon administrative approval, the status changes to 'Approved'.

3. **Request Flow**: When a borrower submits a request, a new record is created in `resource_requests` with status 'Pending'. The system validates against maintenance conflicts and duplicate requests.

4. **Review Flow**: An administrator reviews the request, changing status to 'Under Review', then either 'Approved' or 'Rejected'. The reviewed_by and reviewed_at fields are populated.

5. **Release Flow**: Upon release, the status changes to 'Released', the due_date is assigned, and the available quantity in `resources` is decremented.

6. **Return Flow**: The borrower submits a return, creating a record in `return_submissions` with status 'Pending' and photos in `return_submission_photos`. The administrator inspects and updates inspection_condition and status.

7. **Completion Flow**: Upon return approval, the resource quantity is restored in `resources`, and the request status changes to 'Returned'.

---

## 12. Technical Architecture

### 12.1 System Architecture Overview

The GSO Borrowing and Inventory Management System follows a three-tier web architecture consisting of presentation, business logic, and data storage layers. This architecture ensures separation of concerns, maintainability, and scalability.

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                        │
│  (HTML, CSS, JavaScript, Browser)                          │
├─────────────────────────────────────────────────────────────┤
│                    BUSINESS LOGIC LAYER                      │
│  (PHP, Controllers, Helpers, Authentication)               │
├─────────────────────────────────────────────────────────────┤
│                    DATA STORAGE LAYER                        │
│  (MySQL Database, File Storage)                            │
└─────────────────────────────────────────────────────────────┘
```

### 12.2 Frontend Technologies

**HTML (HyperText Markup Language)**: The system uses HTML5 for semantic structure and accessibility. Pages are organized with proper document structure including headers, navigation, main content areas, and footers.

**CSS (Cascading Style Sheets)**: Multiple CSS files provide styling for different system components:
- `admin.css` - Administrator interface styling
- `borrower.css` - Borrower interface styling
- `auth.css` - Authentication pages styling
- `landing.css` - Public landing page styling
- `ui-redesign.css` - Modern UI enhancements

**JavaScript**: Client-side scripting handles dynamic interactions including form validation, AJAX requests for asynchronous operations, UI state management, and browser-based notifications.

### 12.3 Backend Technologies

**PHP (Hypertext Preprocessor)**: The server-side programming language handles all business logic, database interactions, session management, and request processing. The system uses:
- PDO (PHP Data Objects) for secure database operations with prepared statements
- Session management for user authentication state
- CSRF token generation and validation for security
- Date/time handling with Asia/Manila timezone

**Helper Functions**: Modular PHP files in the `/includes` directory provide reusable functionality:
- `db.php` - Database connection configuration
- `schema_helper.php` - Dynamic schema migration
- `auth_check.php` - Authentication verification
- `admin_check.php` - Admin role verification
- `borrower_check.php` - Borrower role verification
- `activity_log_helper.php` - Activity logging utilities
- `request_helper.php` - Request validation and formatting
- `notification_helper.php` - Notification generation
- `mail_helper.php` - Email sending functionality

### 12.4 Database Layer

**MySQL/MariaDB**: The relational database管理系统 stores all persistent system data. Key characteristics include:
- InnoDB storage engine for transaction support and foreign key constraints
- utf8mb4 character set for international character support
- Strategic indexing for query optimization
- Foreign key relationships for data integrity

**File Storage**: Uploaded files are stored in designated directories:
- `/uploads/resources/` - Resource images
- `/uploads/returns/` - Return submission proof photos

### 12.5 Component Interaction

The typical request flow through the system architecture:

1. **User Request**: The user accesses a page through their browser, sending an HTTP request to the Apache web server.

2. **PHP Processing**: The requested PHP file executes, including necessary helper files and establishing database connections.

3. **Authentication Check**: For protected pages, the auth_check or role-specific helper validates the user's session and role permissions.

4. **Database Operation**: If data is needed, PHP executes SQL queries through PDO, using prepared statements to prevent SQL injection.

5. **Response Generation**: PHP processes the database results and generates HTML output, which is sent back to the user's browser.

6. **Browser Rendering**: The browser receives the HTML, applies CSS styling, and executes any JavaScript for dynamic behavior.

---

## 13. System Workflow (Expanded)

### 13.1 Authentication Flow

The authentication system implements a multi-step verification process to ensure system security:

**Registration Process**:
1. User accesses the signup page and fills out the registration form with personal details, username, password, and email address.
2. User uploads an identification document (JPG, PNG, or PDF).
3. The system validates all inputs and checks for existing accounts with the same email or username.
4. A new user record is created with status 'Pending' and email_verified = 0.
5. A verification email containing a unique token link is sent to the user's email address.
6. The user clicks the verification link, which validates the token and updates email_verified to 1.
7. An administrator reviews the account and changes status to 'Approved', granting login access.

**Login Process**:
1. User enters email/username and password on the login page.
2. The system validates credentials against the database.
3. The system checks for failed login attempts (brute-force protection):
   - After 3 failed attempts, login is locked for 30 seconds
   - After the lock expires, the user gets one protected retry before being redirected to password recovery
4. The system verifies email verification status (borrowers must be verified).
5. The system verifies account status (must be 'Approved', not 'Disabled' or 'Rejected').
6. On success, the session is regenerated and the user is redirected to their dashboard.
7. On failure, appropriate error messages are displayed.

**Password Recovery Process**:
1. User clicks "Forgot Password" and enters their email address.
2. The system validates the email exists and generates a reset token.
3. An email with a reset link is sent to the user.
4. The user clicks the link (valid within expiration period) and enters a new password.
5. The system updates the password hash and clears the reset token.
6. Rate limiting prevents abuse (15-minute window between requests).

### 13.2 Borrowing Process

The borrowing workflow follows a structured approval process:

**Request Submission**:
1. Borrower logs in and browses available resources.
2. Borrower selects a resource and clicks "Request".
3. The request form appears with fields for quantity, contact number, date needed, time needed, and notes.
4. The system validates all inputs:
   - Quantity must be positive and not exceed available stock
   - Date must not be in the past
   - Contact number format validation
   - No duplicate pending requests for the same resource
   - No maintenance conflicts during the requested period
   - No schedule overlaps with existing released requests
5. On validation success, a new request record is created with status 'Pending'.
6. Notifications are sent to administrators alerting them of the new request.

**Request Review**:
1. Administrator accesses the requests management page.
2. The system displays pending requests sorted by submission time (first-come, first-served).
3. Administrator reviews request details and resource availability.
4. Administrator changes status to 'Under Review' during evaluation.
5. Administrator decides to approve or reject:
   - **Approve**: Status changes to 'Approved', reviewed_by and reviewed_at are recorded
   - **Reject**: Status changes to 'Rejected', reviewed_by, reviewed_at, and rejection notes are recorded
6. The borrower receives a notification of the decision.

**Request Release**:
1. After approval, the administrator releases the request.
2. The system assigns an official due date based on the resource's default borrowing period.
3. The status changes to 'Released'.
4. The available quantity in the resources table is decremented by the requested quantity.
5. The borrower receives a notification of release with due date information.
6. The request now appears in the borrower's active borrowings.

### 13.3 Return Process

The return workflow ensures proper verification and documentation:

**Return Submission**:
1. Borrower accesses their active borrowings from the dashboard.
2. Borrower clicks "Return" on an active borrowing.
3. The return form appears with fields for:
   - Condition declaration (Good, Fair, Poor, Damaged, Lost)
   - Notes describing the resource condition
   - Photo uploads (up to 3 proof photos)
4. The system validates the uploaded files (type and size).
5. On submission, a return record is created with status 'Pending'.
6. Photos are uploaded to the returns directory.
7. The borrower receives a confirmation notification.

**Return Review**:
1. Administrator accesses the return review page.
2. The system displays pending returns with associated request details.
3. Administrator views the submitted photos and reads the borrower's condition notes.
4. Administrator decides to approve or reject:
   - **Approve**: 
     - Status changes to 'Approved'
     - inspection_condition is set based on admin's assessment
     - inspection_notes are recorded
     - The resource quantity is restored in the resources table
     - The original request status changes to 'Returned'
   - **Reject**: 
     - Status changes to 'Rejected'
     - The borrower must resubmit with improved evidence
5. Both parties receive notifications of the decision.

### 13.4 Notification Flow

The notification system keeps users informed of system activities:

**Notification Types**:
- **Request Notifications**: New request, request approved, request rejected, request released
- **Return Notifications**: Return submitted, return approved, return rejected
- **Alert Notifications**: Overdue reminders, low-stock alerts
- **System Notifications**: Account approval, password reset, email verification

**Notification Delivery**:
1. Triggering events (user actions, system processes) call the notification helper.
2. A notification record is created in the notifications table.
3. The recipient sees the notification in their topbar notification panel.
4. Unread notifications are highlighted, and clicking marks them as read.

---

## 14. Diagram Guides

This section provides guidance for creating various system diagrams to visualize the GSO Borrowing and Inventory Management System.

### 14.1 Data Flow Diagram (DFD)

Data Flow Diagrams illustrate how data moves through the system, showing processes, data stores, and external entities.

#### 14.1.1 Context Diagram (Level 0)

The Context Diagram shows the system as a single process interacting with external entities:

```
                    ┌─────────────────────────┐
                    │   External Entities     │
                    │                         │
    ┌───────────────┼─────────────────────────┼───────────────┐
    │               │                         │               │
    │   Borrower    │                         │    Admin      │
    │               │                         │               │
    └───────┬───────┘                         └───────┬───────┘
            │                                         │
            │ 1. Submit Request / Return              │
            │ 2. View Resources / Status              │
            │ 3. Receive Notifications                │
            │                                         │
            ▼                                         ▼
    ┌─────────────────────────────────────────────────────────┐
    │                                                         │
    │         GSO Borrowing & Inventory Management           │
    │                      System                            │
    │                                                         │
    │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐    │
    │  │   Account   │  │  Resource   │  │   Request   │    │
    │  │  Management │  │  Management │  │  Processing │    │
    │  └─────────────┘  └─────────────┘  └─────────────┘    │
    │                                                         │
    └─────────────────────────────────────────────────────────┘
            │                                         │
            │ 4. Approved/Rejected Response            │
            │ 5. Updated Status / Reports              │
            │ 6. Activity Logs                         │
            │                                         │
            ▼                                         ▼
    ┌───────────────┐                         ┌───────────────┐
    │   Database    │                         │  File System  │
    │  (MySQL)      │                         │  (Uploads)    │
    └───────────────┘                         └───────────────┘
```

**External Entities**:
- **Borrower**: Student or guest who requests and borrows resources
- **Admin**: GSO personnel who manages the system
- **Database**: MySQL database storing system data
- **File System**: Storage for uploaded images and documents

#### 14.1.2 Level 1 DFD

Level 1 DFD breaks down the main system into major processes:

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           BORROWER                                      │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐              │
│  │   Account    │    │   Resource   │    │   Request    │              │
│  │  Registration│    │   Browsing   │    │  Submission  │              │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘              │
│         │                   │                   │                       │
│         ▼                   ▼                   ▼                       │
│  ┌──────────────────────────────────────────────────────────┐          │
│  │                    PROCESS DATA                           │          │
│  │  ┌────────────┐  ┌────────────┐  ┌────────────┐          │          │
│  │  │  Validate  │  │   Check    │  │   Store    │          │          │
│  │  │   Input    │  │  Conflicts │  │   Request  │          │          │
│  │  └────────────┘  └────────────┘  └────────────┘          │          │
│  └──────────────────────────────────────────────────────────┘          │
│         │                   │                   │                       │
│         ▼                   ▼                   ▼                       │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐              │
│  │    Users     │    │  Resources   │    │Resource      │              │
│  │   Table      │    │   Table      │    │Requests      │              │
│  └──────────────┘    └──────────────┘    │   Table      │              │
│                                          └──────────────┘              │
└─────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                           ADMIN                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

**Level 1 Processes**:
1. **Account Registration**: Handles new user signup, email verification, and approval
2. **Resource Browsing**: Displays available resources with search and filter capabilities
3. **Request Submission**: Collects and validates borrowing requests
4. **Request Processing**: Admin review, approval, rejection, and release
5. **Return Processing**: Borrower return submission and admin inspection
6. **Inventory Management**: Resource CRUD operations and stock management
7. **Maintenance Scheduling**: Scheduling and tracking resource maintenance
8. **Notification System**: Generates and delivers system notifications
9. **Activity Logging**: Records all significant system actions

### 14.2 Flowcharts

#### 14.2.1 Login Process Flowchart

```
┌─────────────────┐
│   Start Login   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Enter Email/    │
│ Username &      │
│ Password        │
└────────┬────────┘
         │
         ▼
┌─────────────────┐     ┌─────────────────┐
│ Validate        │────▶│ Invalid         │──▶ Display Error
│ Credentials     │     │ Credentials     │    Message
└────────┬────────┘     └─────────────────┘
         │
         ▼ Yes
┌─────────────────┐     ┌─────────────────┐
│ Check Failed    │────▶│ Lock Account    │──▶ Show Lockout
│ Attempts > 3    │     │ (30 seconds)    │    Message
└────────┬────────┘     └─────────────────┘
         │ No
         ▼
┌─────────────────┐     ┌─────────────────┐
│ Email Verified? │────▶│ Show Verification│──▶ Redirect to
│                 │     │ Required Error   │    Login
└────────┬────────┘     └─────────────────┘
         │ Yes
         ▼
┌─────────────────┐     ┌─────────────────┐
│ Account Status  │────▶│ Show Account    │──▶ Redirect to
│ = Approved?     │     │ Disabled Error  │    Login
└────────┬────────┘     └─────────────────┘
         │ Yes
         ▼
┌─────────────────┐
│ Regenerate      │
│ Session ID      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Redirect to     │
│ Dashboard       │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│   End Login     │
└─────────────────┘
```

#### 14.2.2 Borrowing Workflow Flowchart

```
┌─────────────────────┐
│  Borrower Logs In   │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Browse Resources   │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐     ┌─────────────────────┐
│ Select Resource &  │────▶│ Resource Available? │──▶ Return to Browse
│ Click "Request"    │     │                     │
└──────────┬──────────┘     └─────────────────────┘
           │ Yes
           ▼
┌─────────────────────┐
│  Fill Request Form  │
│  (Qty, Date, Time,  │
│   Contact, Notes)   │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐     ┌─────────────────────┐
│ Validate Inputs &  │────▶│ Show Validation     │──▶ Return to Form
│ Check Conflicts    │     │ Errors              │
└──────────┬──────────┘     └─────────────────────┘
           │ Valid
           ▼
┌─────────────────────┐
│  Submit Request     │
│  (Status: Pending)  │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Notify Admin       │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Admin Reviews      │
│  Request            │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐     ┌─────────────────────┐
│  Admin Decision     │────▶│  Reject Request     │──▶ Notify Borrower
│                     │     │  (Status: Rejected) │
└──────────┬──────────┘     └─────────────────────┘
           │ Approve
           ▼
┌─────────────────────┐
│  Admin Releases     │
│  Request            │
│  (Status: Released) │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Decrement Resource │
│  Stock              │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Notify Borrower    │
│  of Release &       │
│  Due Date           │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  End Workflow       │
└─────────────────────┘
```

#### 14.2.3 Return Workflow Flowchart

```
┌─────────────────────┐
│  Borrower Views     │
│  Active Borrowings  │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Click "Return"     │
│  on Active Request  │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Fill Return Form   │
│  (Condition, Notes, │
│   Photo Uploads)    │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Validate Uploads   │
│  (Type, Size)       │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Submit Return      │
│  (Status: Pending)  │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Upload Photos to   │
│  File System        │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Notify Admin       │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Admin Reviews      │
│  Return & Photos    │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐     ┌─────────────────────┐
│  Admin Decision     │────▶│  Reject Return      │──▶ Notify Borrower
│                     │     │  (Status: Rejected) │    to Resubmit
└──────────┬──────────┘     └─────────────────────┘
           │ Approve
           ▼
┌─────────────────────┐
│  Inspect Resource   │
│  & Set Condition    │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Update Resource    │
│  Stock (Restore)    │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Update Request     │
│  Status to Returned │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  Notify Borrower    │
│  of Approval        │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│  End Workflow       │
└─────────────────────┘
```

### 14.3 Entity Relationship Diagram (ERD)

The ERD illustrates the relationships between database entities:

```
┌──────────────────┐         ┌──────────────────┐
│      users       │         │      resources   │
├──────────────────┤         ├──────────────────┤
│ user_id (PK)     │         │ resource_id (PK) │
│ username         │         │ name             │
│ password         │         │ type             │
│ email            │         │ category         │
│ full_name        │         │ quantity         │
│ role             │         │ available        │
│ department       │         │ condition        │
│ university_id    │         │ status           │
│ status           │         │ location         │
│ email_verified   │         │ image            │
└────────┬─────────┘         └────────┬─────────┘
         │                            │
         │ 1:N                        │ 1:N
         │ (borrower)                 │ (requested by)
         │                            │
         ▼                            ▼
┌─────────────────────────────────────────────────────────────┐
│                   resource_requests                         │
├─────────────────────────────────────────────────────────────┤
│ request_id (PK)                                             │
│ user_id (FK) ──────────▶ users.user_id                      │
│ resource_id (FK) ──────▶ resources.resource_id              │
│ quantity                                                    │
│ date_needed                                                 │
│ due_date                                                    │
│ status                                                      │
│ reviewed_by ──────────▶ users.user_id                       │
│ released_at                                                 │
└────────────────────────┬────────────────────────────────────┘
                         │
                         │ 1:1
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                   return_submissions                         │
├─────────────────────────────────────────────────────────────┤
│ return_id (PK)                                              │
│ request_id (FK) ──────▶ resource_requests.request_id        │
│ resource_id (FK) ──────▶ resources.resource_id              │
│ user_id (FK) ──────────▶ users.user_id                      │
│ condition                                                   │
│ inspection_condition                                        │
│ status                                                      │
│ inspected_by ──────────▶ users.user_id                      │
└────────────────────────┬────────────────────────────────────┘
                         │
                         │ 1:N
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│              return_submission_photos                       │
├─────────────────────────────────────────────────────────────┤
│ photo_id (PK)                                               │
│ return_id (FK) ──────▶ return_submissions.return_id         │
│ file_path                                                   │
│ uploaded_at                                                 │
└─────────────────────────────────────────────────────────────┘


┌──────────────────┐         ┌──────────────────┐
│maintenance_sched │         │   notifications  │
├──────────────────┤         ├──────────────────┤
│ maintenance_id   │         │ notification_id  │
│ resource_id (FK) │         │ user_id (FK)     │
│ start_date       │         │ type             │
│ end_date         │         │ title            │
│ reason           │         │ message          │
│ status           │         │ is_read          │
│ created_by (FK)  │         │ created_at       │
└────────┬─────────┘         └──────────────────┘
         │
         │ 1:N
         │
         ▼
┌──────────────────┐
│   activity_logs  │
├──────────────────┤
│ log_id (PK)      │
│ user_id (FK)     │
│ action           │
│ details          │
│ ip_address       │
│ created_at       │
└──────────────────┘
```

**Relationship Cardinality**:

| Relationship | Type | Description |
|--------------|------|-------------|
| users → resource_requests | 1:N | A user can have multiple requests |
| resources → resource_requests | 1:N | A resource can have multiple requests |
| users → resource_requests (reviewed_by) | 1:N | An admin can review multiple requests |
| resource_requests → return_submissions | 1:1 | Each request has at most one return |
| return_submissions → return_submission_photos | 1:N | A return can have multiple photos |
| resources → maintenance_schedules | 1:N | A resource can have multiple maintenance schedules |
| users → notifications | 1:N | A user can have multiple notifications |
| users → activity_logs | 1:N | A user can have multiple activity log entries |

### 14.4 UML Diagrams

#### 14.4.1 Use Case Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         GSO Borrowing System                            │
└─────────────────────────────────────────────────────────────────────────┘

                        ┌─────────────────────┐
                        │      Borrower       │
                        │                     │
                        │ • Register Account  │
                        │ • Verify Email      │
                        │ • Login/Logout      │
                        │ • Browse Resources  │
                        │ • Submit Request    │
                        │ • View My Requests  │
                        │ • Cancel Request    │
                        │ • Submit Return     │
                        │ • View History      │
                        │ • Update Profile    │
                        │ • Change Password   │
                        └──────────┬──────────┘
                                   │
                                   │ <<include>>
                                   │
                                   ▼
                        ┌─────────────────────┐
                        │   Authentication    │
                        │   (Login/Logout)    │
                        └─────────────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│  ┌─────────────────────┐         ┌─────────────────────┐              │
│  │    Admin            │         │    System           │              │
│  │                     │         │                     │              │
│  │ • Manage Borrowers  │         │ • Send Notification │              │
│  │ • Manage Admins     │         │ • Log Activity      │              │
│  │ • Manage Resources  │         │ • Validate Request  │              │
│  │ • Review Requests   │         │ • Check Conflicts   │              │
│  │ • Release Requests  │         │ • Track Overdue     │              │
│  │ • Review Returns    │         │ • Generate Reports  │              │
│  │ • Schedule Maint.   │         │                     │              │
│  │ • View Reports      │         │                     │              │
│  │ • View Activity Log │         │                     │              │
│  └──────────┬──────────┘         └─────────────────────┘              │
│             │                                                         │
│             │ <<extend>>                                              │
│             │ (conditional behavior)                                  │
│             ▼                                                         │
│  ┌─────────────────────┐                                              │
│  │   Account Approval  │                                              │
│  │   (Admin only)      │                                              │
│  └─────────────────────┘                                              │
└─────────────────────────────────────────────────────────────────────────┘
```

#### 14.4.2 Activity Diagram (Borrowing Process)

```
┌──────────────┐
│    Start     │
└──────┬───────┘
       │
       ▼
┌──────────────┐
│  Login       │
└──────┬───────┘
       │
       ▼
┌──────────────┐
│  Browse      │◄────────────┐
│  Resources   │             │
└──────┬───────┘             │
       │                     │
       ▼                     │
┌──────────────┐             │
│  Select      │             │
│  Resource    │             │
└──────┬───────┘             │
       │                     │
       ▼                     │
┌──────────────┐     No      │ ┌──────────────┐
│  Available?  │─────────────│─│ Try Another  │
└──────┬───────┘             │ │  Resource    │
       │ Yes                 │ └──────────────┘
       ▼                     │
┌──────────────┐             │
│  Fill Request│             │
│  Form        │             │
└──────┬───────┘             │
       │                     │
       ▼                     │
┌──────────────┐     Invalid │ ┌──────────────┐
│  Valid?      │─────────────│─│ Fix Form     │
└──────┬───────┘             │ └──────────────┘
       │ Valid               │
       ▼                     │
┌──────────────┐             │
│  Submit      │             │
│  Request     │             │
└──────┬───────┘             │
       │                     │
       ▼                     │
┌──────────────┐             │
│  Wait for    │◄────────────┘
│  Approval    │
└──────┬───────┘
       │
       ▼
┌──────────────┐     Rejected│ ┌──────────────┐
│  Decision    │─────────────│─│ View Reason  │
└──────┬───────┘             │ └──────────────┘
       │ Approved            │
       ▼                     │
┌──────────────┐             │
│  Receive     │             │
│  Release     │             │
└──────┬───────┘             │
       │                     │
       ▼                     │
┌──────────────┐             │
│  Use         │◄────────────┘
│  Resource    │
└──────┬───────┘
       │
       ▼
┌──────────────┐
│     End      │
└──────────────┘
```

#### 14.4.3 Sequence Diagram (Login Process)

```
Borrower                    System                      Database
   │                          │                            │
   │  1. Enter credentials    │                            │
   │─────────────────────────▶│                            │
   │                          │                            │
   │                          │  2. Query user by email   │
   │                          │───────────────────────────▶│
   │                          │                            │
   │                          │  3. Return user record    │
   │                          │◀───────────────────────────│
   │                          │                            │
   │                          │  4. Verify password       │
   │                          │───────────────────────────▶│
   │                          │                            │
   │                          │  5. Return match result   │
   │                          │◀───────────────────────────│
   │                          │                            │
   │                          │  6. Check failed attempts │
   │                          │───────────────────────────▶│
   │                          │                            │
   │                          │  7. Return attempt count  │
   │                          │◀───────────────────────────│
   │                          │                            │
   │                          │  8. Check email verified  │
   │                          │───────────────────────────▶│
   │                          │                            │
   │                          │  9. Return verified status│
   │                          │◀───────────────────────────│
   │                          │                            │
   │                          │  10. Check account status │
   │                          │───────────────────────────▶│
   │                          │                            │
   │                          │  11. Return status        │
   │                          │◀───────────────────────────│
   │                          │                            │
   │  12. Login success       │                            │
   │◀─────────────────────────│                            │
   │                          │                            │
   │                          │  13. Regenerate session   │
   │                          │───────────────────────────▶│
   │                          │                            │
   │  14. Redirect to         │                            │
   │     Dashboard            │                            │
   │◀─────────────────────────│                            │
   │                          │                            │
```

#### 14.4.4 Class Diagram (Core Domain Model)

```
┌─────────────────────┐         ┌─────────────────────┐
│       User          │         │      Resource       │
├─────────────────────┤         ├─────────────────────┤
│ - user_id: int      │         │ - resource_id: int  │
│ - username: string  │         │ - name: string      │
│ - email: string     │         │ - type: ResourceType│
│ - password: string  │         │ - category: string  │
│ - full_name: string │         │ - quantity: int     │
│ - role: Role        │         │ - available: int    │
│ - status: Status    │         │ - condition: Cond.  │
│ - email_verified    │         │ - status: AvailStatus│
│   : bool            │         │ - location: string  │
└─────────┬───────────┘         └──────────┬──────────┘
          │ 1:N                           │ 1:N
          │                               │
          ▼                               ▼
┌─────────────────────────────────────────────────────────────┐
│                   ResourceRequest                           │
├─────────────────────────────────────────────────────────────┤
│ - request_id: int                                           │
│ - user_id: int (FK)                                         │
│ - resource_id: int (FK)                                     │
│ - quantity: int                                             │
│ - date_needed: Date                                         │
│ - due_date: Date                                            │
│ - status: RequestStatus                                     │
│ - reviewed_by: int (FK)                                     │
│ - released_at: DateTime                                     │
│ - returned_at: DateTime                                     │
└────────────────────────┬────────────────────────────────────┘
                         │ 1:1
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                   ReturnSubmission                          │
├─────────────────────────────────────────────────────────────┤
│ - return_id: int                                            │
│ - request_id: int (FK)                                      │
│ - resource_id: int (FK)                                     │
│ - user_id: int (FK)                                         │
│ - condition: Condition                                      │
│ - inspection_condition: Condition                           │
│ - status: ReturnStatus                                      │
│ - inspected_by: int (FK)                                    │
└────────────────────────┬────────────────────────────────────┘
                         │ 1:N
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│               ReturnSubmissionPhoto                         │
├─────────────────────────────────────────────────────────────┤
│ - photo_id: int                                             │
│ - return_id: int (FK)                                       │
│ - file_path: string                                         │
│ - uploaded_at: DateTime                                     │
└─────────────────────────────────────────────────────────────┘


┌─────────────────────┐         ┌─────────────────────┐
│MaintenanceSchedule │         │    Notification     │
├─────────────────────┤         ├─────────────────────┤
│ - maintenance_id    │         │ - notification_id   │
│ - resource_id: int  │         │ - user_id: int      │
│ - start_date: Date  │         │ - type: string      │
│ - end_date: Date    │         │ - title: string     │
│ - reason: string    │         │ - message: string   │
│ - status: MaintStatus│         │ - is_read: bool     │
│ - created_by: int   │         │ - created_at: Date  │
└─────────────────────┘         └─────────────────────┘


┌─────────────────────┐
│    ActivityLog      │
├─────────────────────┤
│ - log_id: int       │
│ - user_id: int      │
│ - action: string    │
│ - details: string   │
│ - ip_address: string│
│ - created_at: Date  │
└─────────────────────┘

Legend:
- ResourceType: {Item, Facility}
- Role: {Admin, Borrower}
- Status: {Pending, Approved, Rejected, Disabled}
- RequestStatus: {Pending, Under Review, Approved, Rejected, Cancelled, Released, Returned}
- ReturnStatus: {Pending, Approved, Rejected}
- Condition: {New, Good, Fair, Poor}
- AvailabilityStatus: {Available, Unavailable, Archived}
- MaintenanceStatus: {Scheduled, In Progress, Completed, Cancelled}
```

---

## 15. Additional System Analysis

### 15.1 Internal System Operations

The GSO Borrowing and Inventory Management System implements several internal mechanisms to ensure reliable and secure operation:

**Session Management**: The system uses PHP sessions to maintain user authentication state across page requests. Session IDs are regenerated upon successful login to prevent session fixation attacks. Session timeout and idle detection help protect against unauthorized access.

**Input Validation**: All user inputs undergo server-side validation through the `request_helper.php` functions. Date inputs are normalized to the Asia/Manila timezone. Contact numbers are validated for proper format. Quantity inputs are checked against available stock.

**Conflict Detection**: Before accepting a request, the system performs several conflict checks:
- **Duplicate Request Check**: Prevents multiple pending requests for the same resource by the same user
- **Maintenance Conflict Check**: Ensures the requested date range does not overlap with scheduled maintenance
- **Schedule Overlap Check**: For facilities, ensures the requested time slot does not conflict with already released bookings
- **Stock Availability Check**: Ensures sufficient quantity is available

**File Upload Handling**: Uploaded files (ID images, profile photos, resource images, return proof photos) are validated for:
- Allowed file types (JPG, PNG, PDF)
- Maximum file size limits
- Secure storage in designated directories

**Activity Logging**: Every significant user action is recorded in the activity_logs table, including:
- Login and logout events
- Account registration and approval
- Request submission, approval, rejection, and release
- Return submission and inspection
- Resource CRUD operations
- Maintenance scheduling
- Profile updates

### 15.2 Security Implementation

The system incorporates multiple security measures:

**Password Security**: Passwords are stored using PHP's password_hash() function with the bcrypt algorithm, ensuring that plain text passwords are never stored.

**SQL Injection Prevention**: All database queries use PDO prepared statements with bound parameters, eliminating SQL injection vulnerabilities.

**CSRF Protection**: Forms include hidden CSRF tokens that are validated on submission, preventing cross-site request forgery attacks.

**Rate Limiting**: Login attempts, signup requests, and password reset requests are subject to rate limiting to prevent brute-force and abuse attacks.

**Role-Based Access Control**: The system implements role checks (admin_check.php, borrower_check.php) that verify user roles before allowing access to protected pages.

**File Upload Security**: Uploaded files are validated for type and size, and stored outside the web root or with restricted access to prevent execution of malicious files.

---

*Document updated: April 2026*
