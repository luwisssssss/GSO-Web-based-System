<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function c2aExpectedContenderResponse(array $response,string $label):bool {
    if($response['status']===302)return true;
    if($response['status']!==200)return false;
    $html=preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i','',(string)$response['body']);
    $safe=false;
    foreach(['no pending reschedule is eligible','Only your approved future facility reservation','already completed or cancelled','Only a due Scheduled occurrence','Select an active preventive plan'] as $message)if(str_contains($html,$message))$safe=true;
    return $safe
        && !preg_match('/GSOERR-|<b>\s*(?:Warning|Fatal error|Notice|Deprecated)\s*<\/b>|Uncaught (?:PDOException|Error|Exception)/i',$html);
}
