# Render redeploy checklist (manual steps — no API key configured in this repo).
#
# Run: .\scripts\render-redeploy-notes.ps1

Write-Host @"

=== ScholarZim Render redeploy ===

1. Confirm latest main is pushed (includes Flyway V10 fix + prod repair):
   git log -1 --oneline origin/main

2. Render dashboard -> Web Service (scholarzim) -> Manual Deploy -> Deploy latest commit

3. Watch Logs until you see:
   Started ScholarzimApplication

4. Verify health:
   .\scripts\render-health-check.ps1 -BaseUrl "https://YOUR-SERVICE.onrender.com"

If logs show 'Detected failed migration to version 10':
   - Latest deploy should auto-repair via FlywayConfig (prod profile)
   - If still failing, run: .\scripts\render-mysql-repair.ps1
   - Then Manual Deploy again

"@ -ForegroundColor Cyan

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
$gitLog = git -C $repoRoot log -1 --oneline 2>$null
if ($gitLog) {
    Write-Host "Current HEAD: $gitLog" -ForegroundColor Green
}
