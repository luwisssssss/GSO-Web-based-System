<?php

/**
 * Deterministic parameter extraction for chatbot live queries.
 *
 * Everything here is plain PHP pattern matching over the user's message. No
 * model output is involved, so an extracted value can never be an invented
 * database id or a smuggled instruction. Resource and facility names are
 * resolved against the real catalogue rather than parsed freely, which means
 * the assistant cannot refer to something that does not exist.
 *
 * Relative dates resolve against the application's configured timezone
 * (GSO_APP_TIMEZONE, applied in config/bootstrap.php), never the model's idea
 * of today.
 */

require_once __DIR__ . "/ai_knowledge_helper.php";

if (!function_exists("gsoChatNormalizeText")) {
    function gsoChatNormalizeText(string $message): string
    {
        $normalized = strtolower(trim($message));
        $normalized = preg_replace('/[^a-z0-9:\/\-\s]/', " ", $normalized) ?? "";

        return trim(preg_replace('/\s+/', " ", $normalized) ?? "");
    }
}

if (!function_exists("gsoChatExtractCode")) {
    /**
     * Pull a reference like REQ-123, BRW-001 or INC-000123 out of a message.
     *
     * Returns the numeric part only; the caller still has to resolve it
     * through an authorization-scoped lookup, so a guessed number gains
     * nothing.
     */
    function gsoChatExtractCode(string $message, array $prefixes): ?int
    {
        $pattern = '/\b(?:' . implode("|", array_map("preg_quote", $prefixes)) . ')\s*[-#:]?\s*(\d{1,9})\b/i';

        if (preg_match($pattern, $message, $matches)) {
            return (int) $matches[1];
        }

        // Bare "request 12" / "incident 7" phrasing.
        $wordPattern = '/\b(?:request|reference|ticket|incident|report|borrow(?:ing)?)\s*(?:number|no\.?|#)?\s*(\d{1,9})\b/i';

        if (preg_match($wordPattern, $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}

if (!function_exists("gsoChatExtractDate")) {
    /**
     * Resolve a date phrase to Y-m-d using server time.
     *
     * Returns null when the message names no date, and the caller then asks
     * the user instead of assuming one.
     */
    function gsoChatExtractDate(string $message, ?int $now = null): ?string
    {
        $now = $now ?? time();
        $text = gsoChatNormalizeText($message);

        if ($text === "") {
            return null;
        }

        // Explicit calendar dates first.
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $text, $m)) {
            $candidate = sprintf("%04d-%02d-%02d", (int) $m[1], (int) $m[2], (int) $m[3]);
            return gsoChatIsRealDate($candidate) ? $candidate : null;
        }

        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $text, $m)) {
            $candidate = sprintf("%04d-%02d-%02d", (int) $m[3], (int) $m[1], (int) $m[2]);
            return gsoChatIsRealDate($candidate) ? $candidate : null;
        }

        // "september 20" / "20 september" (optionally with a year).
        $months = "january|february|march|april|may|june|july|august|september|october|november|december";

        if (preg_match('/\b(' . $months . ')\s+(\d{1,2})(?:\s*,?\s*(\d{4}))?\b/', $text, $m)) {
            return gsoChatResolveNamedDate($m[1] . " " . $m[2] . " " . ($m[3] ?? date("Y", $now)), $now);
        }

        if (preg_match('/\b(\d{1,2})\s+(' . $months . ')(?:\s*,?\s*(\d{4}))?\b/', $text, $m)) {
            return gsoChatResolveNamedDate($m[2] . " " . $m[1] . " " . ($m[3] ?? date("Y", $now)), $now);
        }

        if (strpos($text, "day after tomorrow") !== false) {
            return date("Y-m-d", strtotime("+2 days", $now));
        }

        if (strpos($text, "tomorrow") !== false) {
            return date("Y-m-d", strtotime("+1 day", $now));
        }

        if (preg_match('/\b(today|this (?:morning|afternoon|evening)|tonight|later)\b/', $text)) {
            return date("Y-m-d", $now);
        }

        // "next monday", "this friday", or a bare weekday name.
        $days = "monday|tuesday|wednesday|thursday|friday|saturday|sunday";

        if (preg_match('/\b(next|this|coming)?\s*(' . $days . ')\b/', $text, $m)) {
            $qualifier = trim((string) ($m[1] ?? ""));
            $weekday = $m[2];
            $expression = ($qualifier === "next" ? "next " : "") . $weekday;
            $resolved = strtotime($expression, $now);

            if ($resolved === false) {
                return null;
            }

            // "this friday" / a bare weekday should not resolve into the past.
            if ($qualifier !== "next" && date("Y-m-d", $resolved) < date("Y-m-d", $now)) {
                $resolved = strtotime("next " . $weekday, $now);
            }

            return $resolved === false ? null : date("Y-m-d", $resolved);
        }

        return null;
    }
}

if (!function_exists("gsoChatIsRealDate")) {
    function gsoChatIsRealDate(string $value): bool
    {
        $parts = explode("-", $value);

        if (count($parts) !== 3) {
            return false;
        }

        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }
}

if (!function_exists("gsoChatResolveNamedDate")) {
    function gsoChatResolveNamedDate(string $expression, int $now): ?string
    {
        $resolved = strtotime($expression, $now);

        return $resolved === false ? null : date("Y-m-d", $resolved);
    }
}

if (!function_exists("gsoChatExtractTimeRange")) {
    /**
     * Pull a start and end time out of a message.
     *
     * Handles "1pm to 3pm", "13:00-15:00", "from 9 am until 11 am" and a lone
     * "at 2 pm". A single time yields only a start; the caller decides whether
     * that is enough or whether to ask for the end.
     *
     * @return array{start:?string,end:?string}
     */
    function gsoChatExtractTimeRange(string $message): array
    {
        $text = strtolower(trim($message));

        // Remove calendar dates first: "2026-09-21" otherwise reads as the
        // time range "09 to 21" because of the dash.
        $text = preg_replace('/\b\d{4}-\d{1,2}-\d{1,2}\b/', " ", $text) ?? $text;
        $text = preg_replace('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', " ", $text) ?? $text;

        $timePattern = '(\d{1,2})(?::(\d{2}))?\s*(am|pm)?';

        if (preg_match('/\b' . $timePattern . '\s*(?:-|to|until|till|hanggang)\s*' . $timePattern . '\b/i', $text, $m)) {
            // A bare first time borrows the meridiem of the second ("1 to 3 pm").
            $firstMeridiem = $m[3] !== "" ? $m[3] : ($m[6] ?? "");
            $start = gsoChatBuildTime($m[1], $m[2] ?? "", $firstMeridiem);
            $end = gsoChatBuildTime($m[4], $m[5] ?? "", $m[6] ?? "");

            if ($start !== null && $end !== null) {
                return ["start" => $start, "end" => $end];
            }
        }

        if (preg_match('/\b(?:at|from|around)\s+' . $timePattern . '\b/i', $text, $m)) {
            return ["start" => gsoChatBuildTime($m[1], $m[2] ?? "", $m[3] ?? ""), "end" => null];
        }

        if (preg_match('/\b' . $timePattern . '\b/i', $text, $m) && ($m[3] ?? "") !== "") {
            return ["start" => gsoChatBuildTime($m[1], $m[2] ?? "", $m[3]), "end" => null];
        }

        return ["start" => null, "end" => null];
    }
}

if (!function_exists("gsoChatBuildTime")) {
    function gsoChatBuildTime(string $hour, string $minute, string $meridiem): ?string
    {
        $hourValue = (int) $hour;
        $minuteValue = $minute === "" ? 0 : (int) $minute;
        $meridiem = strtolower(trim($meridiem));

        if ($minuteValue < 0 || $minuteValue > 59) {
            return null;
        }

        if ($meridiem === "pm" && $hourValue < 12) {
            $hourValue += 12;
        } elseif ($meridiem === "am" && $hourValue === 12) {
            $hourValue = 0;
        }

        if ($hourValue < 0 || $hourValue > 23) {
            return null;
        }

        return sprintf("%02d:%02d:00", $hourValue, $minuteValue);
    }
}

if (!function_exists("gsoChatMatchResources")) {
    /**
     * Match a message against the real resource catalogue.
     *
     * Matching only ever selects from rows that exist, so the assistant cannot
     * name a resource the GSO does not have. Returns every candidate; deciding
     * between "one match", "ambiguous" and "none" is the caller's job.
     *
     * @param string|null $type "Item", "Facility", or null for both.
     */
    function gsoChatMatchResources(PDO $pdo, string $message, ?string $type = null): array
    {
        $text = gsoChatNormalizeText($message);

        if ($text === "") {
            return [];
        }

        $sql = "SELECT resource_id, resource_name, resource_type, category, location,
                       total_stock, available_stock, under_repair_stock, capacity,
                       status, condition_status, is_archived
                FROM resources
                WHERE is_archived = 0";
        $params = [];

        if ($type !== null) {
            $sql .= " AND resource_type = :type";
            $params[":type"] = $type;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $resources = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $matches = [];

        foreach ($resources as $resource) {
            $name = gsoChatNormalizeText((string) ($resource["resource_name"] ?? ""));

            if ($name === "") {
                continue;
            }

            $score = 0.0;

            // Whole-name mention is the strongest signal.
            if (strpos($text, $name) !== false) {
                $score += 10.0 + (substr_count($name, " ") * 2);
            } else {
                // Otherwise require every meaningful word of the name to appear.
                $nameWords = array_values(array_filter(
                    explode(" ", $name),
                    static fn (string $word): bool => strlen($word) > 2
                ));

                if ($nameWords !== []) {
                    $hits = 0;

                    foreach ($nameWords as $word) {
                        if (preg_match('/\b' . preg_quote($word, "/") . '/', $text)) {
                            $hits++;
                        }
                    }

                    if ($hits === count($nameWords)) {
                        $score += 6.0;
                    } elseif ($hits > 0 && count($nameWords) > 1) {
                        $score += 2.0 * ($hits / count($nameWords));
                    }
                }
            }

            if ($score > 0) {
                $resource["match_score"] = $score;
                $matches[] = $resource;
            }
        }

        usort($matches, static fn (array $a, array $b): int => $b["match_score"] <=> $a["match_score"]);

        // Keep only the strongest tier so a near-miss cannot make a clear
        // request look ambiguous.
        if ($matches !== []) {
            $best = (float) $matches[0]["match_score"];
            $matches = array_values(array_filter(
                $matches,
                static fn (array $row): bool => (float) $row["match_score"] >= $best - 0.001
            ));
        }

        return $matches;
    }
}
