param(
    [Parameter(Mandatory = $true)][ValidateSet("backup", "external", "operations")][string]$Task,
    [string]$ExternalBackupDirectory
)

$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$logDirectory = Join-Path $projectRoot ".tmp\operations"
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
$stamp = (Get-Date).ToUniversalTime().ToString("yyyyMMddTHHmmssZ")
$log = Join-Path $logDirectory "$stamp-$Task.log"

try {
    "[$((Get-Date).ToUniversalTime().ToString('o'))] START $Task" | Tee-Object -FilePath $log
    switch ($Task) {
        "backup" { & (Join-Path $PSScriptRoot "backup.ps1") 2>&1 | Tee-Object -FilePath $log -Append }
        "external" {
            if (-not $ExternalBackupDirectory) { throw "Informe ExternalBackupDirectory para a cópia externa." }
            & (Join-Path $PSScriptRoot "copy-backup-external.ps1") -Destination $ExternalBackupDirectory 2>&1 | Tee-Object -FilePath $log -Append
        }
        "operations" { & (Join-Path $PSScriptRoot "operations-check.ps1") 2>&1 | Tee-Object -FilePath $log -Append }
    }
    "[$((Get-Date).ToUniversalTime().ToString('o'))] OK $Task" | Tee-Object -FilePath $log -Append
} catch {
    "[$((Get-Date).ToUniversalTime().ToString('o'))] ERROR $Task $($_.Exception.Message)" | Tee-Object -FilePath $log -Append
    exit 1
}
