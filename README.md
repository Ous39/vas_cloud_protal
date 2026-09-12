# VAS Cloud Enterprise Control Center

A production-style VAS operations portal for managing `HeraTesting`, `HeraProduction`, and portal governance data.

## What this version provides

- Dockerized PHP 8.2 + Apache + MySQL 8 + phpMyAdmin
- Separate schemas: `HeraTesting`, `HeraProduction`, `vas_portal`
- Modern responsive UI/UX
- Environment switcher for Testing and Production
- Dynamic table browser for all VAS database tables
- Multi-field search: select column, condition, and value
- Add, edit, duplicate, copy and sync records
- Duplicate opens a full form first so you can adjust values before saving
- Two-way copy: Testing → Production and Production → Testing
- Full-table sync in both directions with confirmation
- Confirmation workflow before insert, update, duplicate, copy, sync and write-SQL
- Delete disabled in the portal and destructive SQL blocked
- SQL Console for SELECT/SHOW/DESCRIBE/EXPLAIN and controlled write SQL
- Projects register
- Short Codes register
- User roles and permissions
- Audit trail for operational traceability
- CSV export

## Start the project

```powershell
docker compose down -v --remove-orphans
docker compose up -d --build
```

Open the portal:

```text
http://localhost:8090
```

Login:

```text
Username: admin
Password: admin123
```

phpMyAdmin:

```text
http://localhost:8082
```

MySQL Workbench:

```text
Host: 127.0.0.1
Port: 3307
Username: vas_user
Password: vas_password
Schemas: HeraProduction, HeraTesting, vas_portal
```

## Important production notes

Before real production use:

1. Change all default passwords.
2. Use HTTPS behind a reverse proxy such as Nginx or Traefik.
3. Restrict MySQL port exposure; do not expose `3307` publicly.
4. Replace local Docker MySQL with a managed/high-availability database if needed.
5. Enable daily backups and test restore procedures.
6. Create individual users instead of sharing the admin account.
7. Keep destructive actions disabled unless a formal approval workflow is added.

## Workflow recommendation

1. Use `HeraTesting` for all new records and changes.
2. Duplicate and edit records there first.
3. Confirm and save.
4. Validate using search, SQL console, reports and phpMyAdmin.
5. Copy approved record or table to `HeraProduction`.
6. Review audit trail.

## Troubleshooting

If Docker cannot start:

```powershell
docker version
```

If port 3307 is busy:

```powershell
netstat -ano | findstr :3307
```

If port 8090 is busy, change this in `docker-compose.yml`:

```yaml
ports:
  - "8091:80"
```

If database import does not refresh, remove the volume:

```powershell
docker compose down -v --remove-orphans
docker compose up -d --build
```

## Fix for: `dependency failed to start: container vas-enterprise-db is unhealthy`

This version uses `service_started` plus the PHP app's own database wait script instead of blocking the whole Compose startup on MySQL health. The first database import can take several minutes, especially when the HeraProduction dump is large. During that time, the DB container may not answer health checks immediately even though it is still importing correctly.

Recommended startup:

```powershell
docker compose down -v --remove-orphans
docker compose up -d --build
```

Then watch the database import:

```powershell
docker logs -f vas-enterprise-db
```

When the import finishes, test:

```powershell
docker exec vas-enterprise-db mysql -uroot -proot_password -e "SHOW DATABASES;"
docker exec vas-enterprise-db mysql -uvas_user -pvas_password -e "SHOW TABLES FROM HeraProduction;"
```

## Channel / Short Code module

The Channel module is now structured around the exact operational fields:

- Channel Type: `USSD` or `IVR`
- Short Code
- Service Name
- Provider
- Status: `Active`, `Inactive`, `Pending`, `Suspended`

## Project Management module

The Project module now uses:

- Project Name
- Primary Short Code
- Status: `Planning`, `Development`, `Testing`, `Launched`, `Completed`, `Suspended`
- Start Date
- Launch Date
- Description

## Project-to-Channel relationship

Projects can be linked to one or more Channels through `portal_project_channels`. This lets one project track multiple USSD/IVR channels sharing or supporting the same service short code.
