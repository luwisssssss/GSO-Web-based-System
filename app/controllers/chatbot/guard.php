<?php

/**
 * Shared guard for the chatbot JSON endpoints.
 *
 * Identity always comes from the server session and is re-checked against the
 * users table on every request, so a disabled or de-verified account loses
 * chatbot access immediately. Nothing here trusts a browser-supplied user id
 * or role.
 */

require_once dirname(__DIR__, 3) . "/config/bootstrap.php";
gsoSecureSessionStart();
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/ai_chat_helper.php";
require_once dirname(__DIR__, 3) . "/includes/role_helper.php";

if (!function_exists("gsoChatJsonResponse")) {
    function gsoChatJsonResponse(int $status, array $payload): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        header("X-Content-Type-Options: nosniff");
        header("Cache-Control: no-store");
        // Hex-escape the HTML-significant characters. The payload still parses
        // to identical strings, but stays inert if a browser is ever coaxed
        // into treating the response as markup.
        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        exit();
    }
}

if (!function_exists("gsoChatFail")) {
    /** User-facing errors only. Internal detail goes to the error log. */
    function gsoChatFail(int $status, string $errorCode, string $message): void
    {
        gsoChatJsonResponse($status, [
            "ok" => false,
            "error_code" => $errorCode,
            "message" => $message,
        ]);
    }
}

if (!function_exists("gsoChatAuthenticate")) {
    /**
     * @return array{user_id:int,role:string,full_name:string}
     */
    function gsoChatAuthenticate(PDO $pdo): array
    {
        $userId = (int) ($_SESSION["user_id"] ?? 0);

        if ($userId <= 0) {
            gsoChatFail(401, "unauthenticated", "Please sign in to use the assistant.");
        }

        $stmt = $pdo->prepare("
            SELECT user_id, full_name, role
            FROM users
            WHERE user_id = :user_id
              AND account_status = 'Approved'
              AND email_verified = 1
            LIMIT 1
        ");
        $stmt->execute([":user_id" => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !gsoUserHasPermission((string) $user["role"], "chatbot.use")) {
            gsoChatFail(403, "unauthorized", "Your account is not authorized to use the assistant.");
        }

        return [
            "user_id" => (int) $user["user_id"],
            "role" => (string) $user["role"],
            "full_name" => (string) $user["full_name"],
        ];
    }
}

if (!function_exists("gsoChatRequirePost")) {
    function gsoChatRequirePost(): void
    {
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
            gsoChatFail(405, "method_not_allowed", "Unsupported request.");
        }
    }
}

if (!function_exists("gsoChatVerifyCsrf")) {
    function gsoChatVerifyCsrf(): void
    {
        $token = (string) ($_POST["csrf_token"] ?? "");

        if (empty($_SESSION["csrf_token"]) || !hash_equals((string) $_SESSION["csrf_token"], $token)) {
            gsoChatFail(403, "invalid_token", "Your session expired. Please refresh the page.");
        }
    }
}
