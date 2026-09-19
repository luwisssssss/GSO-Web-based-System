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

if (!function_exists("foreignKeyExists")) {
    function foreignKeyExists(PDO $pdo, string $tableName, string $constraintName): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND CONSTRAINT_NAME = :constraint_name
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            LIMIT 1
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":constraint_name" => $constraintName,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists("constraintExists")) {
    /** CHECK constraints are not foreign keys, so they need their own lookup. */
    function constraintExists(PDO $pdo, string $tableName, string $constraintName): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND CONSTRAINT_NAME = :constraint_name
            LIMIT 1
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":constraint_name" => $constraintName,
        ]);

        return (bool) $stmt->fetchColumn();
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
                CREATE TABLE IF NOT EXISTS login_attempts (
                    attempt_id BIGINT(20) NOT NULL AUTO_INCREMENT,
                    attempt_key VARCHAR(190) NOT NULL,
                    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (attempt_id),
                    KEY idx_login_attempts_key_time (attempt_key, attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // login_attempts holds ephemeral throttle state only. Keys are a
            // keyed HMAC and are always 64 hex characters, so anything else is
            // a raw identifier from an older build and must not be retained.
            $pdo->exec("DELETE FROM login_attempts WHERE attempt_key NOT REGEXP '^[0-9a-f]{64}$'");

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

            // --- AI chatbot foundation (Step 9) -------------------------------
            // Conversations and messages are scoped to one authenticated user.
            // The knowledge base holds the only content the assistant may answer
            // GSO procedure questions from.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_conversations (
                    conversation_id BIGINT(20) NOT NULL AUTO_INCREMENT,
                    user_id INT(11) NOT NULL,
                    title VARCHAR(160) NOT NULL DEFAULT 'New conversation',
                    status ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active',
                    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    ended_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (conversation_id),
                    KEY idx_ai_conversations_user (user_id, status, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_messages (
                    message_id BIGINT(20) NOT NULL AUTO_INCREMENT,
                    conversation_id BIGINT(20) NOT NULL,
                    sender_type ENUM('user', 'assistant', 'system') NOT NULL,
                    message MEDIUMTEXT NOT NULL,
                    intent VARCHAR(60) DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (message_id),
                    KEY idx_ai_messages_conversation (conversation_id, message_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_knowledge_entries (
                    entry_id INT(11) NOT NULL AUTO_INCREMENT,
                    title VARCHAR(160) NOT NULL,
                    category VARCHAR(60) NOT NULL DEFAULT 'General',
                    question VARCHAR(255) NOT NULL,
                    content TEXT NOT NULL,
                    keywords VARCHAR(500) NOT NULL DEFAULT '',
                    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
                    created_by INT(11) DEFAULT NULL,
                    updated_by INT(11) DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (entry_id),
                    KEY idx_ai_knowledge_status (status, category)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            if (tableExists($pdo, "users")
                && !foreignKeyExists($pdo, "ai_conversations", "fk_ai_conversations_user")
            ) {
                $pdo->exec("
                    ALTER TABLE ai_conversations
                    ADD CONSTRAINT fk_ai_conversations_user
                        FOREIGN KEY (user_id) REFERENCES users (user_id)
                        ON DELETE CASCADE
                ");
            }

            if (!foreignKeyExists($pdo, "ai_messages", "fk_ai_messages_conversation")) {
                $pdo->exec("
                    ALTER TABLE ai_messages
                    ADD CONSTRAINT fk_ai_messages_conversation
                        FOREIGN KEY (conversation_id) REFERENCES ai_conversations (conversation_id)
                        ON DELETE CASCADE
                ");
            }

            if (tableExists($pdo, "users")
                && !foreignKeyExists($pdo, "ai_knowledge_entries", "fk_ai_knowledge_created_by")
            ) {
                $pdo->exec("
                    ALTER TABLE ai_knowledge_entries
                    ADD CONSTRAINT fk_ai_knowledge_created_by
                        FOREIGN KEY (created_by) REFERENCES users (user_id)
                        ON DELETE SET NULL
                ");
            }

            if (tableExists($pdo, "users")
                && !foreignKeyExists($pdo, "ai_knowledge_entries", "fk_ai_knowledge_updated_by")
            ) {
                $pdo->exec("
                    ALTER TABLE ai_knowledge_entries
                    ADD CONSTRAINT fk_ai_knowledge_updated_by
                        FOREIGN KEY (updated_by) REFERENCES users (user_id)
                        ON DELETE SET NULL
                ");
            }
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

                if (!columnExists($pdo, "resource_requests", "released_by")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN released_by INT(11) NULL
                        AFTER reviewed_by
                    ");
                }

                if (!columnExists($pdo, "resource_requests", "released_at")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN released_at DATETIME NULL
                        AFTER reviewed_at
                    ");
                }

                $pdo->exec("
                    UPDATE resource_requests
                    SET
                        reviewed_by = COALESCE(reviewed_by, approved_by),
                        reviewed_at = COALESCE(reviewed_at, approved_at)
                    WHERE status IN ('Approved', 'Rejected', 'Released', 'Returned')
                      AND (reviewed_by IS NULL OR reviewed_at IS NULL)
                ");
                $pdo->exec("
                    UPDATE resource_requests
                    SET
                        released_by = COALESCE(released_by, approved_by),
                        released_at = COALESCE(released_at, approved_at)
                    WHERE status IN ('Released', 'Returned')
                      AND (released_by IS NULL OR released_at IS NULL)
                ");
            }

            if (tableExists($pdo, "resources")) {
                if (!columnExists($pdo, "resources", "under_repair_stock")) {
                    $pdo->exec("
                        ALTER TABLE resources
                        ADD COLUMN under_repair_stock INT(11) NOT NULL DEFAULT 0
                        AFTER available_stock
                    ");
                }
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
                $returnStatusColumn = getSchemaColumnMetadata($pdo, "return_submissions", "status");
                $returnStatusType = (string) ($returnStatusColumn["COLUMN_TYPE"] ?? "");
                $hasPendingReturnStatus = strpos($returnStatusType, "'Pending'") !== false;
                $hasLegacyUnderReviewStatus = strpos($returnStatusType, "'Under Review'") !== false;

                if (!$hasPendingReturnStatus || $hasLegacyUnderReviewStatus) {
                    // Older installations called a newly submitted return "Under Review".
                    // Keep both values temporarily so existing rows can be converted without data loss.
                    $pdo->exec("
                        ALTER TABLE return_submissions
                        MODIFY status ENUM('Pending', 'Under Review', 'Approved', 'Rejected')
                            NOT NULL DEFAULT 'Pending'
                    ");
                    $pdo->exec("
                        UPDATE return_submissions
                        SET status = 'Pending'
                        WHERE status = 'Under Review'
                    ");
                    $pdo->exec("
                        ALTER TABLE return_submissions
                        MODIFY status ENUM('Pending', 'Approved', 'Rejected')
                            NOT NULL DEFAULT 'Pending'
                    ");
                }

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

            if (tableExists($pdo, "users")
                && !foreignKeyExists($pdo, "users", "fk_users_approved_by")
            ) {
                $pdo->exec("
                    ALTER TABLE users
                    ADD CONSTRAINT fk_users_approved_by
                        FOREIGN KEY (approved_by) REFERENCES users (user_id)
                        ON DELETE SET NULL
                ");
            }

            if (tableExists($pdo, "resource_requests")
                && !foreignKeyExists($pdo, "resource_requests", "fk_rr_reviewed_by")
            ) {
                $pdo->exec("
                    ALTER TABLE resource_requests
                    ADD CONSTRAINT fk_rr_reviewed_by
                        FOREIGN KEY (reviewed_by) REFERENCES users (user_id)
                        ON DELETE SET NULL
                ");
            }

            if (tableExists($pdo, "resource_requests")
                && !foreignKeyExists($pdo, "resource_requests", "fk_rr_released_by")
            ) {
                $pdo->exec("
                    ALTER TABLE resource_requests
                    ADD CONSTRAINT fk_rr_released_by
                        FOREIGN KEY (released_by) REFERENCES users (user_id)
                        ON DELETE SET NULL
                ");
            }

            // --- Adviser endorsement workflow (Phase 2) -----------------------
            // Phase 2 routing depends on the Phase 1 authorization vocabulary,
            // so the enums are widened first. Widening never reclassifies an
            // existing account: legacy Borrowers keep a NULL account_type.
            if (tableExists($pdo, "users")) {
                if (!enumColumnHasValue($pdo, "users", "role", "Director")) {
                    $pdo->exec("
                        ALTER TABLE users
                        MODIFY COLUMN role
                            ENUM('Admin','Borrower','GSO Staff','Director')
                            NOT NULL DEFAULT 'Borrower'
                    ");
                }

                if (!columnExists($pdo, "users", "account_type")) {
                    $pdo->exec("
                        ALTER TABLE users
                        ADD COLUMN account_type
                            ENUM('Student','Faculty','University Staff') NULL
                            AFTER role
                    ");
                }
            }

            // Current routing state lives on the request; every submission and
            // adviser decision is appended to request_adviser_history so a
            // later endorsement can never overwrite an earlier rejection.
            if (tableExists($pdo, "resource_requests")) {
                if (!columnExists($pdo, "resource_requests", "adviser_state")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN adviser_state
                            ENUM('Not Required','Selection Required','Pending Adviser','Revision Required','Endorsed')
                            NOT NULL DEFAULT 'Not Required'
                            AFTER status
                    ");
                }

                if (!columnExists($pdo, "resource_requests", "adviser_user_id")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN adviser_user_id INT(11) NULL AFTER adviser_state
                    ");
                }

                if (!columnExists($pdo, "resource_requests", "adviser_required_by")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN adviser_required_by INT(11) NULL AFTER adviser_user_id
                    ");
                }

                if (!columnExists($pdo, "resource_requests", "adviser_required_at")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN adviser_required_at DATETIME NULL AFTER adviser_required_by
                    ");
                }

                if (!foreignKeyExists($pdo, "resource_requests", "fk_rr_adviser_user")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD KEY idx_rr_adviser_queue (adviser_user_id, adviser_state),
                        ADD CONSTRAINT fk_rr_adviser_user
                            FOREIGN KEY (adviser_user_id) REFERENCES users (user_id)
                            ON DELETE RESTRICT
                    ");
                }

                if (!foreignKeyExists($pdo, "resource_requests", "fk_rr_adviser_required_by")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD CONSTRAINT fk_rr_adviser_required_by
                            FOREIGN KEY (adviser_required_by) REFERENCES users (user_id)
                            ON DELETE SET NULL
                    ");
                }
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS request_adviser_history (
                    history_id BIGINT(20) NOT NULL AUTO_INCREMENT,
                    request_id INT(11) NOT NULL,
                    adviser_user_id INT(11) NOT NULL,
                    cycle_no INT(11) NOT NULL,
                    action ENUM('Submitted','Endorsed','Returned for Revision') NOT NULL,
                    remarks VARCHAR(1000) NULL,
                    acted_by INT(11) NOT NULL,
                    acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (history_id),
                    KEY idx_adviser_history_request (request_id, acted_at, history_id),
                    KEY idx_adviser_history_adviser (adviser_user_id, acted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            if (tableExists($pdo, "request_adviser_history")) {
                if (!foreignKeyExists($pdo, "request_adviser_history", "fk_adviser_history_request")) {
                    $pdo->exec("
                        ALTER TABLE request_adviser_history
                        ADD CONSTRAINT fk_adviser_history_request
                            FOREIGN KEY (request_id) REFERENCES resource_requests (request_id)
                            ON DELETE CASCADE
                    ");
                }

                if (!foreignKeyExists($pdo, "request_adviser_history", "fk_adviser_history_adviser")) {
                    $pdo->exec("
                        ALTER TABLE request_adviser_history
                        ADD CONSTRAINT fk_adviser_history_adviser
                            FOREIGN KEY (adviser_user_id) REFERENCES users (user_id)
                            ON DELETE RESTRICT
                    ");
                }

                if (!foreignKeyExists($pdo, "request_adviser_history", "fk_adviser_history_actor")) {
                    $pdo->exec("
                        ALTER TABLE request_adviser_history
                        ADD CONSTRAINT fk_adviser_history_actor
                            FOREIGN KEY (acted_by) REFERENCES users (user_id)
                            ON DELETE RESTRICT
                    ");
                }
            }

            // --- Campus Director approval workflow (Phase 3) ---------------
            // The request stores only its current routing state. Director
            // decisions, including every member of a prioritisation outcome,
            // are immutable events in request_director_history.
            if (tableExists($pdo, "resource_requests")) {
                if (!columnExists($pdo, "resource_requests", "director_state")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN director_state
                            ENUM('Not Required','Pending','Approved','Rejected')
                            NOT NULL DEFAULT 'Not Required'
                            AFTER adviser_required_at
                    ");
                }

                if (!columnExists($pdo, "resource_requests", "director_required_by")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN director_required_by INT(11) NULL AFTER director_state
                    ");
                }

                if (!columnExists($pdo, "resource_requests", "director_required_at")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD COLUMN director_required_at DATETIME NULL AFTER director_required_by
                    ");
                }

                if (!foreignKeyExists($pdo, "resource_requests", "fk_rr_director_required_by")) {
                    $pdo->exec("
                        ALTER TABLE resource_requests
                        ADD KEY idx_rr_director_queue (director_state, status, director_required_at),
                        ADD CONSTRAINT fk_rr_director_required_by
                            FOREIGN KEY (director_required_by) REFERENCES users (user_id)
                            ON DELETE SET NULL
                    ");
                }
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS request_director_history (
                    director_history_id BIGINT(20) NOT NULL AUTO_INCREMENT,
                    request_id INT(11) NOT NULL,
                    director_user_id INT(11) NOT NULL,
                    action ENUM('Approved','Rejected','Prioritized','Rejected by Prioritization') NOT NULL,
                    remarks VARCHAR(1000) NULL,
                    decision_batch CHAR(36) NULL,
                    winner_request_id INT(11) NULL,
                    decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (director_history_id),
                    KEY idx_director_history_request (request_id, decided_at, director_history_id),
                    KEY idx_director_history_actor (director_user_id, decided_at),
                    KEY idx_director_history_batch (decision_batch, director_history_id),
                    KEY idx_director_history_winner (winner_request_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            if (tableExists($pdo, "request_director_history")) {
                if (!foreignKeyExists($pdo, "request_director_history", "fk_director_history_request")) {
                    $pdo->exec("
                        ALTER TABLE request_director_history
                        ADD CONSTRAINT fk_director_history_request
                            FOREIGN KEY (request_id) REFERENCES resource_requests (request_id)
                            ON DELETE CASCADE
                    ");
                }
                if (!foreignKeyExists($pdo, "request_director_history", "fk_director_history_actor")) {
                    $pdo->exec("
                        ALTER TABLE request_director_history
                        ADD CONSTRAINT fk_director_history_actor
                            FOREIGN KEY (director_user_id) REFERENCES users (user_id)
                            ON DELETE RESTRICT
                    ");
                }
                if (!foreignKeyExists($pdo, "request_director_history", "fk_director_history_winner")) {
                    $pdo->exec("
                        ALTER TABLE request_director_history
                        ADD CONSTRAINT fk_director_history_winner
                            FOREIGN KEY (winner_request_id) REFERENCES resource_requests (request_id)
                            ON DELETE SET NULL
                    ");
                }
            }

            // --- Legacy settings table (Phase 3.5-Prep) ----------------------
            // system_settings predates ensureSystemSchema() and existed only in
            // the live database. It is recreated here so a fresh install matches
            // development. The guard helpers above remain the single migration
            // mechanism: nothing reads schema_version, and adding a second
            // version marker would create competing schema-version systems.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS system_settings (
                    setting_key VARCHAR(64) NOT NULL,
                    setting_value VARCHAR(255) NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (setting_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");

            // --- Combined borrowing request lines (Phase 3.5A) ---------------
            // One request holds many resource lines: at most one facility plus
            // any number of equipment and consumable lines. resource_requests
            // stays the authoritative request header and keeps its adviser and
            // director routing; request_items carries the resources.
            if (tableExists($pdo, "resources")) {
                if (!columnExists($pdo, "resources", "tracking_mode")) {
                    $pdo->exec("
                        ALTER TABLE resources
                        ADD COLUMN tracking_mode
                            ENUM('Facility','Serialized','Aggregate','Consumable')
                            NOT NULL DEFAULT 'Aggregate'
                            AFTER resource_type
                    ");
                }

                // Existing facilities classify as Facility; every existing item
                // stays aggregate. Serialized and Consumable are assigned by GSO
                // once real units or stock are registered (Phase 3.5B).
                $pdo->exec("
                    UPDATE resources SET tracking_mode = 'Facility'
                    WHERE resource_type = 'Facility' AND tracking_mode <> 'Facility'
                ");
                $pdo->exec("
                    UPDATE resources SET tracking_mode = 'Aggregate'
                    WHERE resource_type = 'Item' AND tracking_mode = 'Facility'
                ");

                if (!constraintExists($pdo, "resources", "chk_resources_tracking_mode")) {
                    $pdo->exec("
                        ALTER TABLE resources
                        ADD CONSTRAINT chk_resources_tracking_mode CHECK (
                            (resource_type = 'Facility' AND tracking_mode = 'Facility')
                            OR (resource_type = 'Item' AND tracking_mode IN ('Serialized','Aggregate','Consumable'))
                        )
                    ");
                }
            }

            // facility_guard is NULL on every non-facility line, so its UNIQUE
            // index allows many equipment lines and at most one facility line
            // per request. Server-side validation still rejects a second
            // facility first; this index is the authoritative backstop.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS request_items (
                    request_item_id BIGINT NOT NULL AUTO_INCREMENT,
                    request_id INT NOT NULL,
                    resource_id INT NOT NULL,
                    line_kind ENUM('Facility','Equipment','Consumable') NOT NULL,
                    requested_quantity INT NOT NULL DEFAULT 1,
                    released_quantity INT NOT NULL DEFAULT 0,
                    returned_quantity INT NOT NULL DEFAULT 0,
                    issued_quantity INT NOT NULL DEFAULT 0,
                    line_notes VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    facility_guard INT AS (CASE WHEN line_kind = 'Facility' THEN request_id END) STORED,
                    PRIMARY KEY (request_item_id),
                    UNIQUE KEY uq_request_items_resource (request_id, resource_id),
                    UNIQUE KEY uq_request_items_one_facility (facility_guard),
                    KEY idx_request_items_resource (resource_id, request_id),
                    KEY idx_request_items_kind (request_id, line_kind),
                    CONSTRAINT chk_request_items_quantities CHECK (
                        requested_quantity >= 1
                        AND released_quantity >= 0 AND released_quantity <= requested_quantity
                        AND returned_quantity >= 0 AND returned_quantity <= released_quantity
                        AND issued_quantity >= 0 AND issued_quantity <= requested_quantity
                    )
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            if (tableExists($pdo, "request_items")) {
                if (!foreignKeyExists($pdo, "request_items", "fk_request_items_request")) {
                    $pdo->exec("
                        ALTER TABLE request_items
                        ADD CONSTRAINT fk_request_items_request
                            FOREIGN KEY (request_id) REFERENCES resource_requests (request_id)
                            ON DELETE CASCADE
                    ");
                }
                if (!foreignKeyExists($pdo, "request_items", "fk_request_items_resource")) {
                    $pdo->exec("
                        ALTER TABLE request_items
                        ADD CONSTRAINT fk_request_items_resource
                            FOREIGN KEY (resource_id) REFERENCES resources (resource_id)
                            ON DELETE RESTRICT
                    ");
                }

                // Historical backfill: exactly one line per legacy request. The
                // NOT EXISTS guard makes a repeated run a no-op, and no
                // `resources` row is written, so stock can never be recomputed,
                // re-issued or double-deducted by this backfill.
                $pdo->exec("
                    INSERT INTO request_items
                        (request_id, resource_id, line_kind, requested_quantity,
                         released_quantity, returned_quantity, created_at)
                    SELECT
                        rr.request_id,
                        rr.resource_id,
                        CASE WHEN r.resource_type = 'Facility' THEN 'Facility' ELSE 'Equipment' END,
                        GREATEST(1, COALESCE(rr.quantity, 1)),
                        CASE WHEN rr.status IN ('Released','Returned') THEN GREATEST(1, COALESCE(rr.quantity, 1)) ELSE 0 END,
                        CASE WHEN rr.status = 'Returned' THEN GREATEST(1, COALESCE(rr.quantity, 1)) ELSE 0 END,
                        rr.request_date
                    FROM resource_requests rr
                    INNER JOIN resources r ON r.resource_id = rr.resource_id
                    WHERE NOT EXISTS (
                        SELECT 1 FROM request_items ri WHERE ri.request_id = rr.request_id
                    )
                ");
            }

            // --- Mixed equipment tracking and return details (Phase 3.5B) ----
            // equipment_units starts empty on purpose: physical units exist
            // only once authorised GSO staff register real asset tags. No
            // unit is ever fabricated from an aggregate quantity.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS equipment_units (
                    unit_id BIGINT NOT NULL AUTO_INCREMENT,
                    resource_id INT NOT NULL,
                    asset_tag VARCHAR(60) NOT NULL,
                    serial_number VARCHAR(120) NULL,
                    status ENUM('Available','Released','Under Repair','Lost','Retired') NOT NULL DEFAULT 'Available',
                    condition_status ENUM('Good','Damaged','Missing Parts','Needs Repair','Lost') NOT NULL DEFAULT 'Good',
                    unit_notes VARCHAR(500) NULL,
                    acquired_at DATE NULL,
                    registered_by INT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL,
                    PRIMARY KEY (unit_id),
                    UNIQUE KEY uq_equipment_units_asset_tag (asset_tag),
                    UNIQUE KEY uq_equipment_units_serial (serial_number),
                    KEY idx_equipment_units_resource (resource_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            if (tableExists($pdo, "equipment_units")) {
                if (!foreignKeyExists($pdo, "equipment_units", "fk_equipment_units_resource")) {
                    $pdo->exec("
                        ALTER TABLE equipment_units
                        ADD CONSTRAINT fk_equipment_units_resource
                            FOREIGN KEY (resource_id) REFERENCES resources (resource_id)
                            ON DELETE RESTRICT
                    ");
                }
                if (!foreignKeyExists($pdo, "equipment_units", "fk_equipment_units_registrar")) {
                    $pdo->exec("
                        ALTER TABLE equipment_units
                        ADD CONSTRAINT fk_equipment_units_registrar
                            FOREIGN KEY (registered_by) REFERENCES users (user_id)
                            ON DELETE SET NULL
                    ");
                }
            }

            // open_unit_guard carries the unit id only while the allocation is
            // still open, so its UNIQUE index makes a second concurrent
            // release of the same physical unit impossible at the storage
            // engine level. Allocation history is never deleted.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS request_item_units (
                    allocation_id BIGINT NOT NULL AUTO_INCREMENT,
                    request_item_id BIGINT NOT NULL,
                    unit_id BIGINT NOT NULL,
                    allocated_by INT NOT NULL,
                    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    released_at DATETIME NULL,
                    returned_at DATETIME NULL,
                    open_unit_guard BIGINT AS (CASE WHEN returned_at IS NULL THEN unit_id END) STORED,
                    PRIMARY KEY (allocation_id),
                    UNIQUE KEY uq_request_item_units_pair (request_item_id, unit_id),
                    UNIQUE KEY uq_request_item_units_open (open_unit_guard),
                    KEY idx_request_item_units_unit (unit_id, returned_at),
                    CONSTRAINT fk_request_item_units_line FOREIGN KEY (request_item_id) REFERENCES request_items (request_item_id) ON DELETE CASCADE,
                    CONSTRAINT fk_request_item_units_unit FOREIGN KEY (unit_id) REFERENCES equipment_units (unit_id) ON DELETE RESTRICT,
                    CONSTRAINT fk_request_item_units_actor FOREIGN KEY (allocated_by) REFERENCES users (user_id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // One condition group per aggregate line, or one row per physical
            // unit. UNIQUE (return_id, unit_id) relies on NULLs being distinct
            // so aggregates may hold several groups while a unit appears once.
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS return_submission_items (
                    return_item_id BIGINT NOT NULL AUTO_INCREMENT,
                    return_id INT NOT NULL,
                    request_item_id BIGINT NOT NULL,
                    unit_id BIGINT NULL,
                    reported_condition ENUM('Good','Damaged','Missing Parts','Needs Repair','Lost') NULL,
                    inspection_condition ENUM('Good','Damaged','Missing Parts','Needs Repair','Lost') NULL,
                    returned_quantity INT NOT NULL DEFAULT 0,
                    lost_quantity INT NOT NULL DEFAULT 0,
                    inspection_remarks VARCHAR(500) NULL,
                    inspected_by INT NULL,
                    inspected_at DATETIME NULL,
                    stock_applied TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (return_item_id),
                    UNIQUE KEY uq_return_items_unit (return_id, unit_id),
                    KEY idx_return_items_line (request_item_id, return_id),
                    CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES return_submissions (return_id) ON DELETE CASCADE,
                    CONSTRAINT fk_return_items_line FOREIGN KEY (request_item_id) REFERENCES request_items (request_item_id) ON DELETE RESTRICT,
                    CONSTRAINT fk_return_items_unit FOREIGN KEY (unit_id) REFERENCES equipment_units (unit_id) ON DELETE RESTRICT,
                    CONSTRAINT fk_return_items_inspector FOREIGN KEY (inspected_by) REFERENCES users (user_id) ON DELETE SET NULL,
                    CONSTRAINT chk_return_items_quantities CHECK (
                        returned_quantity >= 0
                        AND lost_quantity >= 0
                        AND (returned_quantity + lost_quantity) >= 1
                        AND (unit_id IS NULL OR (returned_quantity + lost_quantity) = 1)
                    )
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // A consumable-only request is finished once its materials are
            // issued; calling that 'Returned' would claim goods came back.
            if (tableExists($pdo, "resource_requests")
                && !enumColumnHasValue($pdo, "resource_requests", "status", "Completed")
            ) {
                $pdo->exec("
                    ALTER TABLE resource_requests
                    MODIFY COLUMN status
                        ENUM('Pending','Under Review','Approved','Rejected','Cancelled','Released','Returned','Completed')
                        NOT NULL DEFAULT 'Pending'
                ");
            }

            // Historical returns become aggregate detail rows with
            // stock_applied already set, so the new inspection path can never
            // re-apply a stock movement the old header-level path made.
            if (tableExists($pdo, "return_submission_items") && tableExists($pdo, "request_items")) {
                $pdo->exec("
                    INSERT INTO return_submission_items
                        (return_id, request_item_id, unit_id, reported_condition, inspection_condition,
                         returned_quantity, lost_quantity, inspection_remarks, inspected_by, inspected_at,
                         stock_applied, created_at)
                    SELECT
                        rs.return_id,
                        ri.request_item_id,
                        NULL,
                        rs.reported_condition,
                        rs.inspection_condition,
                        CASE WHEN rs.inspection_condition = 'Lost' THEN 0 ELSE GREATEST(1, ri.requested_quantity) END,
                        CASE WHEN rs.inspection_condition = 'Lost' THEN GREATEST(1, ri.requested_quantity) ELSE 0 END,
                        rs.inspection_remarks,
                        rs.admin_id,
                        rs.reviewed_at,
                        1,
                        rs.submitted_at
                    FROM return_submissions rs
                    INNER JOIN request_items ri
                        ON ri.request_id = rs.request_id AND ri.line_kind <> 'Consumable'
                    WHERE NOT EXISTS (
                        SELECT 1 FROM return_submission_items rsi WHERE rsi.return_id = rs.return_id
                    )
                ");
            }

            if (tableExists($pdo, "maintenance_schedules")) {
                if (!foreignKeyExists($pdo, "maintenance_schedules", "fk_ms_created_by")) {
                    $pdo->exec("
                        ALTER TABLE maintenance_schedules
                        ADD CONSTRAINT fk_ms_created_by
                            FOREIGN KEY (created_by) REFERENCES users (user_id)
                            ON DELETE RESTRICT
                    ");
                }

                if (!foreignKeyExists($pdo, "maintenance_schedules", "fk_ms_updated_by")) {
                    $pdo->exec("
                        ALTER TABLE maintenance_schedules
                        ADD CONSTRAINT fk_ms_updated_by
                            FOREIGN KEY (updated_by) REFERENCES users (user_id)
                            ON DELETE SET NULL
                    ");
                }
            }
        } catch (Throwable $e) {
            $hasRun = false;
            throw new RuntimeException("Schema initialization failed: " . $e->getMessage(), 0, $e);
        }
    }
}
