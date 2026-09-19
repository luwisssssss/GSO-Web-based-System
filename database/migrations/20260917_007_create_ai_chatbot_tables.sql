-- Step 9 AI chatbot foundation.
--
-- ai_conversations : one chat thread, always owned by a single user.
-- ai_messages      : the turns of a thread (user / assistant / system).
-- ai_knowledge_entries : the curated GSO procedure content that is the only
--                    source the assistant may answer procedure questions from.
--
-- No transactional GSO table is modified. The chatbot never issues SQL of its
-- own; it reads knowledge through application services only.

CREATE TABLE IF NOT EXISTS `ai_conversations` (
    `conversation_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `title` VARCHAR(160) NOT NULL DEFAULT 'New conversation',
    `status` ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active',
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`conversation_id`),
    KEY `idx_ai_conversations_user` (`user_id`, `status`, `updated_at`),
    CONSTRAINT `fk_ai_conversations_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
    `message_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT(20) NOT NULL,
    `sender_type` ENUM('user', 'assistant', 'system') NOT NULL,
    `message` MEDIUMTEXT NOT NULL,
    `intent` VARCHAR(60) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`message_id`),
    KEY `idx_ai_messages_conversation` (`conversation_id`, `message_id`),
    CONSTRAINT `fk_ai_messages_conversation`
        FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`conversation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_entries` (
    `entry_id` INT(11) NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(160) NOT NULL,
    `category` VARCHAR(60) NOT NULL DEFAULT 'General',
    `question` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `keywords` VARCHAR(500) NOT NULL DEFAULT '',
    `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `created_by` INT(11) DEFAULT NULL,
    `updated_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`entry_id`),
    KEY `idx_ai_knowledge_status` (`status`, `category`),
    CONSTRAINT `fk_ai_knowledge_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ai_knowledge_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
