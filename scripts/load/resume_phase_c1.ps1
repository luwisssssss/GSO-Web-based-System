$ErrorActionPreference='Stop'
$dir=(Get-Content -Raw scripts/load/latest-phase-c1-evidence.txt).Trim()
$m=Get-Content -Raw "$dir/manifest.private.json" | ConvertFrom-Json
$meta=Get-Content -Raw "$dir/run_metadata.json" | ConvertFrom-Json
if($m.database -notmatch '^gso_load_test_phase_c1_\d{8}_\d{6}$' -or $m.database -ne $meta.database){throw 'Existing C1 database/evidence mismatch.'}
if((Get-FileHash "$dir/frozen.tar" -Algorithm SHA256).Hash -ne $meta.frozen_archive_sha256){throw 'Frozen archive changed.'}
git diff --exit-code $meta.baseline -- . ':!scripts/load'
if($LASTEXITCODE){throw 'Production changed.'}
$env:GSO_APP_ENV='test';$env:GSO_APP_DEBUG='0';$env:GSO_APP_KEY=$m.app_key
$env:GSO_DB_HOST='localhost';$env:GSO_DB_PORT='3306';$env:GSO_DB_NAME=$m.database;$env:GSO_DB_USERNAME=$m.runtime_user;$env:GSO_DB_PASSWORD=$m.runtime_password
$env:GSO_LOAD_EVIDENCE=$dir;$env:GSO_LOAD_URL='http://127.0.0.1:8122/GSO_WebSystem';$env:GSO_APP_URL=$env:GSO_LOAD_URL
$stamp=$m.database.Substring('gso_load_test_phase_c1_'.Length)
$env:GSO_SESSION_NAME='GSOC1'+$stamp.Replace('_','');$env:GSO_SESSION_SECURE='0'
$env:GSO_REQUEST_LETTER_DIR="$dir/request_letters";$env:GSO_ALLOW_LOCAL_MAIL_FALLBACK='1';$env:GSO_MAIL_OUTBOX_DIR="$dir/mail_outbox";$env:GSO_MAIL_TEST_TRANSPORT=''
$env:GSO_SMTP_HOST='';$env:GSO_SMTP_USERNAME='';$env:GSO_SMTP_PASSWORD='';$env:GSO_SMTP_FROM='no-reply@example.invalid';$env:AI_PROVIDER='disabled';$env:AI_API_KEY=''
$attempt=(@(Get-ChildItem $dir -Filter 'resume_*_execution.json').Count+1).ToString('00')
$resumeConfig="$dir/httpd_resume_$attempt.conf"
(Get-Content -Raw "$dir/httpd.conf").Replace("$dir/apache.pid","$dir/apache_resume_$attempt.pid") | Set-Content $resumeConfig -Encoding ASCII
& C:/xampp/apache/bin/httpd.exe -t -f $resumeConfig
if($LASTEXITCODE){throw 'Existing Apache configuration invalid.'}
$server=Start-Process C:/xampp/apache/bin/httpd.exe -ArgumentList @('-D','FOREGROUND','-f',"`"$resumeConfig`"") -WindowStyle Hidden -PassThru -RedirectStandardOutput "$dir/resume_$($attempt)_apache_stdout.log" -RedirectStandardError "$dir/resume_$($attempt)_apache_stderr.log"
try{
    Start-Sleep -Seconds 2
    $h=Invoke-RestMethod 'http://127.0.0.1:8122/phase-c1-control/health.php'
    if($h.database -ne $m.database -or !$h.smtp_disabled -or !$h.local_fallback -or !$h.smtp_config_rejected){throw 'Existing C1 health gate failed.'}
    Write-Output "Resuming existing $($m.database); preserving completed races and snapshots."
    & C:/xampp/php/php.exe scripts/load/c1_review_return_result.php
    if($LASTEXITCODE){throw 'Pending race outcome is not resolved; no further races allowed.'}
    $prior=$ErrorActionPreference;$ErrorActionPreference='Continue'
    try{& C:/xampp/php/php.exe scripts/load/c1_races.php 2>&1 | Tee-Object "$dir/resume_$($attempt)_console.txt";$exit=$LASTEXITCODE}finally{$ErrorActionPreference=$prior}
    @{exit_code=$exit;finished=(Get-Date).ToString('o')} | ConvertTo-Json | Set-Content "$dir/resume_$($attempt)_execution.json"
    if($exit){throw 'STOP C1: preserved failure; no production repair.'}
}finally{if(!$server.HasExited){& taskkill.exe /PID $server.Id /T /F | Out-Null};git status --short}
