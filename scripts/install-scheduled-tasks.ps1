param(
    [string]$BackupTime = "03:00",
    [string]$ExternalBackupTime = "03:15",
    [string]$ExternalBackupDirectory,
    [string]$CheckTime = "03:30"
)

$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$powerShell = (Get-Command powershell.exe).Source
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)

function Install-ProjectTask([string]$Name, [string]$Arguments, [string]$At) {
    $time = [DateTime]::ParseExact($At, "HH:mm", [Globalization.CultureInfo]::InvariantCulture)
    $runner = Join-Path $PSScriptRoot "run-operational-task.ps1"
    $action = New-ScheduledTaskAction -Execute $powerShell -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$runner`" $Arguments" -WorkingDirectory $projectRoot
    $trigger = New-ScheduledTaskTrigger -Daily -At $time
    Register-ScheduledTask -TaskName $Name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "Igreja App: rotina operacional gerenciada pelo projeto." -Force | Out-Null
}

Install-ProjectTask "IgrejaApp-DailyBackup" "-Task backup" $BackupTime
if ($ExternalBackupDirectory) {
    if ($ExternalBackupDirectory.Contains('"')) { throw "Destino externo contém caractere inválido." }
    Install-ProjectTask "IgrejaApp-DailyExternalBackup" "-Task external -ExternalBackupDirectory `"$ExternalBackupDirectory`"" $ExternalBackupTime
}
Install-ProjectTask "IgrejaApp-DailyOperationsCheck" "-Task operations" $CheckTime
Write-Output "SCHEDULE_OK backup=$BackupTime external=$([bool]$ExternalBackupDirectory) operations_check=$CheckTime user=$env:USERNAME"
