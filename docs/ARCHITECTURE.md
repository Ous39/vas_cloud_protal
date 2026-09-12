# Architecture

## Components

- **app**: PHP 8.2 Apache web application.
- **db**: MySQL 8 database server.
- **phpmyadmin**: browser database administration.

## Schemas

- `HeraProduction`: live/production VAS operational database.
- `HeraTesting`: safe testing/staging copy of production data structure.
- `vas_portal`: portal users, permissions, audit trail, saved queries, projects, short codes and confirmations.

## Safety model

- Delete operations are disabled everywhere. Write operations use confirmation tokens stored in `vas_portal.operation_confirmations`. All successful changes are logged in `vas_portal.portal_audit_trail`.
- **HeraProduction is read-only in the SQL Console** (SELECT/SHOW/DESCRIBE/EXPLAIN only). Production writes only happen through the audited, primary-key-scoped record forms (Add/Edit/Duplicate/Copy), never via free-form SQL. The app's own DB grant on HeraProduction is `SELECT, INSERT, UPDATE` only (see `database/init/00_platform_schema.sql` and, for an already-provisioned database, `database/migrations/2026-09-12_MANUAL_revoke_excess_grants.sql`).
- **Full-table sync (truncate + reload) can never target HeraProduction.** Use "Merge into Production" instead, which upserts by primary key and never deletes existing rows.
- Columns matching a sensitive-name pattern (password, secret, token, hash, otp, pin, cvv, card number, auth data, credential, api key) are redacted (`••••••••`) wherever data is rendered or exported, regardless of role.
- Login is rate-limited: 5 failed attempts per username, or 20 failed attempts per IP, within a 15-minute window blocks further attempts.
- CSRF tokens are required on every state-changing request; session cookies are `HttpOnly`, `SameSite=Strict`, and `Secure` when served over HTTPS; responses carry a CSP, HSTS (when HTTPS), and standard anti-clickjacking/MIME-sniffing headers.
- **Complaint / Transaction Investigation report** (`?page=investigate`): a bounded, partition-aware search over `audit_log` (max 31-day range) with MSISDN/transaction/vendor/status/channel filters, readable request/response payloads (`CONVERT(... USING utf8mb4)` on the `input`/`output` blob columns), and filtered CSV export — see `search_audit_log()` / `export_audit_log()` in `app/lib/bootstrap.php`.

## Role model

- `admin`: full portal access.
- `manager`: operational management and SQL access.
- `operator`: data operations without SQL console or user management.
- `viewer`: read-only access.

## Future expansion

Recommended future modules:

- Partner API gateway
- Transaction reconciliation engine
- Monitoring and alerting integration
- Background job workers
- Approval workflow for production promotion
- SSO/OAuth integration
- REST API layer
- Backup and restore UI

## Project and Channel Data Model

The portal governance schema `vas_portal` contains three first-class tables for channel/project operations:

- `portal_short_codes`: channel register for USSD and IVR services.
- `portal_projects`: project register with lifecycle dates and operational status.
- `portal_project_channels`: many-to-many relationship allowing one project to link to multiple channels via the associated short code.

This keeps production/testing business data separate from portal governance data while still allowing VAS operations teams to track service ownership, channel status, and launch readiness.
