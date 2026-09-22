param(
    [string]$OutputDirectory = "backups",
    [ValidateRange(1, 3650)][int]$RetentionDays = 14
)

$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$backupRoot = [System.IO.Path]::GetFullPath((Join-Path $projectRoot $OutputDirectory))
if (-not $backupRoot.StartsWith($projectRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "O diretório de backup deve permanecer dentro do projeto."
}

New-Item -ItemType Directory -Force -Path $backupRoot | Out-Null
$stamp = (Get-Date).ToUniversalTime().ToString("yyyyMMddTHHmmssZ")
$target = Join-Path $backupRoot $stamp
New-Item -ItemType Directory -Path $target | Out-Null
$remoteDump = "/tmp/igreja-$stamp.dump"
$remoteUploads = "/tmp/igreja-uploads-$stamp.tar.gz"

function Assert-Exit([string]$Step) {
    if ($LASTEXITCODE -ne 0) { throw "Falha em $Step (código $LASTEXITCODE)." }
}

try {
    & docker compose ps --status running --services | Out-Null
    Assert-Exit "verificar o Docker Compose"

    & docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc -f "$1"' -- $remoteDump
    Assert-Exit "gerar o dump PostgreSQL"
    & docker compose cp "postgres:$remoteDump" (Join-Path $target "database.dump") | Out-Null
    Assert-Exit "copiar o dump PostgreSQL"

    & docker compose exec -T php tar -C /var/www/api/var/uploads -czf $remoteUploads .
    Assert-Exit "arquivar os uploads"
    & docker compose cp "php:$remoteUploads" (Join-Path $target "uploads.tar.gz") | Out-Null
    Assert-Exit "copiar os uploads"

    & docker compose exec -T postgres pg_restore --list $remoteDump | Out-Null
    Assert-Exit "validar a estrutura do dump"
    & docker compose exec -T php tar -tzf $remoteUploads | Out-Null
    Assert-Exit "validar o arquivo de uploads"

    $databaseFile = Get-Item (Join-Path $target "database.dump")
    $uploadsFile = Get-Item (Join-Path $target "uploads.tar.gz")
    $migration = (& docker compose exec -T php php bin/console doctrine:migrations:current).Trim()
    Assert-Exit "consultar a migration atual"
    $manifest = [ordered]@{
        created_at_utc = (Get-Date).ToUniversalTime().ToString("o")
        postgres_image = "postgres:17-bookworm"
        migration = $migration
        database = [ordered]@{ file = $databaseFile.Name; bytes = $databaseFile.Length; sha256 = (Get-FileHash $databaseFile.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
        uploads = [ordered]@{ file = $uploadsFile.Name; bytes = $uploadsFile.Length; sha256 = (Get-FileHash $uploadsFile.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
    }
    $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $target "manifest.json") -Encoding UTF8

    $cutoff = (Get-Date).ToUniversalTime().AddDays(-$RetentionDays)
    Get-ChildItem -LiteralPath $backupRoot -Directory | Where-Object {
        $_.Name -match '^\d{8}T\d{6}Z$' -and $_.LastWriteTimeUtc -lt $cutoff
    } | ForEach-Object {
        $resolved = [System.IO.Path]::GetFullPath($_.FullName)
        if (-not $resolved.StartsWith($backupRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "Retenção recusou caminho fora da raiz de backup."
        }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }

    Write-Output "BACKUP_OK $target"
} finally {
    & docker compose exec -T postgres rm -f $remoteDump 2>$null | Out-Null
    & docker compose exec -T -u root php rm -f $remoteUploads 2>$null | Out-Null
}
