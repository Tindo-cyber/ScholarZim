# Check ScholarZim health on Render (or any deployed URL).
#
# Usage:
#   .\scripts\render-health-check.ps1
#   .\scripts\render-health-check.ps1 -BaseUrl "https://your-app.onrender.com"

param(
    [string]$BaseUrl = $env:SCHOLARZIM_APP_BASE_URL
)

if (-not $BaseUrl) {
    $BaseUrl = "https://www.scholarzim.com"
}

$BaseUrl = $BaseUrl.TrimEnd("/")
$HealthUrl = "$BaseUrl/actuator/health"

Write-Host "Checking $HealthUrl ..." -ForegroundColor Cyan

try {
    $response = Invoke-WebRequest -Uri $HealthUrl -UseBasicParsing -TimeoutSec 120
    Write-Host "HTTP $($response.StatusCode)" -ForegroundColor Green
    Write-Host $response.Content
    exit 0
}
catch {
    $status = $_.Exception.Response.StatusCode.value__
    Write-Host "Health check failed (HTTP $status)." -ForegroundColor Red
    Write-Host $_.Exception.Message
    Write-Host ""
    Write-Host "If deploy is still starting, wait 1-2 minutes (free tier cold start) and retry." -ForegroundColor Yellow
    Write-Host "If Flyway failed, run: .\scripts\render-mysql-repair.ps1" -ForegroundColor Yellow
    exit 1
}
