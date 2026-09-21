# Architecture

## Components

- **app**: PHP 8.2 Apache web application.
- **db**: MySQL 8 database server.
- **phpmyadmin**: browser database administration.

## Branding / UI

The visual design (red `#ff4d4d` Comium theme, top navbar with logo, card/table styling, footer)
follows the reference at `Ous39/VASCloud` (branch `VAS_Cloud`) — a separate Spring Boot SMS
campaign tool built for the same brand. Only the look and feel was adopted, not the tech stack or
feature set: this app keeps its full page set (19 pages vs. their 6), organized into dropdown nav
groups (Operations, Infrastructure, Reports, Admin) since a flat navbar doesn't scale to that many
pages. `Dashboard` and `Monitoring` stay as standalone top-level links by request. The nav
structure and per-item permission gating live in `layout_start()` / `nav_can_see()` in
`app/public/index.php`; the color tokens and component styles are in `app/public/style.css`.
The Comium logo (`app/public/comium_logo.png`) was copied from that reference repo.
The stylesheet link carries a `?v=<filemtime>` cache-buster (`asset_version()`) so a CSS change
takes effect on the next request instead of being served stale from a browser's HTTP cache.

## Schemas

- `HeraProduction`: live/production VAS operational database.
- `HeraTesting`: safe testing/staging copy of production data structure.
- `vas_portal`: portal users, permissions, audit trail, saved queries, projects, short codes and confirmations.

## Infrastructure services (USSD, IVR, SMSC)

- **USSD & IVR** (`?page=ussd_ivr`): the operational routing table lives in `channel_service_code`
  (HeraProduction/HeraTesting — short code + service code → offer code, per USSD/IVR), plus a live
  view of `agent_queue` (current sessions). Governance-level channel metadata (short code ownership,
  provider, status) stays on the existing Short Codes page (`portal_short_codes`, `vas_portal`
  schema) — this page is the operational counterpart to that registry, not a replacement for it.
  Today's transaction counts are read from `audit_log` filtered to `channel IN ('USSD')` /
  `('IVR')`, which is real data already flowing through the system (USSD already appears as an
  audit_log channel value in this environment).
- **Integrations** (`?page=integrations`, `vas_portal.integrations`): a single registry for every
  external system this platform connects to — SMSC, USSD gateway, IVR platform, monitoring
  endpoints, or anything added later (`service_type` is an enum you extend, not a fixed list).
  "Test" runs a **real live check**, not a simulated one: an HTTP request (with the configured
  auth header) to `base_url` + `health_check_path` for `protocol=http`, or a raw `fsockopen()`
  TCP connect for `protocol=tcp` (e.g. an SMPP bind port) — latency, HTTP/error status, and a
  timestamp are recorded on every check (`test_integration()` in `bootstrap.php`). Credentials
  (bearer token / API key / basic auth) are stored AES-256-CBC encrypted
  (`encrypt_secret()`/`decrypt_secret()`, key in `APP_ENCRYPTION_KEY` or a random one generated
  into `vas_portal.app_secrets` on first use) because, unlike a login password, a health check
  has to actually send this value on the wire — it can't be one-way hashed like `portal_users`.
  There is still no full SMPP client (bind/submit_sm/deliver_sm PDU handling) — the TCP check only
  proves the port is reachable, which is exactly what it's for.
- **Monitoring** (`?page=monitoring`): a single cross-service snapshot — DB table count, USSD/IVR/SMS
  activity today, live agent queue by status, every registered integration with its live status
  (UP/DOWN, latency, when last checked), and the same alerts shown on the dashboard.

### Adding another service module

The pattern used for every module above (Subscriptions, Offers, eSIM, Sales, Friends & Family,
Voting, USSD/IVR routing, Integrations) is the same each time — follow it for anything new. For a
new *external system* specifically, register it in Integrations rather than building a bespoke
connection-config page — that's exactly what Integrations is for.

1. **Find or create the data.** Check if HeraProduction/HeraTesting already has a table for it
   (`table_names()` / `columns()` in `app/lib/bootstrap.php` will tell you). If genuinely nothing
   exists and the service is portal-governance-level config (not live operational data), add a
   table via `ensure_portal_runtime_schema()` in `bootstrap.php` (self-healing — runs on every
   request, `CREATE TABLE IF NOT EXISTS`, safe to add to without a manual migration step).
2. **Check the table's size and indexes before building a search UI.** A small table (hundreds/
   thousands of rows, like `vas_offers` or `unique_number_subscription`) can be searched freely. A
   huge one with no supporting index (like `subscription` at ~89M rows, or `audit_log` at ~227M)
   needs a required exact-match filter or a bounded date range — see `subscription_filters_from_request()`
   and `audit_log_filters_from_request()` for the pattern, and don't skip this check.
3. **Write the query functions in `bootstrap.php`**, not inline in `index.php` — a search/list
   function, and if it's operational (HeraProduction) data being written, route saves through
   `make_confirmation()` + the `?page=confirm` flow (see the `offers`/`esim`/`ussd_ivr` pages). If
   it's portal-governance config (like `smsc_connections`, `portal_projects`), a direct save with a
   `data-confirm` JS prompt is the established alternative (see the `smsc`/`shortcodes` pages) —
   use judgment on which class the new data belongs to.
4. **Add the page** in `app/public/index.php` (`if ($page==='your_page') { require_perm(...); ... }`),
   reusing `layout_start()`/`layout_end()`, `redact_row()` on anything rendered or exported, and the
   existing pagination/filter helpers (`parse_table_filters()`, `list_records()`) where the module
   is just filtered search over one table.
5. **Add the nav entry** in the `$items` array near the top of `layout_start()`, with a permission
   gate matching the rest (reuse `view_tables`/`edit_records`/`create_records`/`view_reports`, or add
   a new permission key the same way `view_reports` and `manage_api_keys` were added — seed it in
   `database/init/00_platform_schema.sql` for fresh installs AND in `ensure_portal_runtime_schema()`
   for already-provisioned databases).
6. **Rebuild, redeploy just the app container** (`docker compose build app && docker compose up -d
   app` — leaves the database untouched), **and verify against the running container** before
   calling it done.

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
