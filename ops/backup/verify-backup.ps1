param(
    [Parameter(Mandatory = $true)][string]$ComposeDirectory,
    [Parameter(Mandatory = $true)][string]$BackupDirectory
)

$ErrorActionPreference = 'Stop'
$composePath = (Resolve-Path -LiteralPath $ComposeDirectory).Path
$backupPath = (Resolve-Path -LiteralPath $BackupDirectory).Path
$manifestPath = Join-Path $backupPath 'manifest.json'
$databaseFile = Join-Path $backupPath 'postgres.dump'
if (-not (Test-Path -LiteralPath $manifestPath) -or -not (Test-Path -LiteralPath $databaseFile)) { throw 'Respaldo incompleto.' }

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
foreach ($item in $manifest) {
    $path = Join-Path $backupPath $item.path
    if (-not (Test-Path -LiteralPath $path)) { throw "Falta $($item.path)." }
    if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $item.sha256) { throw "Hash inválido: $($item.path)." }
}

$restoreDatabase = 'misvales_restore_verify_' + [DateTime]::UtcNow.ToString('yyyyMMddHHmmss')
Push-Location $composePath
try {
    & docker compose exec -T postgres sh -ec "createdb --username=`$POSTGRES_USER $restoreDatabase" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'No fue posible crear la base aislada de verificación.' }
    & docker compose cp $databaseFile "postgres:/var/lib/postgresql/data/misvales-restore.dump" | Out-Null
    & docker compose exec -T postgres sh -ec "pg_restore --exit-on-error --no-owner --no-acl --username=`$POSTGRES_USER --dbname=$restoreDatabase /var/lib/postgresql/data/misvales-restore.dump" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'La restauración aislada falló.' }
    $tableCount = & docker compose exec -T postgres sh -ec "psql --tuples-only --no-align --username=`$POSTGRES_USER --dbname=$restoreDatabase --command='select count(1) from pg_tables where schemaname=current_schema()'"
    if ([int]$tableCount -lt 1) { throw 'La restauración no contiene tablas.' }
} finally {
    & docker compose exec -T postgres sh -ec "dropdb --if-exists --username=`$POSTGRES_USER $restoreDatabase; rm -f /var/lib/postgresql/data/misvales-restore.dump" | Out-Null
    Pop-Location
}
Write-Output "RESTORE_VERIFIED:$restoreDatabase"
