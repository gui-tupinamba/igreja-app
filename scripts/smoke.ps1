param([string]$BaseUrl = 'http://127.0.0.1:8080')

$ErrorActionPreference = 'Stop'
foreach ($endpoint in @('health', 'ready')) {
    $response = Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/api/$endpoint" -TimeoutSec 10
    $body = $response.Content | ConvertFrom-Json
    if ($response.StatusCode -ne 200 -or $body.status -ne 'ok') {
        throw "Falha em /api/$endpoint."
    }
    Write-Output "PASS GET /api/${endpoint}: 200 e status ok."
}
