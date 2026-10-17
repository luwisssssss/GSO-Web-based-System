<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function c1ExpectedContenderResponse(array $response,string $label):bool {
    if($response['status']===302)return true;
    if(!str_starts_with($label,'return_submit_')||$response['status']!==200)return false;
    $html=preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i','',(string)$response['body']);
    return str_contains($html,'This request is no longer eligible for return submission.')
        && !preg_match('/GSOERR-|<b>\s*(?:Warning|Fatal error|Notice|Deprecated)\s*<\/b>|Uncaught (?:PDOException|Error|Exception)/i',$html);
}
