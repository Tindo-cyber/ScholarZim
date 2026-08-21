# Render + Aiven redeploy checklist (manual steps — no API key in this repo).
#
# Run: .\scripts\render-redeploy-notes.ps1

Write-Host @"

=== ScholarZim Render + Aiven redeploy ===

1. Confirm latest main is pushed:
   git log -1 --oneline origin/main

2. Env vars on Render Web Service:
   SPRING_PROFILES_ACTIVE=prod
   SCHOLARZIM_DB_URL=jdbc:mysql://HOST:PORT/DB?sslMode=REQUIRED&allowPublicKeyRetrieval=true
   SCHOLARZIM_DB_USER / SCHOLARZIM_DB_PASSWORD (from Aiven)
   SCHOLARZIM_APP_BASE_URL=https://YOUR-SERVICE.onrender.com
   SCHOLARZIM_SESSION_COOKIE_SECURE=true

3. Render dashboard -> Web Service -> Manual Deploy -> Deploy latest commit

4. Watch Logs until: Started ScholarzimApplication
   (FlywayConfig runs repair() then migrate() on startup)

5. Verify health:
   .\scripts\render-health-check.ps1 -BaseUrl "https://YOUR-SERVICE.onrender.com"

If Logs show Flyway failed migration / schema validation:
   - Run: .\scripts\render-mysql-repair.ps1  (with Aiven SCHOLARZIM_DB_* env vars)
   - Or open scripts/render-flyway-repair.sql in DBeaver with SSL
   - Then Manual Deploy again

If login shows 'Something went wrong':
   - Confirm SSL JDBC URL above
   - Confirm rebuild includes login audit hardening
   - Paste the stack trace from Render Logs around login time

"@ -ForegroundColor Cyan

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
$gitLog = git -C $repoRoot log -1 --oneline 2>$null
if ($gitLog) {
    Write-Host "Current HEAD: $gitLog" -ForegroundColor Green
}
