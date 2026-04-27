<?php
require_once __DIR__ . "/../config/db.php";

$columnStmt = $pdo->query("SHOW COLUMNS FROM resource_requests LIKE 'overdue'");
$overdueColumn = $columnStmt->fetch(PDO::FETCH_ASSOC);

if (!$overdueColumn) {
    echo "No overdue column found in resource_requests. Current overdue status is computed from due dates and request status.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("
        UPDATE resource_requests
        SET overdue = 0
        WHERE overdue <> 0
           OR overdue IS NULL
    ");
    $updateStmt->execute();

    $affectedRows = $updateStmt->rowCount();
    $pdo->commit();

    echo "Reset overdue values to 0 for {$affectedRows} record(s).\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Failed to reset overdue values: " . $e->getMessage() . "\n");
    exit(1);
}
