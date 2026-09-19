<?php
require_once dirname(__DIR__,3)."/includes/borrower_check.php";
require_once dirname(__DIR__,3)."/config/db.php";
require_once dirname(__DIR__,3)."/includes/adviser_endorsement_helper.php";
header("Content-Type: application/json; charset=UTF-8");header("Cache-Control: no-store");
$studentId=(int)($_SESSION["user_id"]??0);$requestId=(int)($_GET["request_id"]??0);
$s=$pdo->prepare("SELECT 1 FROM resource_requests WHERE request_id=:rid AND borrower_id=:uid AND adviser_state='Selection Required' AND adviser_user_id IS NULL");$s->execute([":rid"=>$requestId,":uid"=>$studentId]);
if(!gsoIsApprovedStudent($pdo,$studentId)||!$s->fetchColumn()){http_response_code(403);echo json_encode(["ok"=>false,"results"=>[]]);exit;}
$rows=gsoFindEligibleFaculty($pdo,(string)($_GET["q"]??""),20);
echo json_encode(["ok"=>true,"results"=>array_map(static fn($r)=>["user_id"=>(int)$r["user_id"],"name"=>(string)$r["full_name"],"department"=>(string)($r["department"]??"")],$rows)],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
