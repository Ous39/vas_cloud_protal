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
- **Partner API** (`app/public/api.php`) is read-only and authenticated separately from the portal session, via a `X-Api-Key` header checked against `vas_portal.api_keys` (key is stored as a SHA-256 hash, shown once at creation). Rate-limited to 60 requests/minute/key against `vas_portal.api_request_log`, the same pattern used for portal login rate-limiting. Manage keys at `?page=api_keys` (admin only, `manage_api_keys` permission).

## VAS Service Modules

Purpose-built screens (not just generic table CRUD) for the operational VAS domains:

- **Subscriptions** (`?page=subscriptions`): requires an exact MSISDN or Transaction ID — `subscription` has ~89M rows with no supporting index (see `database/migrations/2026-09-17_MANUAL_add_subscription_index.sql` for the real fix), so open-ended or date-only search is deliberately not offered. Computed active/expired status per data/SMS/minutes bucket.
- **Offer Management** (`?page=offers`): catalog search/add/edit, status toggle routed through the same confirm-before-write flow as everything else.
- **eSIM Profiles** (`?page=esim`): lookup by MSISDN/ICCID/IMSI, provisioning/installation status, add/edit.
- **Sales & Invoices** (`?page=sales`): relational rollup — look up by Order No. or ICCID and see the order, its line items, and its linked invoice(s) together, which raw table browsing across three separate tables can't give you.
- **Friends & Family** (`?page=friends_family`): search unique_number_subscription by MSISDN/friend number/transaction, toggle active status.
- **Voting Service** (`?page=voting`): contestant register plus a live vote tally (`voting_service.content` joined against `voting_contestant.number`).
- **Executive Dashboard**: today's transaction/success/failure counts and top vendors by volume, all bounded to `CURDATE()` so they only touch today's `audit_log` partition. Table row counts everywhere else use `information_schema.TABLES.TABLE_ROWS` (an estimate) instead of exact `COUNT(*)`, since the previous per-table exact-count loop meant a full scan of the ~227M-row `audit_log` and ~89M-row `subscription` tables on every single dashboard load.
- **Alerts** (`?page=alerts`, also a banner on the dashboard): high failure rate in the last hour, and vendors active this time yesterday but silent now — computed when the page loads, not pushed. A real alerting pipeline (email/Slack) needs a scheduler, which doesn't exist in this stack yet.

## Role model

- `admin`: full portal access, including Partner API key management.
- `manager`: operational management and SQL access.
- `operator`: data operations without SQL console or user management.
- `viewer`: read-only access.

## Future expansion

Recommended future modules:

- Transaction reconciliation engine
- Push-based monitoring/alerting (needs a scheduler — the current Alerts page is computed-on-load only)
- Background job workers
- Approval workflow for production promotion
- SSO/OAuth integration
- Write endpoints on the Partner API (currently read-only by design)
- Backup and restore UI

## Project and Channel Data Model

The portal governance schema `vas_portal` contains three first-class tables for channel/project operations:

- `portal_short_codes`: channel register for USSD and IVR services.
- `portal_projects`: project register with lifecycle dates and operational status.
- `portal_project_channels`: many-to-many relationship allowing one project to link to multiple channels via the associated short code.

This keeps production/testing business data separate from portal governance data while still allowing VAS operations teams to track service ownership, channel status, and launch readiness.
