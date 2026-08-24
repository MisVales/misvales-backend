param(
    [Parameter(Mandatory = $true)][string]$ComposeDirectory,
    [Parameter(Mandatory = $true)][string]$BackupDirectory
)

$ErrorActionPreference = 'Stop'
$composePath = (Resolve-Path -LiteralPath $ComposeDirectory).Path
$backupPath = (Resolve-Path -LiteralPath $BackupDirectory).Path
$manifestPath = Join-Path $backupPath 'manifest.json'
$databaseFile = Join-Path $backupPath 'mariadb.sql'
if (-not (Test-Path -LiteralPath $manifestPath) -or -not (Test-Path -LiteralPath $databaseFile)) { throw 'Respaldo incompleto.' }

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
foreach ($item in $manifest) {
    $path = Join-Path $backupPath $item.path
    if (-not (Test-Path -LiteralPath $path)) { throw "Falta $($item.path)." }
    if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $item.sha256) { throw "Hash inválido: $($item.path)." }
}

$restoreDatabase = 'misvales_restore_verify'
$containerName = 'misvales-mariadb-restore-' + [Guid]::NewGuid().ToString('N')
$verificationPassword = [Guid]::NewGuid().ToString('N')
try {
    & docker run --detach --name $containerName --env "MARIADB_ROOT_PASSWORD=$verificationPassword" --env "MARIADB_DATABASE=$restoreDatabase" mariadb:12.3.2 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'No fue posible iniciar MariaDB para la verificación aislada.' }

    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        & docker exec $containerName healthcheck.sh --connect --innodb_initialized 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            break
        }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw 'MariaDB no quedó lista para verificar el respaldo.' }

    & docker cp $databaseFile "${containerName}:/tmp/misvales-restore.sql" | Out-Null
    & docker exec --env "MYSQL_PWD=$verificationPassword" --env "RESTORE_DATABASE=$restoreDatabase" $containerName sh -ec 'mariadb --user=root "$RESTORE_DATABASE" < /tmp/misvales-restore.sql' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'La restauración aislada falló.' }
    $tableCount = & docker exec --env "MYSQL_PWD=$verificationPassword" $containerName mariadb --batch --skip-column-names --user=root --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$restoreDatabase'"
    if ([int]$tableCount -lt 1) { throw 'La restauración no contiene tablas.' }
} finally {
    if ($containerName -like 'misvales-mariadb-restore-*') {
        & docker rm --force $containerName 2>$null | Out-Null
    }
}
Write-Output "RESTORE_VERIFIED:$restoreDatabase"
