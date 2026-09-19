<?php

/**
 * AI knowledge base management.
 *
 * Admin-only. admin_check.php enforces the role and re-validates the account
 * against the database, so a borrower cannot reach this page or its POST
 * actions by typing the URL.
 */

require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/ai_knowledge_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "AI Knowledge Base";
$activePage = "ai_knowledge";
$cssFile = "../public/assets/css/pages/admin.css";

$adminUserId = (int) ($_SESSION["user_id"] ?? 0);

function redirectKnowledgePage(): void
{
    header("Location: ai_knowledge.php");
    exit();
}

$editingEntry = null;
$formErrors = [];
$formValues = [
    "title" => "",
    "category" => "Borrowing",
    "question" => "",
    "content" => "",
    "keywords" => "",
    "status" => "Active",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = (string) ($_POST["csrf_token"] ?? "");
    $action = trim((string) ($_POST["action"] ?? ""));

    if (!hash_equals((string) $_SESSION["csrf_token"], $csrfToken)) {
        $_SESSION["flash_message"] = "Invalid request token.";
        $_SESSION["flash_type"] = "error";
        redirectKnowledgePage();
    }

    $entryId = (int) ($_POST["entry_id"] ?? 0);

    if ($action === "save") {
        $validation = gsoValidateKnowledgeInput($_POST);
        $formErrors = $validation["errors"];
        $formValues = $validation["values"];

        if ($formErrors === []) {
            if ($entryId > 0) {
                $existing = gsoGetKnowledgeEntry($pdo, $entryId);

                if ($existing === null) {
                    $_SESSION["flash_message"] = "That knowledge entry no longer exists.";
                    $_SESSION["flash_type"] = "error";
                    redirectKnowledgePage();
                }

                gsoUpdateKnowledgeEntry($pdo, $entryId, $formValues, $adminUserId);
                addActivityLog(
                    $pdo,
                    $adminUserId,
                    "AI Knowledge Updated",
                    "Updated AI knowledge entry #" . $entryId . " (" . $formValues["title"] . ")."
                );
                $_SESSION["flash_message"] = "Knowledge entry updated.";
            } else {
                $newId = gsoCreateKnowledgeEntry($pdo, $formValues, $adminUserId);
                addActivityLog(
                    $pdo,
                    $adminUserId,
                    "AI Knowledge Created",
                    "Created AI knowledge entry #" . $newId . " (" . $formValues["title"] . ")."
                );
                $_SESSION["flash_message"] = "Knowledge entry created.";
            }

            $_SESSION["flash_type"] = "success";
            redirectKnowledgePage();
        }
    } elseif ($action === "toggle_status") {
        $entry = gsoGetKnowledgeEntry($pdo, $entryId);

        if ($entry === null) {
            $_SESSION["flash_message"] = "That knowledge entry no longer exists.";
            $_SESSION["flash_type"] = "error";
            redirectKnowledgePage();
        }

        $nextStatus = ((string) $entry["status"]) === "Active" ? "Inactive" : "Active";

        if (gsoSetKnowledgeEntryStatus($pdo, $entryId, $nextStatus, $adminUserId)) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "AI Knowledge Status Changed",
                "Set AI knowledge entry #" . $entryId . " to " . $nextStatus . "."
            );
            $_SESSION["flash_message"] = "Entry is now " . $nextStatus . ".";
            $_SESSION["flash_type"] = "success";
        } else {
            $_SESSION["flash_message"] = "This entry was already updated. Please refresh the page.";
            $_SESSION["flash_type"] = "error";
        }

        redirectKnowledgePage();
    } elseif ($action === "delete") {
        $entry = gsoGetKnowledgeEntry($pdo, $entryId);

        if ($entry !== null && gsoDeleteKnowledgeEntry($pdo, $entryId)) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "AI Knowledge Deleted",
                "Deleted AI knowledge entry #" . $entryId . " (" . (string) $entry["title"] . ")."
            );
            $_SESSION["flash_message"] = "Knowledge entry deleted.";
            $_SESSION["flash_type"] = "success";
        } else {
            $_SESSION["flash_message"] = "That knowledge entry no longer exists.";
            $_SESSION["flash_type"] = "error";
        }

        redirectKnowledgePage();
    } else {
        $_SESSION["flash_message"] = "Invalid action.";
        $_SESSION["flash_type"] = "error";
        redirectKnowledgePage();
    }
}

$editId = isset($_GET["edit"]) ? (int) $_GET["edit"] : 0;

if ($editId > 0 && $formErrors === []) {
    $editingEntry = gsoGetKnowledgeEntry($pdo, $editId);

    if ($editingEntry !== null) {
        $formValues = [
            "title" => (string) $editingEntry["title"],
            "category" => (string) $editingEntry["category"],
            "question" => (string) $editingEntry["question"],
            "content" => (string) $editingEntry["content"],
            "keywords" => (string) $editingEntry["keywords"],
            "status" => (string) $editingEntry["status"],
        ];
    }
}

$filters = [
    "category" => (string) ($_GET["category"] ?? "All"),
    "status" => (string) ($_GET["status"] ?? "All"),
    "search" => (string) ($_GET["search"] ?? ""),
];

$entries = gsoListKnowledgeEntries($pdo, $filters);
$activeCount = 0;

foreach ($entries as $row) {
    if (($row["status"] ?? "") === "Active") {
        $activeCount++;
    }
}

$flashMessage = (string) ($_SESSION["flash_message"] ?? "");
$flashType = (string) ($_SESSION["flash_type"] ?? "");
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>AI Knowledge Base</h2>
            <p>
                These entries are the only source the GSO Assistant may use to answer procedure questions.
                Anything not covered here, the assistant will say it does not know rather than guess.
            </p>
        </div>

        <?php if ($flashMessage !== ""): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($formErrors !== []): ?>
            <div class="flash-message flash-error">
                <?php echo htmlspecialchars(implode(" ", $formErrors)); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3><?php echo $editingEntry !== null ? "Edit entry" : "Add entry"; ?></h3>

            <form method="POST" class="ui-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="entry_id" value="<?php echo $editingEntry !== null ? (int) $editingEntry["entry_id"] : 0; ?>">

                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="160" required
                       value="<?php echo htmlspecialchars($formValues["title"], ENT_QUOTES, "UTF-8"); ?>">

                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <?php foreach (gsoAiKnowledgeCategories() as $category): ?>
                        <option value="<?php echo htmlspecialchars($category, ENT_QUOTES, "UTF-8"); ?>"
                            <?php echo $formValues["category"] === $category ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($category); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="question">Sample question</label>
                <input type="text" id="question" name="question" maxlength="255" required
                       placeholder="How do I borrow equipment?"
                       value="<?php echo htmlspecialchars($formValues["question"], ENT_QUOTES, "UTF-8"); ?>">

                <label for="content">Answer content</label>
                <textarea id="content" name="content" rows="6" maxlength="5000" required><?php echo htmlspecialchars($formValues["content"], ENT_QUOTES, "UTF-8"); ?></textarea>

                <label for="keywords">Keywords (comma separated)</label>
                <input type="text" id="keywords" name="keywords" maxlength="500"
                       placeholder="borrow, equipment, request"
                       value="<?php echo htmlspecialchars($formValues["keywords"], ENT_QUOTES, "UTF-8"); ?>">

                <label for="status">Status</label>
                <select id="status" name="status" required>
                    <?php foreach (gsoAiKnowledgeStatuses() as $status): ?>
                        <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?>"
                            <?php echo $formValues["status"] === $status ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($status); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $editingEntry !== null ? "Save changes" : "Add entry"; ?>
                    </button>
                    <?php if ($editingEntry !== null): ?>
                        <a class="btn" href="ai_knowledge.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h3>Entries (<?php echo count($entries); ?> total, <?php echo $activeCount; ?> active)</h3>

            <form method="GET" class="ui-filter-bar">
                <input type="text" name="search" placeholder="Search title, question, keywords"
                       value="<?php echo htmlspecialchars($filters["search"], ENT_QUOTES, "UTF-8"); ?>">

                <select name="category">
                    <option value="All">All categories</option>
                    <?php foreach (gsoAiKnowledgeCategories() as $category): ?>
                        <option value="<?php echo htmlspecialchars($category, ENT_QUOTES, "UTF-8"); ?>"
                            <?php echo $filters["category"] === $category ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($category); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status">
                    <option value="All">All statuses</option>
                    <?php foreach (gsoAiKnowledgeStatuses() as $status): ?>
                        <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?>"
                            <?php echo $filters["status"] === $status ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($status); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn">Filter</button>
            </form>

            <?php if ($entries === []): ?>
                <p>No knowledge entries match this filter.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Sample question</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $row["title"]); ?></td>
                                <td><?php echo htmlspecialchars((string) $row["category"]); ?></td>
                                <td><?php echo htmlspecialchars((string) $row["question"]); ?></td>
                                <td>
                                    <span class="status-badge">
                                        <?php echo htmlspecialchars((string) $row["status"]); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars((string) $row["updated_at"]); ?></td>
                                <td class="table-actions">
                                    <a class="btn btn-sm" href="ai_knowledge.php?edit=<?php echo (int) $row["entry_id"]; ?>">Edit</a>

                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="entry_id" value="<?php echo (int) $row["entry_id"]; ?>">
                                        <button type="submit" class="btn btn-sm">
                                            <?php echo ((string) $row["status"]) === "Active" ? "Deactivate" : "Activate"; ?>
                                        </button>
                                    </form>

                                    <form method="POST" class="inline-form"
                                          onsubmit="return confirm('Delete this knowledge entry permanently?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="entry_id" value="<?php echo (int) $row["entry_id"]; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
