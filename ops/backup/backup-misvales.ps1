param(
    [Parameter(Mandatory = $true)][string]$ComposeDirectory,
    [Parameter(Mandatory = $true)][string]$DestinationDirectory,
    [int]$RetentionDays = 14
)

$ErrorActionPreference = 'Stop'
$composePath = (Resolve-Path -LiteralPath $ComposeDirectory).Path
$destinationRoot = [System.IO.Path]::GetFullPath($DestinationDirectory)
[System.IO.Directory]::CreateDirectory($destinationRoot) | Out-Null
$stamp = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')
$backupDirectory = Join-Path $destinationRoot $stamp
[System.IO.Directory]::CreateDirectory($backupDirectory) | Out-Null

$databaseFile = Join-Path $backupDirectory 'postgres.dump'
$databaseCommand = 'pg_dump --format=custom --no-owner --no-acl --username=$POSTGRES_USER --dbname=$POSTGRES_DB --file=/var/lib/postgresql/data/misvales-backup.dump'
Push-Location $composePath
try {
    & docker compose exec -T postgres sh -ec $databaseCommand 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'pg_dump terminó con error.' }
    & docker compose cp postgres:/var/lib/postgresql/data/misvales-backup.dump $databaseFile | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'No fue posible extraer el dump PostgreSQL.' }
    & docker compose exec -T postgres sh -ec 'rm -f /var/lib/postgresql/data/misvales-backup.dump' | Out-Null
    & docker compose exec -T minio sh -ec 'test -d /data' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'MinIO no está disponible.' }
    & docker compose cp minio:/data (Join-Path $backupDirectory 'minio-data') | Out-Null
} finally {
    Pop-Location
}

$configFiles = @('.env.example', 'composer.lock', 'config')
foreach ($relative in $configFiles) {
    $source = Join-Path $PSScriptRoot "..\..\$relative"
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination (Join-Path $backupDirectory ([System.IO.Path]::GetFileName($source))) -Recurse }
}

$manifest = Get-ChildItem -LiteralPath $backupDirectory -Recurse -File | ForEach-Object {
    [ordered]@{ path = $_.FullName.Substring($backupDirectory.Length).TrimStart('\', '/'); bytes = $_.Length; sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
}
$manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $backupDirectory 'manifest.json') -Encoding utf8

$cutoff = [DateTime]::UtcNow.AddDays(-$RetentionDays)
Get-ChildItem -LiteralPath $destinationRoot -Directory | Where-Object { $_.CreationTimeUtc -lt $cutoff } | Remove-Item -Recurse -Force
Write-Output $backupDirectory
