#Requires -Version 5.1
<#
.SYNOPSIS
  Import a legacy SQL dump into a staging database, then map data into a clean Laravel schema.

.DESCRIPTION
  Modes:
    Merge  - Fill missing rows only into an existing target DB (safe for production).
    Fresh  - Full import into target DB (preserves legacy IDs). Optionally drops/recreates target and runs migrations.

.PARAMETER LegacySqlFile
  Full path to the legacy .sql dump (schema + data).

.PARAMETER StagingDatabase
  Database that receives the raw legacy dump (default: pocket_legacy_stage).

.PARAMETER TargetDatabase
  Clean Laravel database (default: pocket_v2).

.PARAMETER Mode
  Merge | Fresh

.EXAMPLE
  .\migrate_legacy.ps1 `
    -LegacySqlFile "E:\wingz\projects\pocket\alnahdag_pocket(8).sql" `
    -StagingDatabase pocket_legacy_stage `
    -TargetDatabase pocket_v2 `
    -Mode Merge

.EXAMPLE
  .\migrate_legacy.ps1 `
    -LegacySqlFile "E:\data\legacy.sql" `
    -StagingDatabase pocket_legacy_stage `
    -TargetDatabase pocket_v2_import `
    -Mode Fresh `
    -DropTarget `
    -RunMigrations
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $LegacySqlFile,

    [string] $StagingDatabase = "pocket_legacy_stage",

    [string] $TargetDatabase = "pocket_v2",

    [ValidateSet("Merge", "Fresh")]
    [string] $Mode = "Merge",

    [string] $MySqlHost = "127.0.0.1",
    [int] $MySqlPort = 3306,
    [string] $MySqlUser = "root",
    [string] $MySqlPassword = "",

    [string] $MySqlPath = "",

    [string] $BackendRoot = "",

    [switch] $DropStaging,
    [switch] $SkipStagingImport,

    [switch] $DropTarget,
    [switch] $RunMigrations,
    [switch] $SkipVerify,

    [switch] $WhatIf
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Step([string] $Message) {
    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Resolve-MySqlExe {
    param([string] $ExplicitPath)

    if ($ExplicitPath -and (Test-Path -LiteralPath $ExplicitPath)) {
        return (Resolve-Path -LiteralPath $ExplicitPath).Path
    }

    $candidates = @(
        "C:\xampp\mysql\bin\mysql.exe",
        "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe",
        "C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe"
    )

    foreach ($path in $candidates) {
        if (Test-Path -LiteralPath $path) {
            return $path
        }
    }

    $fromPath = Get-Command mysql -ErrorAction SilentlyContinue
    if ($fromPath) {
        return $fromPath.Source
    }

    throw "mysql.exe not found. Pass -MySqlPath or install XAMPP/Laragon/add mysql to PATH."
}

function Invoke-MySql {
    param(
        [string] $Sql,
        [string] $Database = ""
    )

    $args = @(
        "-h", $MySqlHost,
        "-P", $MySqlPort,
        "-u", $MySqlUser,
        "--default-character-set=utf8mb4"
    )

    if ($MySqlPassword) {
        $args += "-p$MySqlPassword"
    }

    if ($Database) {
        $args += $Database
    }

    if ($WhatIf) {
        Write-Host "[WhatIf] mysql $($args -join ' ') -e `"$($Sql.Substring(0, [Math]::Min(120, $Sql.Length)))...`""
        return
    }

    $prev = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    & $script:MySqlExe @args "-e" $Sql 2>&1 | ForEach-Object {
        if ($_ -is [System.Management.Automation.ErrorRecord]) {
            throw $_.Exception.Message
        }
        if ($_ -match "ERROR") {
            throw $_
        }
        if ($_) { Write-Host $_ }
    }
    if ($LASTEXITCODE -ne 0) {
        throw "mysql exited with code $LASTEXITCODE"
    }
    $ErrorActionPreference = $prev
}

function Invoke-MySqlFile {
    param(
        [string] $FilePath,
        [string] $Database = ""
    )

    if (-not (Test-Path -LiteralPath $FilePath)) {
        throw "SQL file not found: $FilePath"
    }

    $args = @(
        "-h", $MySqlHost,
        "-P", $MySqlPort,
        "-u", $MySqlUser,
        "--default-character-set=utf8mb4"
    )

    if ($MySqlPassword) {
        $args += "-p$MySqlPassword"
    }

    if ($Database) {
        $args += $Database
    }

    if ($WhatIf) {
        Write-Host "[WhatIf] mysql < $FilePath (database=$Database)"
        return
    }

    $cmd = "`"$script:MySqlExe`" $($args -join ' ') < `"$FilePath`""
    cmd /c $cmd
    if ($LASTEXITCODE -ne 0) {
        throw "mysql file import failed (exit $LASTEXITCODE): $FilePath"
    }
}

function Get-SqlWithDatabaseNames {
    param(
        [string] $TemplatePath,
        [string] $StagingDb,
        [string] $TargetDb
    )

    $content = Get-Content -LiteralPath $TemplatePath -Raw -Encoding UTF8
    $replacements = [ordered]@{
        "pocket_legacy_stage_2026_04_02" = $StagingDb
        "pocket_legacy_stage"            = $StagingDb
        "pocket_v2_import"               = $TargetDb
        "pocket_v2"                      = $TargetDb
    }

    foreach ($entry in $replacements.GetEnumerator()) {
        $content = $content.Replace($entry.Key, $entry.Value)
    }

    $temp = [System.IO.Path]::Combine(
        [System.IO.Path]::GetTempPath(),
        "pocket-migrate-$([Guid]::NewGuid().ToString('N')).sql"
    )
    [System.IO.File]::WriteAllText($temp, $content, [System.Text.UTF8Encoding]::new($false))
    return $temp
}

function Invoke-Verify {
    param(
        [string] $StagingDb,
        [string] $TargetDb
    )

    Write-Step "Verification counts"

    $query = @"
SELECT 'staging_users' AS metric, COUNT(*) AS c FROM ``$StagingDb``.users
UNION ALL SELECT 'target_users', COUNT(*) FROM ``$TargetDb``.users
UNION ALL SELECT 'staging_recs', COUNT(*) FROM ``$StagingDb``.recs WHERE complixid IS NOT NULL
UNION ALL SELECT 'target_reservations', COUNT(*) FROM ``$TargetDb``.reservations
UNION ALL SELECT 'orphan_reservations', COUNT(*) FROM ``$TargetDb``.reservations r
  WHERE NOT EXISTS (SELECT 1 FROM ``$TargetDb``.users u WHERE u.id = r.user_id)
     OR NOT EXISTS (SELECT 1 FROM ``$TargetDb``.doctors d WHERE d.id = r.doctor_id)
     OR NOT EXISTS (SELECT 1 FROM ``$TargetDb``.complexes c WHERE c.id = r.complex_id);
"@

    Invoke-MySql -Sql $query
}

# --- defaults for switches ---
if (-not $PSBoundParameters.ContainsKey("DropStaging")) {
    $DropStaging = $true
}

if ($Mode -eq "Fresh") {
    if (-not $PSBoundParameters.ContainsKey("DropTarget")) {
        $DropTarget = $true
    }
    if (-not $PSBoundParameters.ContainsKey("RunMigrations")) {
        $RunMigrations = $true
    }
}

# --- paths ---
$LegacySqlFile = (Resolve-Path -LiteralPath $LegacySqlFile).Path
$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $PSCommandPath }
if (-not $BackendRoot) {
    $BackendRoot = (Resolve-Path (Join-Path $scriptDir "..")).Path
}

$mergeTemplate = Join-Path $scriptDir "merge_fill_missing.sql"
$freshTemplate = Join-Path $scriptDir "import_stage_to_clean.sql"

if ($Mode -eq "Merge" -and -not (Test-Path -LiteralPath $mergeTemplate)) {
    throw "Missing template: $mergeTemplate"
}
if ($Mode -eq "Fresh" -and -not (Test-Path -LiteralPath $freshTemplate)) {
    throw "Missing template: $freshTemplate"
}

$script:MySqlExe = Resolve-MySqlExe -ExplicitPath $MySqlPath
Write-Step "Using mysql: $script:MySqlExe"
Write-Step "Mode=$Mode  Staging=$StagingDatabase  Target=$TargetDatabase"
Write-Step "Legacy dump: $LegacySqlFile"

# --- staging ---
if ($DropStaging) {
    Write-Step "Recreate staging database: $StagingDatabase"
    Invoke-MySql -Sql "DROP DATABASE IF EXISTS ``$StagingDatabase``; CREATE DATABASE ``$StagingDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
}

if (-not $SkipStagingImport) {
    Write-Step "Import legacy dump into staging (this may take several minutes)"
    Invoke-MySqlFile -FilePath $LegacySqlFile -Database $StagingDatabase
}
else {
    Write-Step "Skipping staging import (-SkipStagingImport)"
}

# --- target (fresh only) ---
if ($Mode -eq "Fresh" -and $DropTarget) {
    Write-Step "Recreate target database: $TargetDatabase"
    Invoke-MySql -Sql "DROP DATABASE IF EXISTS ``$TargetDatabase``; CREATE DATABASE ``$TargetDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
}

if ($Mode -eq "Fresh" -and $RunMigrations) {
    Write-Step "Run Laravel migrations on $TargetDatabase"
    if ($WhatIf) {
        Write-Host "[WhatIf] DB_DATABASE=$TargetDatabase php artisan migrate --force"
    }
    else {
        Push-Location $BackendRoot
        try {
            $prevDb = $env:DB_DATABASE
            $env:DB_DATABASE = $TargetDatabase
            & php artisan migrate --force
            if ($LASTEXITCODE -ne 0) {
                throw "php artisan migrate failed (exit $LASTEXITCODE)"
            }
        }
        finally {
            if ($null -ne $prevDb) { $env:DB_DATABASE = $prevDb } else { Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue }
            Pop-Location
        }
    }
}

# --- map staging -> target ---
if ($Mode -eq "Merge") {
    Write-Step "Merge fill-missing into $TargetDatabase"
    $tempSql = Get-SqlWithDatabaseNames -TemplatePath $mergeTemplate -StagingDb $StagingDatabase -TargetDb $TargetDatabase
    try {
        Invoke-MySqlFile -FilePath $tempSql -Database $TargetDatabase
    }
    finally {
        if (Test-Path -LiteralPath $tempSql) { Remove-Item -LiteralPath $tempSql -Force }
    }
}
else {
    Write-Step "Fresh import (preserve legacy IDs) into $TargetDatabase"
    $tempSql = Get-SqlWithDatabaseNames -TemplatePath $freshTemplate -StagingDb $StagingDatabase -TargetDb $TargetDatabase
    try {
        Invoke-MySqlFile -FilePath $tempSql -Database $TargetDatabase
    }
    finally {
        if (Test-Path -LiteralPath $tempSql) { Remove-Item -LiteralPath $tempSql -Force }
    }
}

if (-not $SkipVerify) {
    Invoke-Verify -StagingDb $StagingDatabase -TargetDb $TargetDatabase
}

Write-Host ""
Write-Host "Done. Mode=$Mode  Staging=$StagingDatabase  Target=$TargetDatabase" -ForegroundColor Green
