param(
    [string]$BackupTime = "03:00",
    [string]$CheckTime = "03:30"
)

$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$powerShell = (Get-Command powershell.exe).Source
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)

function Install-ProjectTask([string]$Name, [string]$Script, [string]$At) {
    $time = [DateTime]::ParseExact($At, "HH:mm", [Globalization.CultureInfo]::InvariantCulture)
    $action = New-ScheduledTaskAction -Execute $powerShell -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$Script`"" -WorkingDirectory $projectRoot
    $trigger = New-ScheduledTaskTrigger -Daily -At $time
    Register-ScheduledTask -TaskName $Name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "Igreja App: rotina operacional gerenciada pelo projeto." -Force | Out-Null
}

Install-ProjectTask "IgrejaApp-DailyBackup" (Join-Path $PSScriptRoot "backup.ps1") $BackupTime
Install-ProjectTask "IgrejaApp-DailyOperationsCheck" (Join-Path $PSScriptRoot "operations-check.ps1") $CheckTime
Write-Output "SCHEDULE_OK backup=$BackupTime operations_check=$CheckTime user=$env:USERNAME"
