-- Render MySQL: repair failed Flyway V10 migration
-- Run after connecting with render-mysql-repair.ps1 or any MySQL client.

-- 1) Inspect migration history
SELECT version, description, success, installed_on
FROM flyway_schema_history
ORDER BY installed_rank;

-- 2) Remove failed V10 only (safe if V10 failed on bad column name)
DELETE FROM flyway_schema_history
WHERE version = '10' AND success = 0;

-- 3) Verify (after redeploy, version 10 should show success = 1)
SELECT version, success, installed_on
FROM flyway_schema_history
ORDER BY installed_rank;
