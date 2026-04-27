<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/resource_image_helper.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/maintenance_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Add Resource";
$activePage = "inventory";
$cssFile = "../assets/css/admin.css";

$message = "";
$messageType = "";

$formData = [
    "resource_name"   => "",
    "resource_type"   => "Item",
    "category"        => "",
    "description"     => "",
    "resource_image"  => "",
    "location"        => "",
    "total_stock"     => "",
    "available_stock" => "",
    "capacity"        => "",
    "status"          => "Available",
    "condition_status" => "Good",
    "condition_notes" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $message = "Invalid request token.";
        $messageType = "error";
    } else {
        $formData["resource_name"]   = trim($_POST["resource_name"] ?? "");
        $formData["resource_type"]   = trim($_POST["resource_type"] ?? "Item");
        $formData["category"]        = trim($_POST["category"] ?? "");
        $formData["description"]     = trim($_POST["description"] ?? "");
        $formData["resource_image"]  = "";
        $formData["location"]        = trim($_POST["location"] ?? "");
        $formData["total_stock"]     = trim($_POST["total_stock"] ?? "");
        $formData["available_stock"] = trim($_POST["available_stock"] ?? "");
        $formData["capacity"]        = trim($_POST["capacity"] ?? "");
        $formData["status"]          = trim($_POST["status"] ?? "Available");
        $formData["condition_status"] = trim($_POST["condition_status"] ?? "Good");
        $formData["condition_notes"] = trim($_POST["condition_notes"] ?? "");

        $allowedTypes = ["Item", "Facility"];
        $allowedStatuses = ["Available", "Unavailable", "Maintenance"];
        $allowedConditions = getConditionStatusOptions();

        if ($formData["resource_name"] === "") {
            $message = "Resource name is required.";
            $messageType = "error";
        } elseif (!in_array($formData["resource_type"], $allowedTypes, true)) {
            $message = "Invalid resource type.";
            $messageType = "error";
        } elseif (!in_array($formData["status"], $allowedStatuses, true)) {
            $message = "Invalid resource status.";
            $messageType = "error";
        } elseif (!in_array($formData["condition_status"], $allowedConditions, true)) {
            $message = "Invalid resource condition status.";
            $messageType = "error";
        } else {
            $totalStock = null;
            $availableStock = null;
            $capacity = null;
            $finalStatus = $formData["status"];

            if ($formData["total_stock"] !== "") {
                if (!ctype_digit($formData["total_stock"])) {
                    $message = "Total stock must be a whole number.";
                    $messageType = "error";
                } else {
                    $totalStock = (int) $formData["total_stock"];
                }
            }

            if ($message === "" && $formData["available_stock"] !== "") {
                if (!ctype_digit($formData["available_stock"])) {
                    $message = "Available stock must be a whole number.";
                    $messageType = "error";
                } else {
                    $availableStock = (int) $formData["available_stock"];
                }
            }

            if ($message === "" && $formData["capacity"] !== "") {
                if (!ctype_digit($formData["capacity"])) {
                    $message = "Capacity must be a whole number.";
                    $messageType = "error";
                } else {
                    $capacity = (int) $formData["capacity"];
                }
            }

            if ($message === "" && $formData["resource_type"] === "Item") {
                if ($totalStock === null) {
                    $message = "Total stock is required for item resources.";
                    $messageType = "error";
                } else {
                    if ($availableStock === null) {
                        $availableStock = $totalStock;
                    }

                    if ($availableStock > $totalStock) {
                        $message = "Available stock cannot be greater than total stock.";
                        $messageType = "error";
                    }

                    if ($availableStock < 0) {
                        $message = "Available stock cannot be negative.";
                        $messageType = "error";
                    }
                }

                $capacity = null;

                // Auto-update status from stock unless admin explicitly set Maintenance
                if ($message === "" && $finalStatus !== "Maintenance") {
                    $finalStatus = ((int) $availableStock > 0) ? "Available" : "Unavailable";
                }
            }

            if ($message === "" && $formData["resource_type"] === "Facility") {
                if ($capacity === null) {
                    $message = "Capacity is required for facility resources.";
                    $messageType = "error";
                }

                if ($totalStock === null && $availableStock === null) {
                    $totalStock = null;
                    $availableStock = null;
                } elseif ($totalStock !== null && $availableStock === null) {
                    $availableStock = $totalStock;
                } elseif ($totalStock !== null && $availableStock !== null && $availableStock > $totalStock) {
                    $message = "Available stock cannot be greater than total stock.";
                    $messageType = "error";
                }
            }

            if ($message === "") {
                $checkStmt = $pdo->prepare("
                    SELECT resource_id
                    FROM resources
                    WHERE resource_name = :resource_name
                      AND resource_type = :resource_type
                    LIMIT 1
                ");
                $checkStmt->execute([
                    ":resource_name" => $formData["resource_name"],
                    ":resource_type" => $formData["resource_type"]
                ]);

                if ($checkStmt->fetch()) {
                    $message = "A resource with the same name and type already exists.";
                    $messageType = "error";
                } else {
                    $uploadedImageName = null;

                    $uploadResult = uploadResourceImage($_FILES["resource_image"] ?? null);

                    if (!$uploadResult["success"]) {
                        $message = $uploadResult["message"];
                        $messageType = "error";
                    } else {
                        $uploadedImageName = $uploadResult["filename"];

                        if ($formData["condition_status"] !== "Good" && $finalStatus !== "Maintenance") {
                            $finalStatus = "Unavailable";
                        }

                        $insertStmt = $pdo->prepare("
                            INSERT INTO resources
                            (
                                resource_name,
                                resource_type,
                                category,
                                description,
                                resource_image,
                                location,
                                total_stock,
                                available_stock,
                                capacity,
                                status,
                                condition_status,
                                condition_notes
                            )
                            VALUES
                            (
                                :resource_name,
                                :resource_type,
                                :category,
                                :description,
                                :resource_image,
                                :location,
                                :total_stock,
                                :available_stock,
                                :capacity,
                                :status,
                                :condition_status,
                                :condition_notes
                            )
                        ");

                        $success = $insertStmt->execute([
                            ":resource_name"   => $formData["resource_name"],
                            ":resource_type"   => $formData["resource_type"],
                            ":category"        => $formData["category"] !== "" ? $formData["category"] : null,
                            ":description"     => $formData["description"] !== "" ? $formData["description"] : null,
                            ":resource_image"  => $uploadedImageName,
                            ":location"        => $formData["location"] !== "" ? $formData["location"] : null,
                            ":total_stock"     => $totalStock,
                            ":available_stock" => $availableStock,
                            ":capacity"        => $capacity,
                            ":status"          => $finalStatus,
                            ":condition_status" => $formData["condition_status"],
                            ":condition_notes" => $formData["condition_notes"] !== "" ? $formData["condition_notes"] : null
                        ]);

                        if ($success) {
                            addActivityLog(
                                $pdo,
                                (int) ($_SESSION["user_id"] ?? 0),
                                "Resource Added",
                                "Added resource: " . $formData["resource_name"]
                            );

                            $message = "Resource added successfully.";
                            $messageType = "success";

                            $formData = [
                                "resource_name"   => "",
                                "resource_type"   => "Item",
                                "category"        => "",
                                "description"     => "",
                                "resource_image"  => "",
                                "location"        => "",
                                "total_stock"     => "",
                                "available_stock" => "",
                                "capacity"        => "",
                                "status"          => "Available",
                                "condition_status" => "Good",
                                "condition_notes" => ""
                            ];
                        } else {
                            if (!empty($uploadedImageName)) {
                                deleteResourceImage($uploadedImageName);
                            }

                            $message = "Failed to add resource.";
                            $messageType = "error";
                        }
                    }
                }
            }
        }
    }
}

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>

    <main class="page-content settings-page">
        <section class="form-page-hero">
            <div>
                <span class="form-page-kicker">Inventory Setup</span>
                <h2>Add Resource</h2>
                <p>Create a polished inventory record with clearer sections for details, availability, condition, and media.</p>
            </div>

            <div class="form-hero-badge">
                <span class="form-hero-badge-label">Inventory action</span>
                <strong>New Resource</strong>
                <small>Item or Facility</small>
            </div>
        </section>

        <?php if (!empty($message)): ?>
            <div class="flash-message <?php echo $messageType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="form-page-layout resource-edit-layout">
            <aside class="card form-intro-card">
                <div class="form-intro-icon">
                    <i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i>
                </div>
                <h3>Build a complete resource record</h3>
                <p>Give admins and borrowers the details they need to understand what the resource is, where it belongs, and how it can be used.</p>
                <div class="form-intro-points">
                    <span><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Define type, category, and location</span>
                    <span><i class="fa-solid fa-warehouse" aria-hidden="true"></i> Record stock or facility capacity clearly</span>
                    <span><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> Save condition details and image support</span>
                </div>
            </aside>

            <div class="card request-form-card form-panel-card">
                <form method="POST" enctype="multipart/form-data" class="request-form settings-form-grid resource-form-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                    <div class="form-section-heading">
                        <h3>Core Details</h3>
                        <p>Start with the identity and classification of the resource.</p>
                    </div>

                    <div class="form-row form-row--full">
                        <label for="resource_name">Resource Name</label>
                        <input
                            type="text"
                            id="resource_name"
                            name="resource_name"
                            placeholder="Enter the resource name"
                            value="<?php echo htmlspecialchars($formData["resource_name"]); ?>"
                            required
                        >
                    </div>

                    <div class="form-row">
                        <label for="resource_type">Resource Type</label>
                        <select id="resource_type" name="resource_type" required>
                            <option value="Item" <?php echo $formData["resource_type"] === "Item" ? "selected" : ""; ?>>Item</option>
                            <option value="Facility" <?php echo $formData["resource_type"] === "Facility" ? "selected" : ""; ?>>Facility</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label for="category">Category</label>
                        <input
                            type="text"
                            id="category"
                            name="category"
                            placeholder="Enter a category"
                            value="<?php echo htmlspecialchars($formData["category"]); ?>"
                        >
                    </div>

                    <div class="form-row">
                        <label for="location">Location</label>
                        <input
                            type="text"
                            id="location"
                            name="location"
                            placeholder="Enter the storage or room location"
                            value="<?php echo htmlspecialchars($formData["location"]); ?>"
                        >
                    </div>

                    <div class="form-row">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <option value="Available" <?php echo $formData["status"] === "Available" ? "selected" : ""; ?>>Available</option>
                            <option value="Unavailable" <?php echo $formData["status"] === "Unavailable" ? "selected" : ""; ?>>Unavailable</option>
                            <option value="Maintenance" <?php echo $formData["status"] === "Maintenance" ? "selected" : ""; ?>>Maintenance</option>
                        </select>
                    </div>

                    <div class="form-row form-row--full">
                        <label for="description">Description</label>
                        <textarea
                            id="description"
                            name="description"
                            rows="4"
                            placeholder="Add a short description of the resource"
                        ><?php echo htmlspecialchars($formData["description"]); ?></textarea>
                    </div>

                    <div class="form-section-heading">
                        <h3>Availability Setup</h3>
                        <p>Enter the values that control item stock or facility capacity.</p>
                    </div>

                    <div class="form-row">
                        <label for="total_stock">Total Stock</label>
                        <input
                            type="number"
                            id="total_stock"
                            name="total_stock"
                            min="0"
                            value="<?php echo htmlspecialchars($formData["total_stock"]); ?>"
                        >
                    </div>

                    <div class="form-row">
                        <label for="available_stock">Available Stock</label>
                        <input
                            type="number"
                            id="available_stock"
                            name="available_stock"
                            min="0"
                            value="<?php echo htmlspecialchars($formData["available_stock"]); ?>"
                        >
                    </div>

                    <div class="form-row">
                        <label for="capacity">Capacity</label>
                        <input
                            type="number"
                            id="capacity"
                            name="capacity"
                            min="0"
                            value="<?php echo htmlspecialchars($formData["capacity"]); ?>"
                        >
                    </div>

                    <div class="form-section-heading">
                        <h3>Condition and Media</h3>
                        <p>Capture the current condition and optional image for better inventory clarity.</p>
                    </div>

                    <div class="form-row">
                        <label for="condition_status">Condition Status</label>
                        <select id="condition_status" name="condition_status" required>
                            <?php foreach (getConditionStatusOptions() as $conditionOption): ?>
                                <option value="<?php echo htmlspecialchars($conditionOption); ?>" <?php echo $formData["condition_status"] === $conditionOption ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($conditionOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row form-row--full">
                        <label for="resource_image">Resource Image</label>
                        <input
                            type="file"
                            id="resource_image"
                            name="resource_image"
                            accept=".jpg,.jpeg,.png,.gif,.webp"
                        >
                        <small class="form-help">Allowed: JPG, PNG, GIF, WEBP. Maximum file size: 5MB.</small>
                    </div>

                    <div class="form-row form-row--full">
                        <label for="condition_notes">Condition Notes</label>
                        <textarea id="condition_notes" name="condition_notes" rows="3" placeholder="Add optional condition notes"><?php echo htmlspecialchars($formData["condition_notes"]); ?></textarea>
                    </div>

                    <div class="profile-actions form-actions-bar">
                        <button type="submit" class="admin-btn primary-btn">Add Resource</button>
                        <a href="inventory.php" class="admin-btn info-btn">Back to Inventory</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

