<?php
require_once __DIR__ . "/role_helper.php";

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
if (!function_exists("gsoFindEligibleFaculty")) {
    function gsoFindEligibleFaculty(PDO $pdo, string $query="", int $limit=20): array
    {
        $limit=max(1,min(20,$limit)); $query=trim(preg_replace('/\s+/u',' ',$query));
        $sql="SELECT user_id,full_name,department FROM users WHERE role='Borrower' AND account_type='Faculty' AND account_status='Approved' AND email_verified=1";
        $params=[];
        if($query!==""){ $sql.=" AND (full_name LIKE :q OR department LIKE :q)"; $params[":q"]="%".mb_substr($query,0,80)."%"; }
        $sql.=" ORDER BY full_name ASC LIMIT {$limit}"; $stmt=$pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
}
if (!function_exists("gsoAdviserStateBlocksGso")) {
    function gsoAdviserStateBlocksGso(array $request): bool
    { return in_array((string)($request["adviser_state"]??"Not Required"),["Selection Required","Pending Adviser","Revision Required"],true); }
}
if (!function_exists("gsoNextAdviserCycle")) {
    function gsoNextAdviserCycle(PDO $pdo,int $requestId): int
    { $s=$pdo->prepare("SELECT COALESCE(MAX(cycle_no),0)+1 FROM request_adviser_history WHERE request_id=:id");$s->execute([":id"=>$requestId]);return (int)$s->fetchColumn(); }
}
if (!function_exists("gsoAdviserHistory")) {
    function gsoAdviserHistory(PDO $pdo,int $requestId): array
    { $s=$pdo->prepare("SELECT h.*,u.full_name AS actor_name FROM request_adviser_history h JOIN users u ON u.user_id=h.acted_by WHERE h.request_id=:id ORDER BY h.acted_at,h.history_id");$s->execute([":id"=>$requestId]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; }
}
if (!function_exists("gsoNotifyApprovedAdmins")) {
    function gsoNotifyApprovedAdmins(PDO $pdo,string $type,string $title,string $message,string $link): void
    { foreach($pdo->query("SELECT user_id FROM users WHERE role='Admin' AND account_status='Approved' AND email_verified=1")->fetchAll(PDO::FETCH_COLUMN)?:[] as $id){ createNotification($pdo,(int)$id,$type,$title,$message,$link); } }
}
if (!function_exists("gsoSendAdviserAssignmentEmail")) {
    function gsoSendAdviserAssignmentEmail(PDO $pdo,int $requestId,int $adviserId,int $studentId): bool
    {
        require_once dirname(__DIR__,2)."/includes/mail_helper.php";
        $s=$pdo->prepare("SELECT a.full_name,a.email,b.full_name AS student_name,r.resource_name,rr.notes FROM resource_requests rr JOIN users a ON a.user_id=:aid JOIN users b ON b.user_id=rr.borrower_id JOIN resources r ON r.resource_id=rr.resource_id WHERE rr.request_id=:rid AND rr.borrower_id=:sid LIMIT 1");
        $s->execute([":aid"=>$adviserId,":rid"=>$requestId,":sid"=>$studentId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row)return false;
        $ref="REQ-".str_pad((string)$requestId,3,"0",STR_PAD_LEFT);$url=gsoBuildAppUrl("borrower/endorsement_detail.php?request_id={$requestId}");
        $safeName=htmlspecialchars((string)$row["full_name"],ENT_QUOTES,"UTF-8");$safeRef=htmlspecialchars($ref,ENT_QUOTES,"UTF-8");$safeStudent=htmlspecialchars((string)$row["student_name"],ENT_QUOTES,"UTF-8");$safeResource=htmlspecialchars((string)$row["resource_name"],ENT_QUOTES,"UTF-8");$safeUrl=htmlspecialchars($url,ENT_QUOTES,"UTF-8");
        $config=require dirname(__DIR__,2)."/config/mail.php";
        $result=str_ends_with(strtolower((string)$row["email"]),"@example.invalid")
            ? ["success"=>false,"channel"=>"none","message"=>"Reserved test address; delivery skipped."]
            : gsoSendEmail($config,(string)$row["email"],(string)$row["full_name"],"Adviser endorsement requested: {$ref}","<p>Hello {$safeName},</p><p>{$safeStudent} assigned you to review {$safeRef} for {$safeResource}.</p><p><a href=\"{$safeUrl}\">Sign in to review the request</a></p><p>The link does not authorize a decision; authentication is required.</p>","Adviser endorsement requested for {$ref} by {$row['student_name']}. Sign in to review: {$url}");
        addActivityLog($pdo,$studentId,!empty($result["success"])?"Adviser Email Notification Sent":"Adviser Email Notification Failed","Email notification for {$ref} ".(!empty($result["success"])?"was sent.":"failed; in-app routing remains active."));
        if(empty($result["success"]))error_log("Adviser email notification failed for request {$requestId}.");
        return !empty($result["success"]);
    }
}
