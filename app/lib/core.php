<?php
declare(strict_types=1);
// Setup: config, database-backed sessions, security headers, helpers, schema self-healing, login and permissions, generic record tools.

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['timezone']);

$__isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('VAS_CLOUD_SESSION');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $__isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
// Sessions live in vas_portal (portal_sessions), not per-pod disk — so any of the app's replicas
// can serve any request for a logged-in user without needing sticky routing at the ingress. Replaces
// relying on nginx cookie-affinity, which didn't hold up reliably once combined with the /portal
// regex path rewrite this deployment needs (users were getting "Security token expired" once the
// deployment ran more than one replica).
//
// This class must be declared here, above its use below — unlike a plain class, one that
// `implements` an interface isn't compile-time hoisted in PHP, so it has to already be defined by
// the time session_set_save_handler() runs, not just somewhere later in the file.
class DbSessionHandler implements SessionHandlerInterface {
    private static bool $tableReady = false;
    private function ensureTable(): void {
        if (self::$tableReady) return;
        try {
            portal_pdo()->exec("CREATE TABLE IF NOT EXISTS portal_sessions (
                id VARCHAR(128) NOT NULL PRIMARY KEY,
                data MEDIUMTEXT NOT NULL,
                last_activity INT NOT NULL,
                INDEX idx_last_activity (last_activity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            self::$tableReady = true;
        } catch (Throwable $e) { /* DB not reachable yet — session falls back to failing open below */ }
    }
    public function open(string $path, string $name): bool { $this->ensureTable(); return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false {
        try {
            $st = portal_pdo()->prepare('SELECT data FROM portal_sessions WHERE id=?');
            $st->execute([$id]);
            $row = $st->fetch();
            return $row ? $row['data'] : '';
        } catch (Throwable $e) { return ''; }
    }
    public function write(string $id, string $data): bool {
        try {
            portal_pdo()->prepare('INSERT INTO portal_sessions(id,data,last_activity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data), last_activity=VALUES(last_activity)')
                ->execute([$id, $data, time()]);
        } catch (Throwable $e) {}
        return true;
    }
    public function destroy(string $id): bool {
        try { portal_pdo()->prepare('DELETE FROM portal_sessions WHERE id=?')->execute([$id]); } catch (Throwable $e) {}
        return true;
    }
    public function gc(int $max_lifetime): int|false {
        try {
            $st = portal_pdo()->prepare('DELETE FROM portal_sessions WHERE last_activity < ?');
            $st->execute([time() - $max_lifetime]);
            return $st->rowCount();
        } catch (Throwable $e) { return false; }
    }
}
if (!defined('LEAN_BOOT')) { // ussd.php sets LEAN_BOOT: no session, no admin headers, no schema self-heal
    ini_set('session.gc_maxlifetime', '3600'); // keep in step with SESSION_IDLE_SECONDS
    session_set_save_handler(new DbSessionHandler(), true);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}

function csp_nonce(): string { if (empty($_SESSION['csp_nonce'])) $_SESSION['csp_nonce']=bin2hex(random_bytes(16)); return $_SESSION['csp_nonce']; }
function send_security_headers(bool $https): void {
    $nonce = csp_nonce();
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    if ($https) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
if (!defined('LEAN_BOOT')) send_security_headers($__isHttps);

function app_config(?string $key=null) { global $config; return $key ? ($config[$key] ?? null) : $config; }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void { if ($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) throw new RuntimeException('Security token expired. Please refresh and try again.'); }
function flash(string $type, string $msg): void { $_SESSION['flash'][]=['type'=>$type,'msg'=>$msg]; }
function flashes(): array { $f=$_SESSION['flash']??[]; unset($_SESSION['flash']); return $f; }
function redirect(string $url): never { header('Location: '.$url); exit; }
function request_id(): string { if (empty($_SESSION['rid'])) $_SESSION['rid']=bin2hex(random_bytes(8)); return $_SESSION['rid']; }

function pdo(?string $schema=null): PDO {
    static $pool=[]; $cfg=app_config(); $db=$schema ?: $cfg['portal_db'];
    if (!preg_match('/^[A-Za-z0-9_]+$/',$db)) throw new InvalidArgumentException('Invalid schema');
    if (!isset($pool[$db])) {
        // HeraProduction/HeraTesting connect via hera_db_* (the real infrastructure once configured);
        // everything else (vas_portal, this app's own metadata) always uses db_* (local Docker MySQL)
        // regardless of what hera_db_* points at. See config.php / .env.example.
        $isHera = in_array($db, $cfg['allowed_schemas'], true);
        $host = $isHera ? $cfg['hera_db_host'] : $cfg['db_host'];
        $port = $isHera ? $cfg['hera_db_port'] : $cfg['db_port'];
        $user = $isHera ? $cfg['hera_db_user'] : $cfg['db_user'];
        $pass = $isHera ? $cfg['hera_db_pass'] : $cfg['db_pass'];
        $dsn='mysql:host='.$host.';port='.$port.';dbname='.$db.';charset=utf8mb4';
        $pool[$db]=new PDO($dsn,$user,$pass,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }
    return $pool[$db];
}
function portal_pdo(): PDO { return pdo(app_config('portal_db')); }

function ensure_portal_runtime_schema(): void {
    try {
        $db = portal_pdo();
        $schema = app_config('portal_db');

        $columnExists = function(string $table, string $column) use ($db, $schema): bool {
            $st = $db->prepare('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
            $st->execute([$schema, $table, $column]);
            return (int)$st->fetch()['c'] > 0;
        };

        // audit() has always inserted a request_id column that 00_platform_schema.sql never actually
        // created — every audit() call has been silently failing (caught by its own try/catch) since
        // this table's introduction, on every environment including production. Self-heals here
        // rather than requiring a manual ALTER on every already-deployed database.
        if ($columnExists('portal_audit_trail', 'id') && !$columnExists('portal_audit_trail', 'request_id')) {
            $db->exec("ALTER TABLE portal_audit_trail ADD COLUMN request_id VARCHAR(64) NULL AFTER id, ADD INDEX idx_request_id (request_id)");
        }

        if (!$columnExists('portal_users', 'default_schema_name')) {
            $db->exec("ALTER TABLE portal_users ADD COLUMN default_schema_name VARCHAR(64) NOT NULL DEFAULT 'HeraTesting' AFTER status");
        }
        if (!$columnExists('portal_users', 'last_login')) {
            $db->exec('ALTER TABLE portal_users ADD COLUMN last_login DATETIME NULL AFTER default_schema_name');
        }
        if (!$columnExists('portal_users', 'updated_at')) {
            $db->exec('ALTER TABLE portal_users ADD COLUMN updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS operation_confirmations (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(80) NOT NULL UNIQUE,
            username VARCHAR(80) NULL,
            action VARCHAR(80) NOT NULL,
            payload LONGTEXT NOT NULL,
            status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            INDEX idx_confirm_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS api_keys (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(150) NOT NULL,
            key_hash CHAR(64) NOT NULL UNIQUE,
            status ENUM('active','revoked') NOT NULL DEFAULT 'active',
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            INDEX idx_key_status (key_hash, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS api_request_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            key_id INT NOT NULL,
            endpoint VARCHAR(80) NULL,
            ip_address VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_key_created (key_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("INSERT IGNORE INTO role_permissions(role_name, permission_key) VALUES ('admin','manage_api_keys')");

        $db->exec("CREATE TABLE IF NOT EXISTS app_secrets (
            name VARCHAR(80) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS integrations (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            service_type ENUM('smsc','ussd','ivr','monitoring','other') NOT NULL DEFAULT 'other',
            name VARCHAR(150) NOT NULL,
            protocol ENUM('http','tcp') NOT NULL DEFAULT 'http',
            host VARCHAR(255) NULL,
            port INT NULL,
            base_url VARCHAR(500) NULL,
            health_check_path VARCHAR(255) NULL,
            auth_type ENUM('none','basic','bearer','api_key') NOT NULL DEFAULT 'none',
            auth_credential_enc TEXT NULL,
            status ENUM('active','inactive','testing') NOT NULL DEFAULT 'testing',
            last_check_at DATETIME NULL,
            last_check_ok TINYINT(1) NULL,
            last_check_latency_ms INT NULL,
            last_check_message VARCHAR(500) NULL,
            notes TEXT NULL,
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_integration_type_status (service_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // History of every Test click, not just the latest — integrations.last_check_* only ever
        // held the most recent result, which can't show a flaky connection or a real uptime trend.
        $db->exec("CREATE TABLE IF NOT EXISTS integration_checks (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            integration_id INT NOT NULL,
            checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ok TINYINT(1) NOT NULL,
            latency_ms INT NULL,
            message VARCHAR(500) NULL,
            INDEX idx_integration_checked (integration_id, checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("INSERT IGNORE INTO role_permissions(role_name, permission_key) VALUES ('admin','manage_ussd_menus'),('manager','manage_ussd_menus'),('operator','manage_ussd_menus')");
        $db->exec("INSERT IGNORE INTO role_permissions(role_name, permission_key) VALUES ('admin','manage_promotions'),('manager','manage_promotions')");

        // USSD/IVR menu tree — a self-referencing hierarchy (root nodes have parent_id NULL). This is
        // a design/staging tool: it defines and previews the menu structure and exports it as JSON.
        // It does NOT push configuration to Mobius or any other real gateway — that would need the
        // gateway's own menu-config API, which is not something this app has access to yet.
        $db->exec("CREATE TABLE IF NOT EXISTS ussd_menu_nodes (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NULL,
            short_code VARCHAR(80) NOT NULL,
            display_order INT NOT NULL DEFAULT 0,
            prompt_text VARCHAR(300) NOT NULL,
            node_type ENUM('menu','offer','action','end','catalog','recipient','quiz','sharedbundle','flow') NOT NULL DEFAULT 'menu',
            offer_code VARCHAR(80) NULL,
            catalog_filter TEXT NULL,
            body_text TEXT NULL,
            action_key VARCHAR(80) NULL,
            status ENUM('active','inactive','draft') NOT NULL DEFAULT 'draft',
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_menu_shortcode_parent (short_code, parent_id, display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // One-time migration of the old smsc-only registry into the unified integrations table.
        // Gated on a persisted marker, not on matching row names against smsc_connections — matching
        // by name broke the first time a migrated row got renamed (it no longer matched its source
        // row, so the "already migrated" check failed open and re-inserted a duplicate on the next
        // request). A marker in app_secrets runs this at most once, ever, regardless of what happens
        // to the migrated rows afterward.
        if ($columnExists('smsc_connections', 'id')) {
            $st = $db->prepare('SELECT 1 FROM app_secrets WHERE name=?'); $st->execute(['smsc_connections_migrated']);
            if (!$st->fetch()) {
                $safeExecEarly2 = function (string $sql) use ($db): void { try { $db->exec($sql); } catch (Throwable $e) {} };
                $safeExecEarly2("INSERT INTO integrations(service_type,name,protocol,host,port,auth_type,status,notes,created_at)
                    SELECT 'smsc', s.name, 'tcp', s.host, s.port, 'none', s.status,
                           TRIM(BOTH ' | ' FROM CONCAT_WS(' | ', s.notes, CONCAT('system_id=', COALESCE(s.system_id,''), ' bind=', COALESCE(s.bind_type,'')))),
                           s.created_at
                    FROM smsc_connections s
                    WHERE NOT EXISTS (SELECT 1 FROM integrations i WHERE i.name = s.name AND i.service_type = 'smsc')");
                $safeExecEarly2("INSERT IGNORE INTO app_secrets(name,value) VALUES('smsc_connections_migrated', '1')");
            }
        }

        // De-dupes push alerts (see send_slack_alert()/dispatch_pending_alert_notifications()) so an
        // ongoing incident re-notifies at most every ALERT_RENOTIFY_MINUTES instead of once per page
        // load or per cron tick.
        $db->exec("CREATE TABLE IF NOT EXISTS alert_notification_log (
            alert_key VARCHAR(191) NOT NULL PRIMARY KEY,
            last_sent_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Named groups of offer codes for the Promotion Performance report — portal governance data
        // (which offer codes belong to "Summer Promo"), never Hera business data. offer_code_for_other
        // is deliberately NOT duplicated here; the report pulls it live from vas_offers so an edit to
        // an offer's other-network code is reflected immediately instead of going stale.
        $db->exec("CREATE TABLE IF NOT EXISTS promotions (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            schema_name VARCHAR(64) NOT NULL DEFAULT 'HeraTesting',
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_promotion_name_schema (name, schema_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS promotion_offers (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            promotion_id INT NOT NULL,
            offer_code VARCHAR(80) NOT NULL,
            UNIQUE KEY uq_promotion_offer (promotion_id, offer_code),
            CONSTRAINT fk_promo_offer_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Short-lived results of heavy aggregate queries (shared by every pod and viewer) so the Dashboard,
        // Monitoring and Alerts pages don't each re-scan today's audit_log on every view. See cached().
        $db->exec("CREATE TABLE IF NOT EXISTS metric_cache (
            cache_key VARCHAR(190) NOT NULL PRIMARY KEY,
            value MEDIUMTEXT NOT NULL,
            expires_at INT NOT NULL,
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // USSD proxy endpoint (Mobius PROXY / MS_INITIATED menus call it): settings, a short-lived request log used to
        // learn the gateway's format, and per-session reply history (the screen engine itself is stateless).
        $db->exec("CREATE TABLE IF NOT EXISTS ussd_proxy_config (
            name VARCHAR(60) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS ussd_proxy_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            mode VARCHAR(20) NOT NULL,
            remote_ip VARCHAR(80) NULL,
            method VARCHAR(10) NULL,
            query_text TEXT NULL,
            headers_text TEXT NULL,
            body_text MEDIUMTEXT NULL,
            response_text TEXT NULL,
            ms INT NULL,
            note VARCHAR(200) NULL,
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS ussd_proxy_sessions (
            session_key VARCHAR(120) NOT NULL PRIMARY KEY,
            shortcode VARCHAR(80) NOT NULL,
            replies TEXT NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Notes people leave against a subscriber while handling a complaint (shown on Customer Timeline).
        // Never deleted, like everything else here.
        $db->exec("CREATE TABLE IF NOT EXISTS complaint_notes (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            schema_name VARCHAR(64) NOT NULL,
            msisdn VARCHAR(20) NOT NULL,
            transaction_id VARCHAR(64) NULL,
            note TEXT NOT NULL,
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notes_msisdn (schema_name, msisdn, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Who receives alert emails — managed on the Alerts page. Rows are enabled/disabled, never
        // deleted (same no-hard-delete convention as the rest of the app). The mail SERVER settings
        // and password stay in environment variables, not here.
        $db->exec("CREATE TABLE IF NOT EXISTS alert_recipients (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            notify_failure TINYINT(1) NOT NULL DEFAULT 1,
            notify_vendor TINYINT(1) NOT NULL DEFAULT 1,
            notify_slow TINYINT(1) NOT NULL DEFAULT 1,
            notify_summary TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_alert_recipient_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Databases created before per-recipient preferences existed.
        if (!$columnExists('alert_recipients', 'notify_failure')) {
            $db->exec("ALTER TABLE alert_recipients ADD COLUMN notify_failure TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN notify_vendor TINYINT(1) NOT NULL DEFAULT 1");
        }

        if (!$columnExists('alert_recipients', 'notify_slow')) {
            $db->exec("ALTER TABLE alert_recipients ADD COLUMN notify_slow TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN notify_summary TINYINT(1) NOT NULL DEFAULT 1");
        }

        // What the alerts fire on (edited on the Alert Settings page): on/off and limits per alert
        // type, plus failure reasons that shouldn't count toward the failure-rate alert (e.g. a
        // customer having no credit is not an outage).
        $db->exec("CREATE TABLE IF NOT EXISTS alert_config (
            name VARCHAR(60) NOT NULL PRIMARY KEY,
            value VARCHAR(60) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS alert_ignored_reasons (
            reason VARCHAR(500) NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Mail server for alert email, edited on the Alerts page (single row, id=1). The password is
        // encrypted at rest with the same key/mechanism as Integrations credentials and is never
        // rendered back to the page. Environment variables (SMTP_*) remain a fallback when this row
        // has no host.
        $db->exec("CREATE TABLE IF NOT EXISTS alert_smtp (
            id TINYINT NOT NULL PRIMARY KEY,
            host VARCHAR(255) NOT NULL DEFAULT '',
            port INT NOT NULL DEFAULT 587,
            secure ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
            username VARCHAR(190) NOT NULL DEFAULT '',
            password_enc TEXT NULL,
            from_email VARCHAR(190) NOT NULL DEFAULT '',
            tls_verify TINYINT(1) NOT NULL DEFAULT 1,
            updated_by VARCHAR(80) NULL,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS saved_queries (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            schema_name VARCHAR(64) NOT NULL DEFAULT 'HeraTesting',
            sql_text MEDIUMTEXT NOT NULL,
            category VARCHAR(80) NULL,
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_saved_schema (schema_name, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS portal_projects (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_name VARCHAR(180) NOT NULL,
            short_code VARCHAR(80) NULL,
            status ENUM('Planning','Development','Testing','Launched','Completed','Suspended') NOT NULL DEFAULT 'Planning',
            start_date DATE NULL,
            launch_date DATE NULL,
            description TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_status (status),
            INDEX idx_project_short_code (short_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS portal_short_codes (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            channel_type ENUM('USSD','IVR') NOT NULL DEFAULT 'USSD',
            short_code VARCHAR(80) NOT NULL,
            service_name VARCHAR(180) NOT NULL,
            provider VARCHAR(150) NULL,
            status ENUM('Active','Inactive','Pending','Suspended') NOT NULL DEFAULT 'Pending',
            description TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_channel_shortcode_service (channel_type, short_code, service_name),
            INDEX idx_channel_type (channel_type),
            INDEX idx_shortcode_status (status),
            INDEX idx_shortcode (short_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL,
            ip_address VARCHAR(80) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username_created (username, created_at),
            INDEX idx_ip_created (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_name VARCHAR(50) NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            UNIQUE KEY uq_role_perm (role_name, permission_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("INSERT IGNORE INTO role_permissions(role_name, permission_key) VALUES
            ('admin','view_dashboard'),('admin','view_tables'),('admin','create_records'),('admin','edit_records'),('admin','duplicate_records'),('admin','copy_records'),('admin','run_sql'),('admin','manage_users'),('admin','manage_projects'),('admin','manage_shortcodes'),('admin','view_audit'),('admin','view_reports'),
            ('manager','view_dashboard'),('manager','view_tables'),('manager','create_records'),('manager','edit_records'),('manager','duplicate_records'),('manager','copy_records'),('manager','run_sql'),('manager','manage_projects'),('manager','manage_shortcodes'),('manager','view_audit'),('manager','view_reports'),
            ('operator','view_dashboard'),('operator','view_tables'),('operator','create_records'),('operator','edit_records'),('operator','duplicate_records'),('operator','copy_records'),('operator','manage_projects'),('operator','manage_shortcodes'),('operator','view_reports'),
            ('viewer','view_dashboard'),('viewer','view_tables'),('viewer','view_reports')");
        // Older installs may have role_permissions without view_reports; add it for every existing role.
        $safeExecEarly = function(string $sql) use ($db): void { try { $db->exec($sql); } catch (Throwable $e) {} };
        $safeExecEarly("INSERT IGNORE INTO role_permissions(role_name, permission_key) SELECT DISTINCT role_name, 'view_reports' FROM role_permissions");

        $db->exec("CREATE TABLE IF NOT EXISTS portal_project_channels (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            channel_id INT NOT NULL,
            short_code VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_channel (project_id, channel_id),
            INDEX idx_project_channel_shortcode (short_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Safe migration for older project/channel module versions.
        $safeExec = function(string $sql) use ($db): void { try { $db->exec($sql); } catch (Throwable $e) {} };
        if (!$columnExists('portal_short_codes','channel_type')) $safeExec("ALTER TABLE portal_short_codes ADD COLUMN channel_type ENUM('USSD','IVR') NOT NULL DEFAULT 'USSD' AFTER id");
        $safeExec("UPDATE portal_short_codes SET status = CASE LOWER(status) WHEN 'active' THEN 'Active' WHEN 'testing' THEN 'Pending' WHEN 'paused' THEN 'Suspended' WHEN 'retired' THEN 'Inactive' ELSE status END");
        $safeExec("ALTER TABLE portal_short_codes MODIFY short_code VARCHAR(80) NOT NULL");
        $safeExec("ALTER TABLE portal_short_codes MODIFY status ENUM('Active','Inactive','Pending','Suspended') NOT NULL DEFAULT 'Pending'");
        if ($columnExists('portal_short_codes','route_type')) $safeExec("UPDATE portal_short_codes SET channel_type = IF(route_type='IVR','IVR','USSD') WHERE channel_type IS NULL OR channel_type=''");

        if (!$columnExists('ussd_menu_nodes','catalog_filter')) {
            $safeExec("ALTER TABLE ussd_menu_nodes MODIFY node_type ENUM('menu','offer','action','end','catalog') NOT NULL DEFAULT 'menu'");
            $safeExec("ALTER TABLE ussd_menu_nodes ADD COLUMN catalog_filter TEXT NULL AFTER offer_code");
        }
        $nodeTypeDef = (string)$db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ussd_menu_nodes' AND COLUMN_NAME='node_type'")->fetchColumn();
        if ($nodeTypeDef !== '' && stripos($nodeTypeDef, "'flow'") === false) $safeExec("ALTER TABLE ussd_menu_nodes MODIFY node_type ENUM('menu','offer','action','end','catalog','recipient','quiz','sharedbundle','flow') NOT NULL DEFAULT 'menu'");
        if (!$columnExists('ussd_menu_nodes','body_text')) $safeExec("ALTER TABLE ussd_menu_nodes ADD COLUMN body_text TEXT NULL AFTER catalog_filter");
        if (!$columnExists('portal_projects','short_code')) $safeExec("ALTER TABLE portal_projects ADD COLUMN short_code VARCHAR(80) NULL AFTER project_name");
        if (!$columnExists('portal_projects','start_date')) $safeExec("ALTER TABLE portal_projects ADD COLUMN start_date DATE NULL AFTER status");
        if (!$columnExists('portal_projects','launch_date')) $safeExec("ALTER TABLE portal_projects ADD COLUMN launch_date DATE NULL AFTER start_date");
        $safeExec("UPDATE portal_projects SET status = CASE LOWER(status) WHEN 'active' THEN 'Launched' WHEN 'testing' THEN 'Testing' WHEN 'paused' THEN 'Suspended' WHEN 'completed' THEN 'Completed' WHEN 'retired' THEN 'Suspended' ELSE status END");
        $safeExec("ALTER TABLE portal_projects MODIFY status ENUM('Planning','Development','Testing','Launched','Completed','Suspended') NOT NULL DEFAULT 'Planning'");
        if ($columnExists('portal_projects','project_code')) $safeExec("ALTER TABLE portal_projects MODIFY project_code VARCHAR(80) NULL");

        // Older installs created these as ENUM('HeraTesting','HeraProduction'); widen so HeraStaging/Hera can be used.
        foreach ([['portal_users', 'default_schema_name'], ['promotions', 'schema_name'], ['saved_queries', 'schema_name']] as [$t, $col]) {
            $st = $db->prepare('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
            $st->execute([$schema, $t, $col]);
            $type = $st->fetchColumn();
            if ($type === 'enum') $db->exec("ALTER TABLE `$t` MODIFY `$col` VARCHAR(64) NOT NULL DEFAULT 'HeraTesting'");
        }
        if (!$columnExists('portal_users', 'allowed_schemas')) {
            $db->exec("ALTER TABLE portal_users ADD COLUMN allowed_schemas VARCHAR(255) NULL AFTER default_schema_name");
        }
        // Older installs were created with ENUM('admin','operator','viewer'); the Manager role (and its permissions) needs the wider list.
        $st = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='portal_users' AND COLUMN_NAME='role'");
        $st->execute([$schema]);
        if (($t = $st->fetchColumn()) && stripos((string)$t, "'manager'") === false) {
            $db->exec("ALTER TABLE portal_users MODIFY role ENUM('admin','manager','operator','viewer') NOT NULL DEFAULT 'viewer'");
        }
        $db->exec("UPDATE portal_users SET default_schema_name='HeraTesting' WHERE default_schema_name IS NULL OR default_schema_name='' ");
    } catch (Throwable $e) {
        // Do not block the whole portal if migration fails; the visible page will show the real DB error.
    }
}
function allowed_schemas(): array { return app_config('allowed_schemas'); }
// Databases this user may open. Admins always get every allowed database; anyone else gets the ones an admin
// ticked on the Users page (portal_users.allowed_schemas, comma-separated). NULL = all, so existing users are unchanged.
function user_schemas(): array {
    $all = allowed_schemas(); $u = user();
    if (!$u || ($u['role'] ?? '') === 'admin' || ($u['allowed_schemas'] ?? null) === null || trim((string)$u['allowed_schemas']) === '') return $all;
    $mine = array_values(array_intersect($all, array_map('trim', explode(',', (string)$u['allowed_schemas']))));
    return $mine ?: [$all[0]];
}
function current_schema(): string {
    $mine = user_schemas(); $s = $_SESSION['schema'] ?? app_config('default_schema');
    if (in_array($s, $mine, true)) return $s;
    $d = app_config('default_schema');
    return in_array($d, $mine, true) ? $d : $mine[0];
}
function set_current_schema(string $s): void { if (in_array($s, user_schemas(), true)) $_SESSION['schema']=$s; }
function opposite_schema(string $s): string { return is_protected_schema($s) ? 'HeraTesting' : 'HeraProduction'; }
// Live databases: no free-form SQL writes, never the target of a truncating sync. Anything else
// (HeraTesting, HeraStaging) is a scratch/pre-prod copy.
const PROTECTED_SCHEMAS = ['HeraProduction', 'Hera'];
function is_protected_schema(string $s): bool { return in_array($s, PROTECTED_SCHEMAS, true); }
function schema_label(string $s): string { return ['HeraProduction' => 'Production', 'HeraTesting' => 'Testing', 'HeraStaging' => 'Staging', 'Hera' => 'Hera (live)'][$s] ?? $s; }
function schema_short(string $s): string { return ['HeraProduction' => 'PROD', 'HeraTesting' => 'TEST', 'HeraStaging' => 'STAGING', 'Hera' => 'HERA'][$s] ?? strtoupper($s); }
// css class used for the environment pill: prod (red) for live databases, stage (blue), test (amber)
function schema_kind(string $s): string { return is_protected_schema($s) ? 'prod' : ($s === 'HeraStaging' ? 'stage' : 'test'); }
function ident(string $name): string { if (!preg_match('/^[A-Za-z0-9_]+$/',$name)) throw new InvalidArgumentException('Invalid identifier: '.$name); return '`'.$name.'`'; }
function full_ident(string $schema,string $table): string { return ident($schema).'.'.ident($table); }

function user(): ?array { return $_SESSION['user'] ?? null; }
const SESSION_IDLE_SECONDS = 3600;
// Re-reads the user on every request so disabling a user or changing their role takes effect immediately
// instead of only after their session expires, and signs out sessions idle for more than an hour.
function require_login(): void {
    $u = user();
    if (!$u) redirect('?page=login');
    if (time() - (int)($_SESSION['last_seen'] ?? time()) > SESSION_IDLE_SECONDS) {
        unset($_SESSION['user'], $_SESSION['last_seen']); flash('info', 'You were signed out after a period of inactivity.'); redirect('?page=login');
    }
    $_SESSION['last_seen'] = time();
    try {
        $st = portal_pdo()->prepare('SELECT id,full_name,username,role,status,default_schema_name,allowed_schemas FROM portal_users WHERE id=?');
        $st->execute([(int)$u['id']]); $fresh = $st->fetch();
    } catch (Throwable $e) { return; }
    if (!$fresh || $fresh['status'] !== 'active') { unset($_SESSION['user'], $_SESSION['last_seen']); flash('danger', 'Your account is no longer active.'); redirect('?page=login'); }
    $_SESSION['user'] = array_merge($u, $fresh);
    if (!empty($_SESSION['must_change_pw']) && !in_array($_GET['page'] ?? '', ['account', 'logout'], true)) { flash('warning', 'You are using the default password. Choose a new one to continue.'); redirect('?page=account'); }
    if (random_int(1, 300) === 1) portal_housekeeping();
}
// Old rows nothing reads any more. Runs from the 5-minute alert cron and now and then from a page view, so these log tables cannot grow for ever.
function portal_housekeeping(): void {
    static $ran = false; if ($ran) return; $ran = true;
    foreach ([['login_attempts', 'created_at', 30], ['api_request_log', 'created_at', 30], ['integration_checks', 'checked_at', 30], ['operation_confirmations', 'created_at', 7], ['ussd_flow_calls', 'created_at', 60]] as [$t, $c, $d]) {
        try { portal_pdo()->exec("DELETE FROM `$t` WHERE `$c` < NOW() - INTERVAL $d DAY LIMIT 5000"); } catch (Throwable $e) {}
    }
}

// The password the install script gives the first admin. Whoever signs in with it is sent to My Account until it is changed.
const KNOWN_DEFAULT_PASSWORDS = ['admin123'];
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

// The caller's address. Behind the ingress every request arrives from the proxy's own (private) address, and the real caller is the
// last entry the proxy added to X-Forwarded-For. A request that comes straight from a public address has that header ignored, so it
// cannot be forged to dodge the login lock-out or the USSD allow-list.
function ip_is_internal(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
// $person = true: the person behind the proxies (skips trailing private/loopback entries, which are proxies in front of the ingress);
// false: the last entry as it is, which is what the USSD allow-list compares (Mobius itself has a private address).
function client_ip(bool $person = true): string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? ''); $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '' && ip_is_internal($remote)) {
        $parts = array_values(array_filter(array_map('trim', explode(',', $xff)), fn($x) => filter_var($x, FILTER_VALIDATE_IP) !== false));
        if ($parts) { if ($person) foreach (array_reverse($parts) as $ip) if (!ip_is_internal($ip)) return $ip; return end($parts); }
    }
    return substr($remote !== '' ? $remote : 'unknown', 0, 80);
}

function record_login_attempt(string $username, bool $success): void {
    try { portal_pdo()->prepare('INSERT INTO login_attempts(username,ip_address,success) VALUES(?,?,?)')->execute([$username, client_ip(), $success ? 1 : 0]); } catch (Throwable $e) {}
}

function is_login_locked_out(string $username): bool {
    try {
        $st = portal_pdo()->prepare('SELECT COUNT(*) c FROM login_attempts WHERE username=? AND success=0 AND created_at > (NOW() - INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)');
        $st->execute([$username]);
        if ((int)$st->fetch()['c'] >= LOGIN_MAX_ATTEMPTS) return true;
        $st = portal_pdo()->prepare('SELECT COUNT(*) c FROM login_attempts WHERE ip_address=? AND success=0 AND created_at > (NOW() - INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)');
        // When every caller shows the same private/loopback address (the proxy in front hides the real one) a per-address rule would lock everybody
        // out because of one person, so then only the per-username rule applies.
        if (ip_is_internal(client_ip())) return false;
        $st->execute([client_ip()]);
        return (int)$st->fetch()['c'] >= (LOGIN_MAX_ATTEMPTS * 4);
    } catch (Throwable $e) { return false; }
}

function login_attempt(string $username,string $password): bool {
    if ($username === '' || is_login_locked_out($username)) { record_login_attempt($username, false); return false; }
    $st=portal_pdo()->prepare('SELECT * FROM portal_users WHERE username=? AND status="active" LIMIT 1'); $st->execute([$username]); $u=$st->fetch();
    if (!$u) password_verify($password, '$2y$12$36THbWOafxnZWHeHkqOj8OTvvx8VoKfXq4IAbTOSlP9iVgnIdjH92'); // same time taken for an unknown name, so names cannot be guessed from the response time
    if ($u && password_verify($password,$u['password_hash'])) { record_login_attempt($username, true); session_regenerate_id(true); if (in_array($password, KNOWN_DEFAULT_PASSWORDS, true)) $_SESSION['must_change_pw'] = true; else unset($_SESSION['must_change_pw']); $_SESSION['user']=array_diff_key($u,['password_hash'=>1]); $_SESSION['last_seen']=time(); set_current_schema($u['default_schema_name'] ?? app_config('default_schema')); portal_pdo()->prepare('UPDATE portal_users SET last_login=NOW() WHERE id=?')->execute([$u['id']]); audit('login',null,null,null,'User logged in'); return true; }
    record_login_attempt($username, false);
    return false;
}
function logout(): void { audit('logout',null,null,null,'User logged out'); $_SESSION=[]; session_destroy(); }
function permissions_for_role(string $role): array { $st=portal_pdo()->prepare('SELECT permission_key FROM role_permissions WHERE role_name=?'); $st->execute([$role]); return array_column($st->fetchAll(),'permission_key'); }
function can(string $perm): bool { $u=user(); if (!$u) return false; if (($u['role']??'')==='admin') return true; return in_array($perm, permissions_for_role($u['role']), true); }
function require_perm(string $perm): void { if (!can($perm)) throw new RuntimeException('You do not have permission to perform this action.'); }
function role_badge(string $role): string { return ['admin'=>'danger','manager'=>'primary','operator'=>'warning','viewer'=>'secondary'][$role] ?? 'secondary'; }

function audit(string $action, ?string $schema=null, ?string $table=null, ?string $key=null, ?string $details=null): void {
    try { $u=user(); portal_pdo()->prepare('INSERT INTO portal_audit_trail(request_id,user_id,username,action,schema_name,target_table,target_key,ip_address,user_agent,details) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([request_id(),$u['id']??null,$u['username']??null,$action,$schema,$table,$key,client_ip(),substr($_SERVER['HTTP_USER_AGENT']??'',0,255),$details]); } catch(Throwable $e) {}
}

function table_names(string $schema): array { $st=pdo($schema)->query("SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA=".pdo($schema)->quote($schema)." ORDER BY TABLE_NAME"); return array_column($st->fetchAll(),'table_name'); }
function table_exists(string $schema,string $table): bool { return in_array($table, table_names($schema), true); }
function columns(string $schema,string $table): array { $st=pdo($schema)->prepare('SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, DATA_TYPE AS data_type, IS_NULLABLE AS nullable, COLUMN_KEY AS ckey, EXTRA AS extra, COLUMN_DEFAULT AS def FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION'); $st->execute([$schema,$table]); return $st->fetchAll(); }
function column_exists(string $schema, string $table, string $col): bool {
    $st = pdo($schema)->prepare('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$schema, $table, $col]);
    return (int)$st->fetch()['c'] > 0;
}
function index_exists(string $schema, string $table, string $index): bool {
    $st = pdo($schema)->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
    $st->execute([$schema, $table, $index]);
    return (int)$st->fetchColumn() > 0;
}
function primary_columns(string $schema,string $table): array { $cols=columns($schema,$table); $pk=array_values(array_map(fn($c)=>$c['name'], array_filter($cols, fn($c)=>$c['ckey']==='PRI'))); if (!$pk && $cols) $pk=[$cols[0]['name']]; return $pk; }
function table_count(string $schema,string $table): int { try { return (int)pdo($schema)->query('SELECT COUNT(*) c FROM '.ident($table))->fetch()['c']; } catch(Throwable $e) { return 0; } }
// Estimate only (InnoDB's cached statistics, no table scan) — safe to call once per table on every
// dashboard/listing page load even for huge tables (subscription: ~89M rows, audit_log: ~227M rows,
// neither has a supporting index for a cheap exact COUNT(*)).
function approx_table_count(string $schema,string $table): int { try { $st=pdo($schema)->prepare('SELECT TABLE_ROWS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?'); $st->execute([$schema,$table]); $r=$st->fetch(); return $r?(int)$r['c']:0; } catch(Throwable $e) { return 0; } }
function build_pk_where(string $schema,string $table,array $rowOrGet,array &$params): string { $parts=[]; foreach(primary_columns($schema,$table) as $pk){ if(!array_key_exists($pk,$rowOrGet)) throw new RuntimeException('Missing primary key '.$pk); $parts[]=ident($pk).'=?'; $params[]=$rowOrGet[$pk]; } return implode(' AND ',$parts); }
function fetch_record(string $schema,string $table,array $keys): ?array { $params=[]; $where=build_pk_where($schema,$table,$keys,$params); $st=pdo($schema)->prepare('SELECT * FROM '.ident($table).' WHERE '.$where.' LIMIT 1'); $st->execute($params); return $st->fetch() ?: null; }
function row_key_query(string $schema,string $table,array $row): string { $pairs=[]; foreach(primary_columns($schema,$table) as $pk) $pairs[$pk]=$row[$pk]??''; return http_build_query($pairs); }
function is_auto_col(array $c): bool { return str_contains((string)$c['extra'],'auto_increment'); }
function distinct_column_values(string $schema, string $table, string $col, int $limit = 100): array {
    if (!table_exists($schema, $table)) return [];
    $st = pdo($schema)->prepare('SELECT DISTINCT '.ident($col).' v FROM '.ident($table).' WHERE '.ident($col).' IS NOT NULL AND '.ident($col)." != '' ORDER BY v LIMIT ".(int)$limit);
    $st->execute();
    return array_column($st->fetchAll(), 'v');
}
// distinct_column_values() with no filter does a full scan on a table subscription's size (~89M rows,
// no supporting index for an unbounded DISTINCT) — fine for vas_offers, not fine here. Bounded to a
// recent window so it can use the (date, channel) index instead of scanning the whole table; channel
// is a small, stable set of values, so recent history is all a dropdown actually needs.
function distinct_recent_channels(...$a) { return cached('distinct_recent_channels:'.md5(serialize($a)), 21600, fn() => distinct_recent_channels_uncached(...$a)); }
function distinct_recent_channels_uncached(string $schema, int $days = 30): array {
    if (!table_exists($schema, 'subscription')) return [];
    $st = pdo($schema)->prepare("SELECT DISTINCT channel v FROM subscription WHERE date >= NOW() - INTERVAL ? DAY AND channel IS NOT NULL AND channel != '' ORDER BY v LIMIT 50");
    $st->bindValue(1, $days, PDO::PARAM_INT);
    $st->execute();
    return array_column($st->fetchAll(), 'v');
}
function normalize_free_data_to_mb(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return $raw;
    if (preg_match('/^([\d.]+)\s*GB$/i', $raw, $m)) return (string)(int)round(((float)$m[1]) * 1024);
    if (preg_match('/^([\d.]+)\s*MB$/i', $raw, $m)) return (string)(int)round((float)$m[1]);
    return $raw;
}
function editable_columns(string $schema,string $table,bool $includePk=false): array { return array_values(array_filter(columns($schema,$table), fn($c)=>$includePk || !is_auto_col($c))); }

const SENSITIVE_COLUMN_PATTERN = '/password|secret|token|hash|otp|pin_code|^pin$|cvv|card_number|auth_data|credential|api_key/i';
function is_sensitive_column(string $name): bool { return (bool)preg_match(SENSITIVE_COLUMN_PATTERN, $name); }
function redact_row(array $row): array { foreach ($row as $k=>$v) if ($v!==null && is_sensitive_column((string)$k)) $row[$k]='••••••••'; return $row; }

function parse_table_filters(array $get): array {
    $filters=[]; foreach(($get['fcol']??[]) as $i=>$fc) $filters[]=['col'=>$fc,'op'=>($get['fop'][$i]??'contains'),'val'=>($get['fval'][$i]??'')]; return $filters;
}
function table_filter_where(string $schema,string $table,array $filters,array &$params): string {
    $where=[]; $cols=array_column(columns($schema,$table),'name');
    foreach($filters as $f){ $col=$f['col']??''; $op=$f['op']??'contains'; $val=(string)($f['val']??''); if($val===''||!in_array($col,$cols,true)) continue; $id=ident($col); if($op==='equals'){ $where[]="$id = ?"; $params[]=$val; } elseif($op==='starts'){ $where[]="$id LIKE ?"; $params[]=$val.'%'; } elseif($op==='ends'){ $where[]="$id LIKE ?"; $params[]='%'.$val; } elseif($op==='gt'){ $where[]="$id > ?"; $params[]=$val; } elseif($op==='lt'){ $where[]="$id < ?"; $params[]=$val; } else { $where[]="$id LIKE ?"; $params[]='%'.$val.'%'; } }
    return $where ? ' WHERE '.implode(' AND ',$where) : '';
}
function order_by_pk(string $schema,string $table): string { $pk=primary_columns($schema,$table); return $pk ? ' ORDER BY '.implode(',',array_map('ident',$pk)).' DESC' : ''; }
