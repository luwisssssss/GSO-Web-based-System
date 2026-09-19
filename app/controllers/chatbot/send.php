<?php

/**
 * Chatbot message endpoint.
 *
 * POST: csrf_token, message, optional conversation_id
 * Returns the assistant reply as JSON. All authorization, routing and
 * knowledge retrieval happen server-side in the ChatbotService.
 */

require_once __DIR__ . "/guard.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";

gsoChatRequirePost();
$authUser = gsoChatAuthenticate($pdo);
gsoChatVerifyCsrf();

$message = (string) ($_POST["message"] ?? "");
$rawConversationId = $_POST["conversation_id"] ?? null;
$conversationId = ($rawConversationId === null || $rawConversationId === "")
    ? null
    : (int) $rawConversationId;

try {
    $result = gsoHandleChatMessage($pdo, $authUser, $conversationId, $message);
} catch (Throwable $e) {
    // Never leak the exception text, file paths, or SQL to the browser.
    error_log("Chatbot request failed: " . $e->getMessage());
    gsoChatFail(500, "service_error", "Sorry, the assistant is temporarily unavailable. Please try again.");
}

if (empty($result["ok"])) {
    if (($result["error_code"] ?? "") === "unauthorized") {
        addActivityLog(
            $pdo,
            (int) $authUser["user_id"],
            "Chatbot Access Denied",
            "A chatbot request was rejected by authorization."
        );
    }

    gsoChatFail(
        (int) ($result["status"] ?? 400),
        (string) ($result["error_code"] ?? "request_failed"),
        (string) ($result["message"] ?? "The assistant could not handle that request.")
    );
}

gsoChatJsonResponse(200, [
    "ok" => true,
    "conversation_id" => (int) $result["conversation_id"],
    "intent" => (string) $result["intent"],
    "source" => (string) $result["source"],
    "message" => (string) $result["message"],
]);
