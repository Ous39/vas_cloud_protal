-- MANUAL STEP ONLY — do not run automatically. Touches real production DB privileges.
-- Run this yourself, through your normal DB change-management process.
--
-- The now-retired database/legacy/00_create_databases_and_portal.sql issued
-- `GRANT ALL PRIVILEGES ON HeraProduction.*` (and HeraTesting) to 'vas_user'@'%'
-- on every fresh container init. MySQL grants are additive, so the later, narrower
-- grant in database/init/00_platform_schema.sql never actually took this away —
-- meaning the app's own DB user has held DELETE/DROP/GRANT-adjacent privileges on
-- live HeraProduction data this whole time, despite the app pretending to block
-- DELETE/DROP/TRUNCATE at the SQL-console layer. See database/legacy/README.md.
--
-- This narrows the already-provisioned grant down to what the app actually uses
-- (matching database/init/00_platform_schema.sql, minus CREATE/ALTER — the app no
-- longer issues DDL against HeraProduction at runtime either; see
-- app/lib/bootstrap.php's run_sql()).

REVOKE ALL PRIVILEGES ON HeraProduction.* FROM 'vas_user'@'%';
GRANT SELECT, INSERT, UPDATE ON HeraProduction.* TO 'vas_user'@'%';

REVOKE ALL PRIVILEGES ON HeraTesting.* FROM 'vas_user'@'%';
GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, REFERENCES, LOCK TABLES, EXECUTE, SHOW VIEW ON HeraTesting.* TO 'vas_user'@'%';

FLUSH PRIVILEGES;

-- Verify afterwards:
-- SHOW GRANTS FOR 'vas_user'@'%';
