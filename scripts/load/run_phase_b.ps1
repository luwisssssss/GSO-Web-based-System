param([int]$Port = 8121)
$ErrorActionPreference = 'Stop'
$root = (Get-Location).Path.Replace('\','/')
if ($root -ne 'C:/xampp/htdocs/GSO_WebSystem') { throw 'Run from the project root.' }
$baseline = 'e2cbea932ed4073a814361c455c4f3086fd768a9'
$checkpoint = '92e0242077675a842420d1def52bff2e883b22f8'
if ((git rev-parse HEAD) -ne $checkpoint) { throw 'Wrong baseline HEAD.' }
git diff --exit-code $baseline -- . ':!scripts/load'
if ($LASTEXITCODE) { throw 'Tracked application changes detected.' }
$unexpected = @(git status --porcelain | Where-Object { $_ -notmatch '^.{3}scripts/load/' })
if ($unexpected.Count) { throw 'Working tree has changes outside the new load assets.' }
$php = 'C:/xampp/php/php.exe'; $k6 = 'C:/Program Files/k6/k6.exe'
if ((& $k6 version) -notmatch 'v2\.2\.0 ') { throw 'Expected k6 v2.2.0.' }
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$dir = (Join-Path $env:TEMP ('GSO_PHASE_B_' + $stamp)).Replace('\','/')
New-Item -ItemType Directory -Path $dir,"$dir/sessions","$dir/tmp","$dir/control" | Out-Null
Set-Content -LiteralPath "$root/scripts/load/latest-phase-b-evidence.txt" -Value $dir
$env:GSO_APP_ENV = 'test'; $env:GSO_APP_DEBUG = '0'; $env:GSO_APP_KEY = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
$env:GSO_DB_HOST = 'localhost'; $env:GSO_DB_PORT = '3306'; $env:GSO_DB_USERNAME = 'root'; $env:GSO_DB_PASSWORD = ''
$env:GSO_DB_NAME = 'gso_load_test_phase_b_' + $stamp; $env:GSO_LOAD_EVIDENCE = $dir
$env:GSO_SESSION_NAME = 'GSOPHASEB' + $stamp.Replace('_',''); $env:GSO_SESSION_SECURE = '0'
$env:GSO_LOAD_URL = "http://127.0.0.1:$Port/GSO_WebSystem"
$env:GSO_APP_URL = $env:GSO_LOAD_URL
$env:AI_PROVIDER='disabled'; $env:AI_API_KEY=''; $env:GSO_LOAD_PHASE='B'
foreach ($file in @('common','prepare','integrity','phase_b_manifest','telemetry')) { & $php -l "scripts/load/$file.php"; if ($LASTEXITCODE) { throw 'Harness PHP lint failed.' } }
& $php scripts/load/prepare.php
if ($LASTEXITCODE) { throw 'Fixture preparation failed; no workload was run.' }
& $php scripts/load/phase_b_manifest.php
if ($LASTEXITCODE) { throw 'Phase B manifest preparation failed.' }
$manifest = Get-Content -Raw -LiteralPath "$dir/manifest.private.json" | ConvertFrom-Json
$env:GSO_LOAD_MANIFEST = "$dir/manifest.private.json"
$env:GSO_APP_KEY = $manifest.app_key
$env:GSO_LOAD_STAGE = 'before'; & $php scripts/load/integrity.php
if ($LASTEXITCODE) { throw 'Invalid test fixture; no workload was run.' }
# HTTP worker inherits ONLY the database-scoped runtime credentials.
$env:GSO_DB_USERNAME = $manifest.runtime_user; $env:GSO_DB_PASSWORD = $manifest.runtime_password
$guard = @'
<?php
if (getenv('GSO_APP_ENV') !== 'test' || !preg_match('/\Agso_load_test_phase_b_[0-9]{8}_[0-9]{6}\z/', (string)getenv('GSO_DB_NAME')) || !str_starts_with((string)getenv('GSO_DB_USERNAME'), 'gso_load_')) { http_response_code(503); exit('Load isolation guard refused request'); }
foreach (['mail','fsockopen','pfsockopen','stream_socket_client'] as $f) if (function_exists($f)) { http_response_code(503); exit('SMTP isolation guard refused request'); }
'@
Set-Content -LiteralPath "$dir/guard.php" -Value $guard -Encoding ASCII
$health = @'
<?php
$p=new PDO('mysql:host=localhost;dbname='.getenv('GSO_DB_NAME').';charset=utf8mb4',getenv('GSO_DB_USERNAME'),getenv('GSO_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
header('Content-Type: application/json');echo json_encode(['database'=>$p->query('SELECT DATABASE()')->fetchColumn(),'current_user'=>$p->query('SELECT CURRENT_USER()')->fetchColumn(),'mariadb'=>$p->query('SELECT VERSION()')->fetchColumn(),'php'=>PHP_VERSION,'smtp_functions_disabled'=>!function_exists('mail')&&!function_exists('stream_socket_client')&&!function_exists('fsockopen')&&!function_exists('pfsockopen'),'grants'=>$p->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)]);
'@
Set-Content -LiteralPath "$dir/control/health.php" -Value $health -Encoding ASCII
# disable_functions is a PHP startup setting; give this Apache its own ini.
$privateIni = (Get-Content -Raw C:/xampp/php/php.ini) -replace '(?m)^disable_functions\s*=.*$', 'disable_functions=mail,fsockopen,pfsockopen,stream_socket_client'
Set-Content -LiteralPath "$dir/php.ini" -Value $privateIni -Encoding ASCII
$conf = @"
ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:$Port
ServerName localhost:$Port
PidFile "$dir/apache.pid"
ErrorLog "$dir/apache_error.log"
LogFormat "%t \"%r\" %>s %D" phasea
CustomLog "$dir/access.log" phasea
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
LoadModule status_module modules/mod_status.so
ExtendedStatus On
LoadFile "C:/xampp/php/php8ts.dll"
LoadFile "C:/xampp/php/libpq.dll"
LoadFile "C:/xampp/php/libsqlite3.dll"
LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
PHPINIDir "$dir"
TypesConfig conf/mime.types
DocumentRoot "C:/xampp/htdocs"
DirectoryIndex index.php
php_admin_value auto_prepend_file "$dir/guard.php"
php_admin_value disable_functions "mail,fsockopen,pfsockopen,stream_socket_client"
php_admin_value error_log "$dir/php_error.log"
php_admin_flag log_errors On
php_admin_flag display_errors Off
php_admin_value session.save_path "$dir/sessions"
php_admin_value upload_tmp_dir "$dir/tmp"
<Directory "C:/xampp/htdocs">
AllowOverride All
Require local
Options FollowSymLinks
</Directory>
Alias /phase-b-control/ "$dir/control/"
<Location /phase-b-server-status>
SetHandler server-status
Require local
</Location>
<Directory "$dir/control">
Require local
AllowOverride None
Options -Indexes
</Directory>
<FilesMatch "\.php$">
SetHandler application/x-httpd-php
</FilesMatch>
"@
Set-Content -LiteralPath "$dir/httpd.conf" -Value $conf -Encoding ASCII
& C:/xampp/apache/bin/httpd.exe -t -f "$dir/httpd.conf"
if ($LASTEXITCODE) { throw 'Isolated Apache configuration invalid.' }
$mysqlLog = 'C:/xampp/mysql/data/mysql_error.log'
$mysqlOffset = if (Test-Path $mysqlLog) { (Get-Item $mysqlLog).Length } else { 0 }
@{baseline=$baseline; checkpoint=$checkpoint; database=$env:GSO_DB_NAME; started=(Get-Date).ToString('o'); mariadb_log=$mysqlLog; mariadb_log_offset=$mysqlOffset} | ConvertTo-Json | Set-Content "$dir/run_metadata.json"
$server = Start-Process -FilePath C:/xampp/apache/bin/httpd.exe -ArgumentList @('-D','FOREGROUND','-f',"`"$dir/httpd.conf`"") -WindowStyle Hidden -PassThru -RedirectStandardOutput "$dir/apache_stdout.log" -RedirectStandardError "$dir/apache_stderr.log"
try {
  Start-Sleep -Seconds 2
  $healthResult = Invoke-RestMethod "http://127.0.0.1:$Port/phase-b-control/health.php"
  if ($healthResult.database -ne $manifest.database -or !$healthResult.smtp_functions_disabled -or $healthResult.current_user -ne ($manifest.runtime_user+'@localhost')) { throw 'Isolation health check failed.' }
  # SHOW GRANTS may contain an authentication hash; retain it only in private evidence.
  $healthResult | ConvertTo-Json -Depth 5 | Set-Content "$dir/server_health.private.json"
  Write-Output "Isolation verified: $($manifest.database); SMTP disabled; loopback port $Port."
  $machine=@{computer=(Get-CimInstance Win32_ComputerSystem | Select-Object Manufacturer,Model,TotalPhysicalMemory);cpu=(Get-CimInstance Win32_Processor | Select-Object Name,NumberOfCores,NumberOfLogicalProcessors);os=(Get-CimInstance Win32_OperatingSystem | Select-Object Caption,Version,BuildNumber);disk=(Get-CimInstance Win32_DiskDrive | Select-Object Model,Size)}
  $machine | ConvertTo-Json -Depth 5 | Set-Content "$dir/machine.json"
  $monitorCode="& ([scriptblock]::Create((Get-Content -Raw scripts/load/monitor.ps1))) -Evidence '$dir' -Port $Port"
  $encoded=[Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($monitorCode))
  $monitor=Start-Process powershell.exe -ArgumentList @('-NoProfile','-EncodedCommand',$encoded) -WindowStyle Hidden -PassThru -WorkingDirectory $root
  foreach ($mode in @('ten','fifteen')) {
    $env:GSO_LOAD_MODE = $mode
    $timer = [Diagnostics.Stopwatch]::StartNew()
    $priorPreference=$ErrorActionPreference; $ErrorActionPreference='Continue'
    try { & $k6 run --quiet --out "json=$dir/$($mode)_samples.json" scripts/load/phase_b.js 2>&1 | Tee-Object "$dir/$($mode)_console.txt"; $runExit=$LASTEXITCODE } finally { $ErrorActionPreference=$priorPreference }
    $runExit = $LASTEXITCODE; $timer.Stop()
    @{mode=$mode;seconds=$timer.Elapsed.TotalSeconds;exit_code=$runExit} | ConvertTo-Json | Set-Content "$dir/$($mode)_execution.json"
    if ($runExit) { throw "STOP: $mode workload failed. Do not proceed or repair production code." }
    $env:GSO_LOAD_STAGE = $mode; & $php scripts/load/integrity.php
    if ($LASTEXITCODE) { throw "STOP: $mode integrity failed." }
    if ((Test-Path "$dir/php_error.log") -and (Get-Item "$dir/php_error.log").Length -gt 0) { throw "STOP: PHP log entries after $mode; inspect before continuing." }
    if (Select-String -LiteralPath "$dir/apache_error.log" -Pattern '\[(?:php\d?:error|.*:warn|.*:error|.*:crit|.*:alert|.*:emerg)\]' -Quiet) { throw "STOP: Apache errors after $mode." }
    $stream=[IO.File]::Open($mysqlLog,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::ReadWrite)
    try { $null=$stream.Seek($mysqlOffset,[IO.SeekOrigin]::Begin); $reader=New-Object IO.StreamReader($stream); $delta=$reader.ReadToEnd(); $delta | Set-Content "$dir/mariadb_$($mode)_log_delta.txt" } finally { $stream.Dispose() }
    if ($delta -match '(?i)fatal|uncaught|SQLSTATE|deadlock|lock wait timeout|too many connections|memory exhausted|\[ERROR\]') { throw "STOP: MariaDB log error after $mode." }
    $dbStats=& $php scripts/load/telemetry.php | ConvertFrom-Json
    $dbStats | ConvertTo-Json | Set-Content "$dir/$($mode)_db_status.json"
    Write-Output "GATE PASS: $mode integrity and PHP/Apache/MariaDB logs."
  }
} finally {
  Set-Content "$dir/monitor.stop" -Value 'stop'
  if($monitor){ if(!$monitor.WaitForExit(10000)){Stop-Process -Id $monitor.Id -ErrorAction SilentlyContinue} }
  if (!$server.HasExited) { & taskkill.exe /PID $server.Id /T /F | Out-Null }
  if (Test-Path $mysqlLog) {
    $stream = [IO.File]::Open($mysqlLog,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::ReadWrite)
    try { $null=$stream.Seek($mysqlOffset,[IO.SeekOrigin]::Begin); $reader=New-Object IO.StreamReader($stream); $reader.ReadToEnd() | Set-Content "$dir/mariadb_log_delta.txt" } finally { $stream.Dispose() }
  }
  Write-Output "Evidence: $dir"
  git status --short
}

