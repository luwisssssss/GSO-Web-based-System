<?php
require_once dirname(__DIR__, 3) . '/includes/auth_check.php';
require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/includes/request_attachment_helper.php';
ensureApprovedPermissionAccount($pdo, 'profile.manage_own');
$id = (string) ($_GET['attachment_id'] ?? '');
if (!preg_match('/\A[1-9][0-9]{0,18}\z/', $id)) { http_response_code(404); exit('Document not found.'); }
$s=$pdo->prepare('SELECT a.*,r.borrower_id FROM request_attachments a JOIN resource_requests r ON r.request_id=a.request_id WHERE a.attachment_id=?');$s->execute([$id]);$letter=$s->fetch(PDO::FETCH_ASSOC);
$role=(string)$_SESSION['role'];
$own=$letter && (int)$letter['borrower_id']===(int)$_SESSION['user_id'] && gsoUserHasPermission($role,'request.view_own');
if (!$letter || (!$own && !gsoUserHasPermission($role,'request.documents.view'))) { http_response_code(404); exit('Document not found.'); }
try { $path=gsoRequestLetterPath($letter['stored_file_name']); } catch(Throwable $e) { http_response_code(404); exit('Document not found.'); }
if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Document not found.'); }
header('Content-Type: '.$letter['mime_type']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Cache-Control: private, no-store');
header('Content-Length: '.filesize($path));
header("Content-Disposition: attachment; filename=\"request-letter-".$letter['attachment_id'].'.'.pathinfo($letter['stored_file_name'],PATHINFO_EXTENSION)."\"; filename*=UTF-8''".rawurlencode($letter['original_file_name']));
readfile($path);exit();
