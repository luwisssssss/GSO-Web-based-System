<?php
declare(strict_types=1);

/**
 * Seed the GSO knowledge base with the procedures the application actually
 * implements.
 *
 * Run with local MySQL:
 *   C:\xampp\php\php.exe scripts\seed_ai_knowledge.php
 *
 * Idempotent: entries are matched by title, so re-running refreshes the seeded
 * wording without duplicating rows or touching entries an admin has added.
 * Pass --force to overwrite seeded entries that were edited in the admin page.
 */

$root = dirname(__DIR__);
require_once $root . "/config/bootstrap.php";
require_once $root . "/config/db.php";
require_once $root . "/includes/ai_knowledge_helper.php";

$force = in_array("--force", $argv ?? [], true);

$entries = [
    [
        "title" => "How to borrow equipment",
        "category" => "Borrowing",
        "question" => "How do I borrow equipment?",
        "keywords" => "borrow, borrowing, equipment, request, item, loan, how to borrow, request equipment",
        "content" => "To borrow equipment: open Browse Resources, select the item you need, then submit a request with the quantity, your contact number, the date needed, and the purpose. "
            . "Your request starts as Pending. GSO staff review it (Under Review), then either approve or reject it. "
            . "Once approved, GSO releases the item to you and the loan appears under My Borrowed. "
            . "You can follow the status of every request under My Requests.",
    ],
    [
        "title" => "Borrowing requirements",
        "category" => "Borrowing",
        "question" => "What are the requirements to borrow?",
        "keywords" => "requirements, requirement, needed, eligibility, qualify, contact number, purpose",
        "content" => "You need an approved and verified GSO account to borrow. "
            . "When submitting a request you must provide the quantity, a valid contact number for return reminders, the date needed, and the purpose of use. "
            . "Facility reservations also require a start time and an end time. "
            . "Requests are checked against current availability before they are accepted.",
    ],
    [
        "title" => "How to reserve a facility",
        "category" => "Facilities",
        "question" => "How do I reserve a facility?",
        "keywords" => "facility, reserve, reservation, book, booking, room, venue, avr, hall, schedule",
        "content" => "To reserve a facility: open Browse Resources, choose the facility, then enter the date, start time, and end time. "
            . "The system checks your schedule against existing approved reservations and rejects the request if it overlaps one. "
            . "Submit the request and it goes to GSO for review and approval. "
            . "After use, the reservation still needs an explicit post-use submission and GSO review before it is marked complete - it does not close automatically when the end time passes.",
    ],
    [
        "title" => "Facility schedule conflicts",
        "category" => "Facilities",
        "question" => "Why was my facility reservation rejected for a conflict?",
        "keywords" => "conflict, overlap, double booking, already booked, taken, unavailable schedule",
        "content" => "A facility can only hold one approved reservation for a given time range. "
            . "If your requested date and time overlap an existing approved reservation, the system blocks the request and tells you there is a schedule conflict. "
            . "Pick a different time slot or date, or contact GSO to discuss alternatives.",
    ],
    [
        "title" => "How to return borrowed items",
        "category" => "Returns",
        "question" => "What should I do when returning an item?",
        "keywords" => "return, returning, bring back, give back, submit return, proof, condition",
        "content" => "Open My Borrowed and submit a return for the loan. "
            . "Record the condition of the item and attach proof photos. "
            . "Your submission is marked Pending until GSO inspects the item. "
            . "GSO then approves the return, which restores the stock and completes the transaction, or rejects it with remarks so you can resubmit. "
            . "Completed transactions appear under History.",
    ],
    [
        "title" => "Returning damaged or incomplete items",
        "category" => "Returns",
        "question" => "What happens if I return a damaged item?",
        "keywords" => "damaged, damage, broken, missing parts, needs repair, lost, penalty, condition",
        "content" => "Declare the real condition when you submit the return - Good, Damaged, Missing Parts, Needs Repair, or Lost. "
            . "GSO inspects the item and records the final condition. "
            . "Units assessed as Damaged, Missing Parts, or Needs Repair are held out of service until they are repaired; only the affected units are withheld, so the remaining good units stay available to others. "
            . "Items reported Lost are deducted from total stock. Contact GSO about any accountability that follows.",
    ],
    [
        "title" => "Overdue items",
        "category" => "Returns",
        "question" => "What if my borrowed item is overdue?",
        "keywords" => "overdue, late, past due, due date, reminder, deadline",
        "content" => "Each released loan has a due date. If the due date passes before your return is submitted, the loan is flagged as overdue and both you and GSO staff are notified. "
            . "Submit the return as soon as possible through My Borrowed, and contact GSO if you need more time.",
    ],
    [
        "title" => "How to report a damaged or broken item",
        "category" => "Incidents",
        "question" => "How do I report a broken chair or damaged equipment?",
        "keywords" => "incident, report, broken, damaged, issue, problem, report issue, complaint, defective",
        "content" => "Open Report an Issue and file an incident report. "
            . "Provide the incident type, a clear description, the location, and attach a photo if you can. You may also link the affected resource. "
            . "GSO reviews the report, sets its priority, assigns it, and updates its status as work progresses. "
            . "You can follow your reports under My Reports and you are notified when the status changes.",
    ],
    [
        "title" => "How to submit an incident report",
        "category" => "Incidents",
        "question" => "How do I submit an incident report?",
        "keywords" => "submit incident, file report, incident report, how to report, accident",
        "content" => "Use Report an Issue in your sidebar. "
            . "Fill in the incident type, description, and location, then submit. A photo attachment is optional but helps GSO assess the issue. "
            . "After submitting, the report appears under My Reports with a status of Submitted and moves through Under Review, In Progress, and Resolved as GSO handles it.",
    ],
    [
        "title" => "Equipment under maintenance",
        "category" => "Maintenance",
        "question" => "Why is an item unavailable or under maintenance?",
        "keywords" => "maintenance, repair, servicing, under repair, unavailable, out of service",
        "content" => "GSO schedules maintenance for resources that need servicing or repair. "
            . "While a maintenance schedule is in progress the resource is marked Maintenance and cannot be requested. "
            . "When GSO completes the maintenance, the resource returns to Available and any units that were held for repair go back into circulation. "
            . "Check Browse Resources for the current status of an item.",
    ],
    [
        "title" => "Where to find your requests and loans",
        "category" => "Navigation",
        "question" => "Where can I see my requests?",
        "keywords" => "where, find, see, my requests, my borrowed, history, navigate, page, notifications",
        "content" => "Your sidebar has everything: Browse Resources to search and request, My Requests for the status of submitted requests, "
            . "My Borrowed for items currently released to you and to submit returns, History for completed transactions, "
            . "Report an Issue to file an incident, and My Reports to follow those incidents. "
            . "The bell icon in the top bar shows notifications for approvals, releases, returns, and incident updates.",
    ],
    [
        "title" => "About the General Services Office",
        "category" => "General",
        "question" => "What does the GSO do?",
        "keywords" => "gso, general services office, office, about, contact, help, urs, binangonan",
        "content" => "The General Services Office of University of Rizal System - Binangonan Campus manages campus equipment and facilities. "
            . "It handles borrowing requests, facility reservations, releases and returns, incident reports, and maintenance of campus resources. "
            . "This assistant can explain those procedures. For anything requiring a decision, an exception, or personal account help, contact the GSO office directly.",
    ],
    [
        "title" => "Using the GSO reports page",
        "category" => "Navigation",
        "question" => "How do I use the GSO reports?",
        "keywords" => "reports, report, analytics, export, statistics, summary, records, dashboard",
        "content" => "GSO staff open Reports from the admin sidebar. "
            . "It covers Inventory Status, Borrowing Transactions, Reservation Records, Overdue Items, "
            . "Damaged / Lost Items, Maintenance Records, and Incident Reporting Analytics. "
            . "The incident analytics section breaks reports down by category, priority, location, and resolution time, "
            . "and the page supports filtering and export. "
            . "The assistant does not generate reports; open the Reports page to view or export them.",
    ],    [
        "title" => "Request statuses explained",
        "category" => "Borrowing",
        "question" => "What do the request statuses mean?",
        "keywords" => "status, pending, under review, approved, rejected, released, returned, cancelled, meaning",
        "content" => "Pending means GSO has not started reviewing your request yet. Under Review means staff are evaluating it. "
            . "Approved means it was accepted but not yet handed over. Released means the item is now in your possession and the loan is active. "
            . "Returned means you returned it and GSO verified it. Rejected means GSO declined the request, and Cancelled means you withdrew it before release.",
    ],
];

$created = 0;
$updated = 0;
$skipped = 0;

foreach ($entries as $entry) {
    $validation = gsoValidateKnowledgeInput($entry + ["status" => "Active"]);

    if ($validation["errors"] !== []) {
        fwrite(STDERR, "Invalid seed entry '{$entry["title"]}': " . implode(" ", $validation["errors"]) . "\n");
        exit(1);
    }

    $values = $validation["values"];

    $stmt = $pdo->prepare("SELECT entry_id, updated_by FROM ai_knowledge_entries WHERE title = :title LIMIT 1");
    $stmt->execute([":title" => $values["title"]]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        gsoCreateKnowledgeEntry($pdo, $values, 0);
        $created++;
        continue;
    }

    // Leave anything an admin has edited alone unless --force is given.
    if (!$force && $existing["updated_by"] !== null) {
        $skipped++;
        continue;
    }

    gsoUpdateKnowledgeEntry($pdo, (int) $existing["entry_id"], $values, 0);
    $updated++;
}

$total = (int) $pdo->query("SELECT COUNT(*) FROM ai_knowledge_entries WHERE status = 'Active'")->fetchColumn();

echo "Knowledge base seeded: {$created} created, {$updated} refreshed, {$skipped} preserved (admin-edited).\n";
echo "Active entries: {$total}\n";
