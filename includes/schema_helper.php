<?php

if (!function_exists("getSchemaColumnMetadata")) {
    function getSchemaColumnMetadata(PDO $pdo, string $tableName, string $columnName): ?array
    {
        $stmt = $pdo->prepare("
            SELECT
                COLUMN_NAME,
                COLUMN_TYPE,
                DATA_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":column_name" => $columnName,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists("tableExists")) {
    function tableExists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
            LIMIT 1
        ");
        $stmt->execute([
            ":table_name" => $tableName
        ]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists("columnExists")) {
    function columnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        return getSchemaColumnMetadata($pdo, $tableName, $columnName) !== null;
    }
}

if (!function_exists("enumColumnHasValue")) {
    function enumColumnHasValue(PDO $pdo, string $tableName, string $columnName, string $value): bool
    {
        $column = getSchemaColumnMetadata($pdo, $tableName, $columnName);

        if ($column === null) {
            return false;
        }

        $type = (string) ($column["COLUMN_TYPE"] ?? "");

        return strpos($type, "'" . str_replace("'", "\\'", $value) . "'") !== false;
    }
}

if (!function_exists("ensureSystemSchema")) {
    function ensureSystemSchema(PDO $pdo): void
    {
        static $hasRun = false;

        if ($hasRun) {
            return;
        }

        $hasRun = true;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS maintenance_schedules (
                    maintenance_id INT(11) NOT NULL AUTO_INCREMENT,
                    resource_id INT(11) NOT NULL,
                    start_date DATE NOT NULL,
                    end_date DATE NOT NULL,
                    duration_days INT(11) NOT NULL DEFAULT 1,
                    reason VARCHAR(255) NOT NULL,
                    remarks VARCHAR(500) DEFAULT NULL,
                    status ENUM('Scheduled', 'In Progress', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Scheduled',
                    created_by INT(11) NOT NULL,
                    updated_by INT(11) DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL,
                    PRIMARY KEY (maintenance_id),
                    KEY idx_maintenance_resource (resource_id),
                    KEY idx_maintenance_status (status),
                    KEY idx_maintenance_dates (start_date, end_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            if (tableExists($pdo, "resource_requests")) {
                if (!enumColumnHasValue($pdo, "resource_requests", "status", "Under Review")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        MODIFY status ENUM('Pending', 'Under Review', 'Approved', 'Rejected', 'Cancelled', 'Released', 'Returned')
                            NOT NULL DEFAULT 'Pending'
                    ");
                }

                if (!columnExists($pdo, "resource_requests", "contact_number")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN contact_number VARCHAR(30) NULL
                        AFTER quantity
                    ");
                }

                if (!columnExists($pdo, "resource_requests", "last_reminded_at")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN last_reminded_at DATETIME NULL
                        AFTER notes
                    ");
                }

                if (!columnExists($pdo, "resource_requests", "reminder_count")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN reminder_count INT(11) NOT NULL DEFAULT 0
                        AFTER last_reminded_at
                    ");
                }
            }

            if (tableExists($pdo, "resources")) {
                if (!columnExists($pdo, "resources", "condition_status")) {
                    $pdo->exec("
                        ALTER TABLE resources
                        ADD COLUMN condition_status ENUM('Good', 'Damaged', 'Missing Parts', 'Needs Repair', 'Lost')
                            NOT NULL DEFAULT 'Good'
                        AFTER status
                    ");
                }

                if (!columnExists($pdo, "resources", "condition_notes")) {
                    $pdo->exec("
                        ALTER TABLE resources
                        ADD COLUMN condition_notes VARCHAR(500) NULL
                        AFTER condition_status
                    ");
                }
            }

            if (tableExists($pdo, "return_submissions")) {
                if (!columnExists($pdo, "return_submissions", "reported_condition")) {
                    $pdo->exec("
                        ALTER TABLE return_submissions
                        ADD COLUMN reported_condition ENUM('Good', 'Damaged', 'Missing Parts', 'Needs Repair', 'Lost') NULL
                        AFTER condition_notes
                    ");
                }

                if (!columnExists($pdo, "return_submissions", "inspection_condition")) {
                    $pdo->exec("
                        ALTER TABLE return_submissions
                        ADD COLUMN inspection_condition ENUM('Good', 'Damaged', 'Missing Parts', 'Needs Repair', 'Lost') NULL
                        AFTER admin_notes
                    ");
                }

                if (!columnExists($pdo, "return_submissions", "inspection_remarks")) {
                    $pdo->exec("
                        ALTER TABLE return_submissions
                        ADD COLUMN inspection_remarks VARCHAR(500) NULL
                        AFTER inspection_condition
                    ");
                }
            }

            if (tableExists($pdo, "users")) {
                if (!columnExists($pdo, "users", "profile_image")) {
                    $pdo->exec("
                        ALTER TABLE users
                        ADD COLUMN profile_image LONGBLOB NULL
                        AFTER uploaded_id_type
                    ");
                }

                if (!columnExists($pdo, "users", "profile_image_type")) {
                    $pdo->exec("
                        ALTER TABLE users
                        ADD COLUMN profile_image_type VARCHAR(100) NULL
                        AFTER profile_image
                    ");
                }
            }
        } catch (Throwable $e) {
            $hasRun = false;
            throw new RuntimeException("Schema initialization failed: " . $e->getMessage(), 0, $e);
        }
    }
}
