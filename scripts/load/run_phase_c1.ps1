param([int]$Port=8122)
$ErrorActionPreference='Stop'
$root=(Get-Location).Path.Replace('\','/')
$baseline='e2cbea932ed4073a814361c455c4f3086fd768a9'
$checkpoint='46cb46c90e3b7e24c2171ec6c385e4f97997907b'
if((git rev-parse HEAD) -ne $checkpoint){throw 'Wrong Phase B checkpoint.'}
git diff --exit-code $baseline -- . ':!scripts/load'
if($LASTEXITCODE){throw 'Production files differ from frozen baseline.'}
if(@(git status --porcelain | Where-Object {$_ -notmatch '^.{3}scripts/load/'}).Count){throw 'Changes outside scripts/load.'}
$php='C:/xampp/php/php.exe'
foreach($file in @('common','prepare','c1_integrity','c1_races')){& $php -l "scripts/load/$file.php";if($LASTEXITCODE){throw 'Harness lint failed.'}}
$stamp=Get-Date -Format 'yyyyMMdd_HHmmss'
$dir=(Join-Path $env:TEMP ('GSO_PHASE_C1_'+$stamp)).Replace('\','/')
New-Item -ItemType Directory -Path $dir,"$dir/public/GSO_WebSystem","$dir/control","$dir/sessions","$dir/tmp","$dir/mail_outbox" | Out-Null
Set-Content scripts/load/latest-phase-c1-evidence.txt -Value $dir
# Export tracked, frozen production files; no .env, mail.local.php or developer secrets.
git archive --format=tar --output="$dir/frozen.tar" $baseline
if($LASTEXITCODE){throw 'Frozen export failed.'}
tar.exe -xf "$dir/frozen.tar" -C "$dir/public/GSO_WebSystem"
if($LASTEXITCODE){throw 'Frozen extraction failed.'}
if((Test-Path "$dir/public/GSO_WebSystem/.env") -or (Test-Path "$dir/public/GSO_WebSystem/config/mail.local.php")){throw 'Secret override found in frozen export.'}
$env:GSO_APP_ENV='test';$env:GSO_APP_DEBUG='0';$env:GSO_APP_KEY=[guid]::NewGuid().ToString('N')+[guid]::NewGuid().ToString('N')
$env:GSO_DB_HOST='localhost';$env:GSO_DB_PORT='3306';$env:GSO_DB_USERNAME='root';$env:GSO_DB_PASSWORD=''
$env:GSO_DB_NAME='gso_load_test_phase_c1_'+$stamp;$env:GSO_LOAD_EVIDENCE=$dir
$env:GSO_SESSION_NAME='GSOC1'+$stamp.Replace('_','');$env:GSO_SESSION_SECURE='0'
$env:GSO_LOAD_URL="http://127.0.0.1:$Port/GSO_WebSystem";$env:GSO_APP_URL=$env:GSO_LOAD_URL
$env:GSO_REQUEST_LETTER_DIR="$dir/request_letters"
$env:GSO_ALLOW_LOCAL_MAIL_FALLBACK='1';$env:GSO_MAIL_OUTBOX_DIR="$dir/mail_outbox";$env:GSO_MAIL_TEST_TRANSPORT=''
$env:GSO_SMTP_HOST='';$env:GSO_SMTP_USERNAME='';$env:GSO_SMTP_PASSWORD='';$env:GSO_SMTP_FROM='no-reply@example.invalid'
$env:AI_PROVIDER='disabled';$env:AI_API_KEY=''
& $php scripts/load/prepare.php
if($LASTEXITCODE){throw 'Fresh C1 preparation failed; do not recreate existing database.'}
$manifest=Get-Content -Raw "$dir/manifest.private.json" | ConvertFrom-Json
$env:GSO_APP_KEY=$manifest.app_key;$env:GSO_DB_USERNAME=$manifest.runtime_user;$env:GSO_DB_PASSWORD=$manifest.runtime_password
$guard=@'
<?php
if(getenv('GSO_APP_ENV')!=='test'||!preg_match('/\Agso_load_test_phase_c1_\d{8}_\d{6}\z/',(string)getenv('GSO_DB_NAME'))||!str_starts_with((string)getenv('GSO_DB_USERNAME'),'gso_load_phase_c1_')){http_response_code(503);exit('C1 isolation refused');}
foreach(['mail','fsockopen','pfsockopen','stream_socket_client'] as $f)if(function_exists($f)){http_response_code(503);exit('C1 SMTP guard refused');}
'@
Set-Content "$dir/guard.php" -Value $guard -Encoding ASCII
$health=@'
<?php
require dirname(__DIR__).'/public/GSO_WebSystem/config/bootstrap.php';
require dirname(__DIR__).'/public/GSO_WebSystem/includes/mail_helper.php';
$config=require dirname(__DIR__).'/public/GSO_WebSystem/config/mail.php';
$p=new PDO('mysql:host=localhost;dbname='.getenv('GSO_DB_NAME').';charset=utf8mb4',getenv('GSO_DB_USERNAME'),getenv('GSO_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
header('Content-Type: application/json');echo json_encode(['database'=>$p->query('SELECT DATABASE()')->fetchColumn(),'current_user'=>$p->query('SELECT CURRENT_USER()')->fetchColumn(),'smtp_disabled'=>!function_exists('mail')&&!function_exists('stream_socket_client')&&!function_exists('fsockopen'),'local_fallback'=>gsoAllowLocalMailFallback(),'smtp_config_rejected'=>gsoMailConfigurationIssueDetails($config)!=='','outbox'=>gsoMailOutboxDirectory(),'schema_version'=>$p->query("SELECT setting_value FROM system_settings WHERE setting_key='schema_version'")->fetchColumn(),'php'=>PHP_VERSION,'mariadb'=>$p->query('SELECT VERSION()')->fetchColumn(),'grants'=>$p->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)]);
'@
Set-Content "$dir/control/health.php" -Value $health -Encoding ASCII
$ini=(Get-Content -Raw C:/xampp/php/php.ini) -replace '(?m)^disable_functions\s*=.*$', 'disable_functions=mail,fsockopen,pfsockopen,stream_socket_client'
Set-Content "$dir/php.ini" -Value $ini -Encoding ASCII
$conf=@"
ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:$Port
ServerName localhost:$Port
PidFile "$dir/apache.pid"
ErrorLog "$dir/apache_error.log"
LogFormat "%{begin:usec}t %{end:usec}t %>s %D \"%r\" \"%{X-GSO-C1-Contender}i\"" c1
CustomLog "$dir/access.log" c1
LoadModule access_compat_module modules/mod_access_compat.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule env_module modules/mod_env.so
LoadModule headers_module modules/mod_headers.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule alias_module modules/mod_alias.so
LoadFile "C:/xampp/php/php8ts.dll"
LoadFile "C:/xampp/php/libpq.dll"
LoadFile "C:/xampp/php/libsqlite3.dll"
LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
PHPINIDir "$dir"
TypesConfig conf/mime.types
DocumentRoot "$dir/public"
DirectoryIndex index.php
php_admin_value auto_prepend_file "$dir/guard.php"
php_admin_value error_log "$dir/php_error.log"
php_admin_flag log_errors On
php_admin_flag display_errors Off
php_admin_value session.save_path "$dir/sessions"
php_admin_value upload_tmp_dir "$dir/tmp"
<Directory "$dir/public">
AllowOverride All
Require local
Options FollowSymLinks
</Directory>
Alias /phase-c1-control/ "$dir/control/"
<Directory "$dir/control">
Require local
AllowOverride None
Options -Indexes
</Directory>
<FilesMatch "\.php$">
SetHandler application/x-httpd-php
</FilesMatch>
"@
Set-Content "$dir/httpd.conf" -Value $conf -Encoding ASCII
& C:/xampp/apache/bin/httpd.exe -t -f "$dir/httpd.conf"
if($LASTEXITCODE){throw 'Isolated C1 configuration failed.'}
$mysqlLog='C:/xampp/mysql/data/mysql_error.log'
@{baseline=$baseline;checkpoint=$checkpoint;database=$manifest.database;started=(Get-Date).ToString('o');mariadb_log=$mysqlLog;mariadb_log_offset=(Get-Item $mysqlLog).Length;frozen_archive_sha256=(Get-FileHash "$dir/frozen.tar" -Algorithm SHA256).Hash} | ConvertTo-Json | Set-Content "$dir/run_metadata.json"
$server=Start-Process C:/xampp/apache/bin/httpd.exe -ArgumentList @('-D','FOREGROUND','-f',"`"$dir/httpd.conf`"") -WindowStyle Hidden -PassThru -RedirectStandardOutput "$dir/apache_stdout.log" -RedirectStandardError "$dir/apache_stderr.log"
try {
    Start-Sleep -Seconds 2
    $h=Invoke-RestMethod "http://127.0.0.1:$Port/phase-c1-control/health.php"
    if($h.database -ne $manifest.database -or $h.current_user -ne ($manifest.runtime_user+'@localhost') -or !$h.smtp_disabled -or !$h.local_fallback -or !$h.smtp_config_rejected -or $h.schema_version -ne '20261010_008'){throw 'C1 health/isolation gate failed.'}
    $h | ConvertTo-Json -Depth 5 | Set-Content "$dir/server_health.private.json"
    Write-Output "C1 isolation verified: $($manifest.database); frozen export; SMTP disabled; existing local outbox."
    $prior=$ErrorActionPreference;$ErrorActionPreference='Continue'
    try{& $php scripts/load/c1_races.php 2>&1 | Tee-Object "$dir/c1_console.txt";$exit=$LASTEXITCODE}finally{$ErrorActionPreference=$prior}
    @{exit_code=$exit;finished=(Get-Date).ToString('o')} | ConvertTo-Json | Set-Content "$dir/execution.json"
    if($exit){throw 'STOP C1: failure evidence preserved; no production fix applied.'}
}finally{
    if(!$server.HasExited){& taskkill.exe /PID $server.Id /T /F | Out-Null}
    Write-Output "C1 private evidence: $dir"
    git status --short
}
