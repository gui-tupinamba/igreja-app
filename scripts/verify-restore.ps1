param([Parameter(Mandatory = $true)][string]$BackupDirectory)

$ErrorActionPreference = "Stop"
$backupPath = (Resolve-Path -LiteralPath $BackupDirectory).Path
$manifestPath = Join-Path $backupPath "manifest.json"
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) { throw "manifest.json não encontrado." }
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$databasePath = Join-Path $backupPath $manifest.database.file
$uploadsPath = Join-Path $backupPath $manifest.uploads.file
foreach ($file in @($databasePath, $uploadsPath)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Arquivo ausente: $file" }
}
if ((Get-FileHash $databasePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $manifest.database.sha256) { throw "Checksum inválido para database.dump." }
if ((Get-FileHash $uploadsPath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $manifest.uploads.sha256) { throw "Checksum inválido para uploads.tar.gz." }

$suffix = ([Guid]::NewGuid().ToString("N")).Substring(0, 10)
$database = "igreja_restore_check_$suffix"
if ($database -notmatch '^igreja_restore_check_[a-f0-9]{10}$') { throw "Nome de banco temporário recusado." }
$remoteDump = "/tmp/$database.dump"
$remoteUploads = "/tmp/$database-uploads.tar.gz"

function Assert-Exit([string]$Step) {
    if ($LASTEXITCODE -ne 0) { throw "Falha em $Step (código $LASTEXITCODE)." }
}

$postgresUser = (& docker compose exec -T postgres sh -c 'printf %s "$POSTGRES_USER"').Trim()
Assert-Exit "consultar o usuário PostgreSQL"
try {
    & docker compose cp $databasePath "postgres:$remoteDump" | Out-Null
    Assert-Exit "copiar o dump para validação"
    & docker compose exec -T postgres createdb -U $postgresUser $database
    Assert-Exit "criar o banco isolado"
    & docker compose exec -T postgres pg_restore -U $postgresUser -d $database --exit-on-error $remoteDump
    Assert-Exit "restaurar o banco isolado"

    $tables = [int]((& docker compose exec -T postgres psql -U $postgresUser -d $database -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'").Trim())
    Assert-Exit "contar tabelas restauradas"
    $migrations = [int]((& docker compose exec -T postgres psql -U $postgresUser -d $database -tAc "SELECT count(*) FROM doctrine_migration_versions").Trim())
    Assert-Exit "validar migrations restauradas"
    if ($tables -lt 10 -or $migrations -lt 1) { throw "Restauração incompleta: $tables tabelas e $migrations migrations." }

    & docker compose cp $uploadsPath "php:$remoteUploads" | Out-Null
    Assert-Exit "copiar o arquivo de uploads para validação"
    & docker compose exec -T php tar -tzf $remoteUploads | Out-Null
    Assert-Exit "validar o arquivo de uploads"
    Write-Output "RESTORE_OK database=$database tables=$tables migrations=$migrations"
} finally {
    if ($postgresUser) { & docker compose exec -T postgres dropdb -U $postgresUser --if-exists $database 2>$null | Out-Null }
    & docker compose exec -T postgres rm -f $remoteDump 2>$null | Out-Null
    & docker compose exec -T -u root php rm -f $remoteUploads 2>$null | Out-Null
}
