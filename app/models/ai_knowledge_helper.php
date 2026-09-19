<?php

/**
 * AI knowledge base storage and retrieval.
 *
 * These entries are the only source the assistant may answer GSO procedure
 * questions from. Retrieval is deterministic keyword scoring done in PHP over
 * active entries, so every answer can be traced back to a specific entry and
 * the assistant cannot invent an official rule.
 */

require_once __DIR__ . "/schema_helper.php";

if (!function_exists("gsoAiKnowledgeCategories")) {
    function gsoAiKnowledgeCategories(): array
    {
        return [
            "Borrowing",
            "Facilities",
            "Returns",
            "Incidents",
            "Maintenance",
            "Navigation",
            "General",
        ];
    }
}

if (!function_exists("gsoAiKnowledgeStatuses")) {
    function gsoAiKnowledgeStatuses(): array
    {
        return ["Active", "Inactive"];
    }
}

if (!function_exists("gsoAiTokenize")) {
    /**
     * Reduce free text to comparable lowercase tokens, dropping words that
     * carry no retrieval signal.
     */
    function gsoAiTokenize(string $text): array
    {
        $stopWords = [
            "a", "an", "and", "are", "as", "at", "be", "can", "did", "do", "does", "for", "from",
            "get", "got", "how", "i", "if", "in", "is", "it", "me", "my", "of", "on", "or", "please",
            "so", "that", "the", "then", "there", "this", "to", "want", "was", "we", "what", "when",
            "where", "which", "who", "why", "will", "with", "you", "your",
        ];

        $normalized = strtolower(trim($text));
        $normalized = preg_replace('/[^a-z0-9\s]/', " ", $normalized) ?? "";
        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_filter($tokens, static function (string $token) use ($stopWords): bool {
            return strlen($token) > 2 && !in_array($token, $stopWords, true);
        });

        return array_values(array_unique($tokens));
    }
}

if (!function_exists("gsoScoreKnowledgeEntry")) {
    /**
     * Weighted overlap between the asker's tokens and one entry. Curated
     * keywords outrank an incidental match in the body text.
     */
    function gsoScoreKnowledgeEntry(array $tokens, array $entry): float
    {
        if ($tokens === []) {
            return 0.0;
        }

        $fields = [
            "keywords" => 5.0,
            "question" => 3.0,
            "title" => 2.0,
            "category" => 1.5,
            "content" => 0.5,
        ];

        $score = 0.0;

        foreach ($fields as $field => $weight) {
            $haystack = " " . strtolower((string) ($entry[$field] ?? "")) . " ";

            if (trim($haystack) === "") {
                continue;
            }

            foreach ($tokens as $token) {
                if (strpos($haystack, $token) !== false) {
                    $score += $weight;
                }
            }
        }

        // Normalize so a long question cannot dilute a strong match.
        return $score / max(1, count($tokens));
    }
}

if (!function_exists("gsoFindKnowledgeEntries")) {
    /**
     * Best-matching active entries for a question, most relevant first.
     *
     * Returns an empty array when nothing clears the relevance floor, which is
     * how the assistant knows to say it does not have the answer instead of
     * guessing at official policy.
     */
    function gsoFindKnowledgeEntries(PDO $pdo, string $question, int $limit = 3, float $minimumScore = 1.5): array
    {
        $tokens = gsoAiTokenize($question);

        if ($tokens === []) {
            return [];
        }

        try {
            $stmt = $pdo->query("
                SELECT entry_id, title, category, question, content, keywords
                FROM ai_knowledge_entries
                WHERE status = 'Active'
            ");
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("Knowledge base retrieval failed: " . $e->getMessage());
            return [];
        }

        $scored = [];

        foreach ($entries as $entry) {
            $score = gsoScoreKnowledgeEntry($tokens, $entry);

            if ($score >= $minimumScore) {
                $entry["score"] = $score;
                $scored[] = $entry;
            }
        }

        usort($scored, static function (array $a, array $b): int {
            return $b["score"] <=> $a["score"];
        });

        return array_slice($scored, 0, max(1, $limit));
    }
}

if (!function_exists("gsoListKnowledgeEntries")) {
    function gsoListKnowledgeEntries(PDO $pdo, array $filters = []): array
    {
        $where = ["1 = 1"];
        $params = [];

        $category = (string) ($filters["category"] ?? "All");
        if ($category !== "All" && in_array($category, gsoAiKnowledgeCategories(), true)) {
            $where[] = "category = :category";
            $params[":category"] = $category;
        }

        $status = (string) ($filters["status"] ?? "All");
        if ($status !== "All" && in_array($status, gsoAiKnowledgeStatuses(), true)) {
            $where[] = "status = :status";
            $params[":status"] = $status;
        }

        $search = trim((string) ($filters["search"] ?? ""));
        if ($search !== "") {
            $where[] = "(title LIKE :search_title OR question LIKE :search_question OR keywords LIKE :search_keywords)";
            $value = "%" . str_replace(["%", "_"], ["\\%", "\\_"], $search) . "%";
            $params[":search_title"] = $params[":search_question"] = $params[":search_keywords"] = $value;
        }

        $sql = "SELECT entry_id, title, category, question, content, keywords, status, updated_at
                FROM ai_knowledge_entries
                WHERE " . implode(" AND ", $where) . "
                ORDER BY category ASC, title ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("gsoGetKnowledgeEntry")) {
    function gsoGetKnowledgeEntry(PDO $pdo, int $entryId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM ai_knowledge_entries WHERE entry_id = :id LIMIT 1");
        $stmt->execute([":id" => $entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        return $entry ?: null;
    }
}

if (!function_exists("gsoValidateKnowledgeInput")) {
    /** Server-side validation shared by the create and update paths. */
    function gsoValidateKnowledgeInput(array $input): array
    {
        $errors = [];
        $title = trim((string) ($input["title"] ?? ""));
        $question = trim((string) ($input["question"] ?? ""));
        $content = trim((string) ($input["content"] ?? ""));
        $category = trim((string) ($input["category"] ?? ""));
        $status = trim((string) ($input["status"] ?? "Active"));
        $keywords = trim((string) ($input["keywords"] ?? ""));

        if ($title === "" || mb_strlen($title) > 160) {
            $errors[] = "Title is required and must be 160 characters or fewer.";
        }
        if ($question === "" || mb_strlen($question) > 255) {
            $errors[] = "Sample question is required and must be 255 characters or fewer.";
        }
        if ($content === "") {
            $errors[] = "Answer content is required.";
        }
        if (mb_strlen($content) > 5000) {
            $errors[] = "Answer content must be 5000 characters or fewer.";
        }
        if (!in_array($category, gsoAiKnowledgeCategories(), true)) {
            $errors[] = "Select a valid category.";
        }
        if (!in_array($status, gsoAiKnowledgeStatuses(), true)) {
            $errors[] = "Select a valid status.";
        }
        if (mb_strlen($keywords) > 500) {
            $errors[] = "Keywords must be 500 characters or fewer.";
        }

        return [
            "errors" => $errors,
            "values" => [
                "title" => $title,
                "question" => $question,
                "content" => $content,
                "category" => $category,
                "status" => $status,
                "keywords" => $keywords,
            ],
        ];
    }
}

if (!function_exists("gsoKnowledgeActorId")) {
    /**
     * Normalize the acting admin for the created_by/updated_by columns.
     * Seeds and system tasks have no user, so they store NULL rather than a
     * placeholder id the foreign key would (correctly) reject.
     */
    function gsoKnowledgeActorId(int $adminUserId): ?int
    {
        return $adminUserId > 0 ? $adminUserId : null;
    }
}

if (!function_exists("gsoCreateKnowledgeEntry")) {
    function gsoCreateKnowledgeEntry(PDO $pdo, array $values, int $adminUserId): int
    {
        $actorId = gsoKnowledgeActorId($adminUserId);
        $stmt = $pdo->prepare("
            INSERT INTO ai_knowledge_entries
                (title, category, question, content, keywords, status, created_by, updated_by)
            VALUES
                (:title, :category, :question, :content, :keywords, :status, :created_by, :updated_by)
        ");
        $stmt->execute([
            ":title" => $values["title"],
            ":category" => $values["category"],
            ":question" => $values["question"],
            ":content" => $values["content"],
            ":keywords" => $values["keywords"],
            ":status" => $values["status"],
            ":created_by" => $actorId,
            ":updated_by" => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }
}

if (!function_exists("gsoUpdateKnowledgeEntry")) {
    function gsoUpdateKnowledgeEntry(PDO $pdo, int $entryId, array $values, int $adminUserId): bool
    {
        $stmt = $pdo->prepare("
            UPDATE ai_knowledge_entries
            SET title = :title,
                category = :category,
                question = :question,
                content = :content,
                keywords = :keywords,
                status = :status,
                updated_by = :updated_by,
                updated_at = NOW()
            WHERE entry_id = :entry_id
            LIMIT 1
        ");

        return $stmt->execute([
            ":title" => $values["title"],
            ":category" => $values["category"],
            ":question" => $values["question"],
            ":content" => $values["content"],
            ":keywords" => $values["keywords"],
            ":status" => $values["status"],
            ":updated_by" => gsoKnowledgeActorId($adminUserId),
            ":entry_id" => $entryId,
        ]);
    }
}

if (!function_exists("gsoSetKnowledgeEntryStatus")) {
    function gsoSetKnowledgeEntryStatus(PDO $pdo, int $entryId, string $status, int $adminUserId): bool
    {
        if (!in_array($status, gsoAiKnowledgeStatuses(), true)) {
            return false;
        }

        // The status mismatch keeps a repeated toggle from logging twice.
        $stmt = $pdo->prepare("
            UPDATE ai_knowledge_entries
            SET status = :status, updated_by = :updated_by, updated_at = NOW()
            WHERE entry_id = :entry_id AND status <> :current_status
            LIMIT 1
        ");
        $stmt->execute([
            ":status" => $status,
            ":updated_by" => gsoKnowledgeActorId($adminUserId),
            ":entry_id" => $entryId,
            ":current_status" => $status,
        ]);

        return $stmt->rowCount() === 1;
    }
}

if (!function_exists("gsoDeleteKnowledgeEntry")) {
    function gsoDeleteKnowledgeEntry(PDO $pdo, int $entryId): bool
    {
        $stmt = $pdo->prepare("DELETE FROM ai_knowledge_entries WHERE entry_id = :entry_id LIMIT 1");
        $stmt->execute([":entry_id" => $entryId]);

        return $stmt->rowCount() === 1;
    }
}
