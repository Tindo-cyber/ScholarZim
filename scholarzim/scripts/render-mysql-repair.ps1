# Connect to Render MySQL and repair failed Flyway V10 migration.
#
# Set env vars, then run from scholarzim/:
#   .\scripts\render-mysql-repair.ps1
#
# Or use render-flyway-repair.sql in DBeaver / MySQL Workbench.

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$SqlFile = Join-Path $ScriptDir "render-flyway-repair.sql"

function Parse-JdbcUrl {
    param([string]$Url)
    if ($Url -match "jdbc:mysql://([^:/]+):(\d+)/([^?]+)") {
        return @{
            Host = $Matches[1]
            Port = $Matches[2]
            Database = $Matches[3]
        }
    }
    throw "Could not parse SCHOLARZIM_DB_URL: $Url"
}

$host_ = $env:SCHOLARZIM_DB_HOST
$port = if ($env:SCHOLARZIM_DB_PORT) { $env:SCHOLARZIM_DB_PORT } else { "3306" }
$db = $env:SCHOLARZIM_DB_NAME
$user = $env:SCHOLARZIM_DB_USER
$pass = $env:SCHOLARZIM_DB_PASSWORD

if ($env:SCHOLARZIM_DB_URL -and (-not $host_)) {
    $parsed = Parse-JdbcUrl $env:SCHOLARZIM_DB_URL
    $host_ = $parsed.Host
    $port = $parsed.Port
    $db = $parsed.Database
}

if (-not $host_ -or -not $db -or -not $user -or -not $pass) {
    Write-Host ""
    Write-Host "Missing database credentials." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Option A - set env vars:" -ForegroundColor Cyan
    Write-Host '  $env:SCHOLARZIM_DB_HOST = "your-host.render.com"'
    Write-Host '  $env:SCHOLARZIM_DB_PORT = "3306"'
    Write-Host '  $env:SCHOLARZIM_DB_NAME = "scholarzim"'
    Write-Host '  $env:SCHOLARZIM_DB_USER = "your_user"'
    Write-Host '  $env:SCHOLARZIM_DB_PASSWORD = "your_password"'
    Write-Host ""
    Write-Host "Option B - JDBC URL plus user/password:" -ForegroundColor Cyan
    Write-Host '  $env:SCHOLARZIM_DB_URL = "jdbc:mysql://host:3306/db?useSSL=true"'
    Write-Host '  $env:SCHOLARZIM_DB_USER = "..."'
    Write-Host '  $env:SCHOLARZIM_DB_PASSWORD = "..."'
    Write-Host ""
    Write-Host "Option C - GUI: open render-flyway-repair.sql in DBeaver" -ForegroundColor Cyan
    Write-Host "  File: $SqlFile"
    Write-Host ""
    exit 1
}

$mysql = Get-Command mysql -ErrorAction SilentlyContinue
if (-not $mysql) {
    Write-Host "mysql.exe not found on PATH." -ForegroundColor Yellow
    Write-Host "Install MySQL Shell or use DBeaver with: $SqlFile"
    exit 1
}

Write-Host "Connecting to ${host_}:${port} / $db as $user ..." -ForegroundColor Cyan

$repairSql = @(
    "SELECT version, description, success, installed_on FROM flyway_schema_history ORDER BY installed_rank;"
    "DELETE FROM flyway_schema_history WHERE version = '10' AND success = 0;"
    "SELECT version, success, installed_on FROM flyway_schema_history ORDER BY installed_rank;"
) -join "`n"

$repairSql | & mysql -h $host_ -P $port -u $user -p$pass $db --ssl-mode=REQUIRED

if ($LASTEXITCODE -ne 0) {
    Write-Host ("mysql failed (exit " + $LASTEXITCODE + "). Try DBeaver with " + $SqlFile) -ForegroundColor Red
    exit $LASTEXITCODE
}

Write-Host ""
Write-Host "Repair SQL completed. Redeploy the Render web service." -ForegroundColor Green
