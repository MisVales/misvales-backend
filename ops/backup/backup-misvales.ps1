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

$databaseFile = Join-Path $backupDirectory 'mariadb.sql'
$databaseCommand = 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --single-transaction --routines --triggers --events --hex-blob --user="$MARIADB_USER" "$MARIADB_DATABASE" > /var/lib/mysql/misvales-backup.sql'
Push-Location $composePath
try {
    & docker compose exec -T mariadb sh -ec $databaseCommand 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'mariadb-dump terminó con error.' }
    & docker compose cp mariadb:/var/lib/mysql/misvales-backup.sql $databaseFile | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'No fue posible extraer el respaldo de MariaDB.' }
    & docker compose exec -T mariadb sh -ec 'rm -f /var/lib/mysql/misvales-backup.sql' | Out-Null
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
