<?php
require_once __DIR__ . "/role_helper.php";
require_once __DIR__ . "/request_helper.php";

if (!function_exists("gsoIsApprovedFaculty")) {
    function gsoIsApprovedFaculty(PDO $pdo, int $userId): bool
    {
        $stmt=$pdo->prepare("SELECT 1 FROM users WHERE user_id=:id AND role='Borrower' AND account_type='Faculty' AND account_status='Approved' AND email_verified=1 LIMIT 1");
        $stmt->execute([":id"=>$userId]); return (bool)$stmt->fetchColumn();
    }
}
if (!function_exists("gsoIsApprovedStudent")) {
    function gsoIsApprovedStudent(PDO $pdo, int $userId): bool
    {
        $stmt=$pdo->prepare("SELECT 1 FROM users WHERE user_id=:id AND role='Borrower' AND account_type='Student' AND account_status='Approved' AND email_verified=1 LIMIT 1");
        $stmt->execute([":id"=>$userId]); return (bool)$stmt->fetchColumn();
    }
}
// R2B: retired compatibility helpers never route, write decisions or send mail.
function gsoFindEligibleFaculty(PDO $pdo, string $query="", int $limit=20): array { return []; }
function gsoAdviserStateBlocksGso(array $request): bool { return false; }
function gsoSendAdviserAssignmentEmail(PDO $pdo,int $requestId,int $adviserId,int $studentId): bool { return false; }
function gsoAdviserHistory(PDO $pdo,int $requestId): array {
    $s=$pdo->prepare("SELECT h.*,u.full_name AS actor_name FROM request_adviser_history h JOIN users u ON u.user_id=h.acted_by WHERE h.request_id=:id ORDER BY h.acted_at,h.history_id");
    $s->execute([":id"=>$requestId]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
}
