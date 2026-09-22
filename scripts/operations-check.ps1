param(
    [string]$BaseUrl = "https://api.guitupinamba.dev",
    [string]$BackupDirectory = "backups",
    [ValidateRange(1, 720)][int]$MaxBackupAgeHours = 30
)

$ErrorActionPreference = "Stop"
foreach ($endpoint in @("health", "ready")) {
    $response = Invoke-RestMethod -Uri "$BaseUrl/api/$endpoint" -TimeoutSec 15
    if ($response.status -ne "ok") { throw "Falha em /api/$endpoint." }
}

$required = @("postgres", "php", "nginx", "web")
$running = @(& docker compose ps --status running --services)
if ($LASTEXITCODE -ne 0) { throw "Não foi possível consultar os containers." }
$missing = @($required | Where-Object { $_ -notin $running })
if ($missing.Count -gt 0) { throw "Containers ausentes: $($missing -join ', ')." }

& docker compose exec -T php php bin/console doctrine:migrations:up-to-date --no-interaction | Out-Null
if ($LASTEXITCODE -ne 0) { throw "Há migrations pendentes ou a consulta falhou." }

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$backupRoot = Join-Path $root $BackupDirectory
$latest = Get-ChildItem -LiteralPath $backupRoot -Filter manifest.json -File -Recurse | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
if (-not $latest) { throw "Nenhum backup com manifest foi encontrado." }
$age = (New-TimeSpan -Start $latest.LastWriteTimeUtc -End (Get-Date).ToUniversalTime()).TotalHours
if ($age -gt $MaxBackupAgeHours) { throw "Backup mais recente tem $([math]::Round($age, 1)) horas." }

$drive = Get-PSDrive -Name ([System.IO.Path]::GetPathRoot($root).TrimEnd('\').TrimEnd(':'))
$freePercent = 100 * $drive.Free / ($drive.Used + $drive.Free)
if ($freePercent -lt 10) { throw "Espaço livre abaixo de 10%." }
Write-Output "OPERATIONS_OK backup_age_hours=$([math]::Round($age, 1)) disk_free_percent=$([math]::Round($freePercent, 1))"
