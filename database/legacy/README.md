# Retired init scripts

These two files were redundant, earlier drafts of `database/init/00_platform_schema.sql`,
kept alongside it in `database/init/`. Because Docker's `docker-entrypoint-initdb.d`
runs files in filename order, `00_create_databases_and_portal.sql` ran **before**
`00_platform_schema.sql` on every fresh container and issued:

```sql
GRANT ALL PRIVILEGES ON HeraProduction.* TO 'vas_user'@'%';
GRANT ALL PRIVILEGES ON HeraTesting.* TO 'vas_user'@'%';
```

MySQL grants are additive — `00_platform_schema.sql`'s narrower, later `GRANT SELECT,
INSERT, UPDATE, CREATE, ALTER, ...` did **not** revoke this. The net effect on every
fresh install was that the app's own database user held full privileges — including
`DELETE` and `DROP` — on live HeraProduction data, despite the application layer
(`safe_sql_kind()` in `app/lib/bootstrap.php`) presenting itself as blocking those
commands. The DB-level grant never actually matched that promise.

`00_portal_tables.sql` was an even earlier, smaller subset of the same portal schema
(missing `role_permissions`, the newer `portal_projects`/`portal_short_codes` columns,
and `portal_project_channels`).

Moved here 2026-09-12 for reference only; no longer applied on init. See
`database/migrations/2026-09-12_MANUAL_revoke_excess_grants.sql` for the one-time
manual step needed to fix the grant on an **already-provisioned** database (a fresh
`00_platform_schema.sql`-only init never has the problem to begin with).
