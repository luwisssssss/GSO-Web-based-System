<?php

/**
 * Chatbot history endpoint.
 *
 * POST action=list           -> this user's recent conversations
 * POST action=load           -> messages of one conversation the user owns
 * POST action=new            -> start an empty conversation
 *
 * Ownership is enforced in the service queries, so an id belonging to another
 * user resolves to nothing rather than to their transcript.
 */

require_once __DIR__ . "/guard.php";

gsoChatRequirePost();
$authUser = gsoChatAuthenticate($pdo);
gsoChatVerifyCsrf();

$action = trim((string) ($_POST["action"] ?? "list"));
$userId = (int) $authUser["user_id"];

try {
    if ($action === "list") {
        $conversations = gsoListUserConversations($pdo, $userId, 15);

        gsoChatJsonResponse(200, [
            "ok" => true,
            "conversations" => array_map(static function (array $row): array {
                return [
                    "conversation_id" => (int) $row["conversation_id"],
                    "title" => (string) $row["title"],
                    "updated_at" => (string) $row["updated_at"],
                ];
            }, $conversations),
        ]);
    }

    if ($action === "new") {
        $conversationId = gsoCreateConversation($pdo, $userId);

        gsoChatJsonResponse(200, [
            "ok" => true,
            "conversation_id" => $conversationId,
            "messages" => [],
        ]);
    }

    if ($action === "load") {
        $conversationId = (int) ($_POST["conversation_id"] ?? 0);
        $conversation = gsoGetOwnedConversation($pdo, $userId, $conversationId);

        if ($conversation === null) {
            gsoChatFail(404, "conversation_not_found", "That conversation is not available.");
        }

        $messages = gsoGetConversationMessages($pdo, $userId, $conversationId, 100);

        gsoChatJsonResponse(200, [
            "ok" => true,
            "conversation_id" => $conversationId,
            "title" => (string) $conversation["title"],
            "messages" => array_map(static function (array $row): array {
                return [
                    "sender_type" => (string) $row["sender_type"],
                    "message" => (string) $row["message"],
                    "created_at" => (string) $row["created_at"],
                ];
            }, $messages),
        ]);
    }
} catch (Throwable $e) {
    error_log("Chatbot history request failed: " . $e->getMessage());
    gsoChatFail(500, "service_error", "Sorry, the assistant is temporarily unavailable. Please try again.");
}

gsoChatFail(400, "invalid_action", "Unsupported request.");
