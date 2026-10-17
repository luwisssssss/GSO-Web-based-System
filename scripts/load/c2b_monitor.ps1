param([Parameter(Mandatory=$true)][string]$Evidence,[int]$Port=8124)
$ErrorActionPreference='Stop'
$root=(Get-Location).Path
while(!(Test-Path "$Evidence/monitor.stop")) {
    try {
        $db=& C:/xampp/php/php.exe scripts/load/telemetry.php | ConvertFrom-Json
        $cpu=Get-CimInstance Win32_PerfFormattedData_PerfOS_Processor -Filter "Name='_Total'"
        $os=Get-CimInstance Win32_OperatingSystem
        $httpd=@(Get-Process httpd -ErrorAction SilentlyContinue)
        $row=@{timestamp=(Get-Date).ToString('o');cpu_percent=[double]$cpu.PercentProcessorTime;available_ram_bytes=[long]$os.FreePhysicalMemory*1024;apache_processes=$httpd.Count;apache_working_set_bytes=($httpd | Measure-Object WorkingSet64 -Sum).Sum;database=$db}
        $status=(Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$Port/phase-c2b-server-status?auto").Content
        foreach($key in @('BusyWorkers','IdleWorkers')){if($status -match "(?m)^${key}: (\d+)"){$row[$key]=[int]$matches[1]}}
        $row | ConvertTo-Json -Compress -Depth 4 | Add-Content "$Evidence/telemetry.jsonl"
    } catch { $_.Exception.Message | Add-Content "$Evidence/monitor_errors.log" }
    Start-Sleep -Seconds 5
}
