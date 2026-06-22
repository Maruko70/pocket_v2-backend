#Requires -Version 5.1
<#
.SYNOPSIS
  Run migrate_legacy.ps1 using a JSON config file.

.EXAMPLE
  .\migrate_legacy_from_config.ps1 -ConfigFile .\migrate_legacy.config.json
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $ConfigFile,

    [switch] $WhatIf
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if (-not (Test-Path -LiteralPath $ConfigFile)) {
    throw "Config not found: $ConfigFile"
}

$config = Get-Content -LiteralPath $ConfigFile -Raw -Encoding UTF8 | ConvertFrom-Json
$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $PSCommandPath }
$migrateScript = Join-Path $scriptDir "migrate_legacy.ps1"

$params = @{
    LegacySqlFile     = $config.legacySqlFile
    StagingDatabase   = $config.stagingDatabase
    TargetDatabase    = $config.targetDatabase
    Mode              = $config.mode
    MySqlHost         = $config.mySqlHost
    MySqlPort         = [int]$config.mySqlPort
    MySqlUser         = $config.mySqlUser
    MySqlPassword     = $config.mySqlPassword
}

if ($config.mySqlPath) { $params.MySqlPath = $config.mySqlPath }
if ($config.dropStaging) { $params.DropStaging = $true }
if ($config.skipStagingImport) { $params.SkipStagingImport = $true }
if ($config.dropTarget) { $params.DropTarget = $true }
if ($config.runMigrations) { $params.RunMigrations = $true }
if ($config.skipVerify) { $params.SkipVerify = $true }
if ($WhatIf) { $params.WhatIf = $true }

& $migrateScript @params
