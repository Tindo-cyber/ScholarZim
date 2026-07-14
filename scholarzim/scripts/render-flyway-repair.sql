-- Aiven / Render MySQL: repair failed Flyway migrations
-- Run via render-mysql-repair.ps1 or any MySQL client with SSL enabled.

-- 1) Inspect migration history
SELECT version, description, success, installed_on
FROM flyway_schema_history
ORDER BY installed_rank;

-- 2) Remove ALL failed migration rows (keeps successful history)
DELETE FROM flyway_schema_history
WHERE success = 0;

-- 3) Verify, then Manual Deploy the Render web service
--    App startup runs flyway.repair() then migrate()
SELECT version, success, installed_on
FROM flyway_schema_history
ORDER BY installed_rank;
