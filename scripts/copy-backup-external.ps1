param(
    [Parameter(Mandatory = $true)][string]$Destination,
    [string]$SourceDirectory = "backups",
    [ValidateRange(1, 3650)][int]$RetentionDays = 30
)

$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$sourceRoot = [System.IO.Path]::GetFullPath((Join-Path $projectRoot $SourceDirectory))
$destinationRoot = [System.IO.Path]::GetFullPath($Destination)
if (-not $sourceRoot.StartsWith($projectRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "A origem deve permanecer dentro do projeto."
}
if ($destinationRoot.StartsWith($projectRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "O destino externo não pode ficar dentro do projeto."
}

New-Item -ItemType Directory -Force -Path $destinationRoot | Out-Null
$latestManifest = Get-ChildItem -LiteralPath $sourceRoot -Filter manifest.json -File -Recurse |
    Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
if (-not $latestManifest) { throw "Nenhum backup completo foi encontrado." }
$source = $latestManifest.Directory
if ($source.Name -notmatch '^\d{8}T\d{6}Z$') { throw "Nome do backup de origem inválido." }
$manifest = Get-Content -LiteralPath $latestManifest.FullName -Raw | ConvertFrom-Json

function Assert-BackupFile([string]$Root, $Entry) {
    if ([System.IO.Path]::GetFileName([string]$Entry.file) -ne [string]$Entry.file) { throw "Nome de arquivo inválido no manifesto." }
    $file = Join-Path $Root $Entry.file
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Arquivo ausente: $file" }
    if ((Get-FileHash $file -Algorithm SHA256).Hash.ToLowerInvariant() -ne $Entry.sha256) { throw "Checksum inválido: $file" }
}

Assert-BackupFile $source.FullName $manifest.database
Assert-BackupFile $source.FullName $manifest.uploads
$target = Join-Path $destinationRoot $source.Name
if (-not (Test-Path -LiteralPath $target)) {
    $staging = Join-Path $destinationRoot (".{0}.partial-{1}" -f $source.Name, ([Guid]::NewGuid().ToString("N")))
    try {
        New-Item -ItemType Directory -Path $staging | Out-Null
        Copy-Item -LiteralPath $latestManifest.FullName -Destination $staging
        Copy-Item -LiteralPath (Join-Path $source.FullName $manifest.database.file) -Destination $staging
        Copy-Item -LiteralPath (Join-Path $source.FullName $manifest.uploads.file) -Destination $staging
        Assert-BackupFile $staging $manifest.database
        Assert-BackupFile $staging $manifest.uploads
        Move-Item -LiteralPath $staging -Destination $target
    } finally {
        if (Test-Path -LiteralPath $staging) {
            $resolvedStaging = [System.IO.Path]::GetFullPath($staging)
            if (-not $resolvedStaging.StartsWith($destinationRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
                throw "Limpeza recusou caminho fora do destino externo."
            }
            Remove-Item -LiteralPath $resolvedStaging -Recurse -Force
        }
    }
}
if (-not (Test-Path -LiteralPath (Join-Path $target "manifest.json") -PathType Leaf)) { throw "Manifesto ausente no destino externo." }
Assert-BackupFile $target $manifest.database
Assert-BackupFile $target $manifest.uploads

$cutoff = (Get-Date).ToUniversalTime().AddDays(-$RetentionDays)
Get-ChildItem -LiteralPath $destinationRoot -Directory | Where-Object {
    $_.Name -match '^\d{8}T\d{6}Z$' -and $_.LastWriteTimeUtc -lt $cutoff
} | ForEach-Object {
    $resolved = [System.IO.Path]::GetFullPath($_.FullName)
    if (-not $resolved.StartsWith($destinationRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Retenção recusou caminho fora do destino externo."
    }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}

Write-Output "EXTERNAL_BACKUP_OK $target"
