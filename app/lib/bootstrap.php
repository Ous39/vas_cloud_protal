<?php
declare(strict_types=1);
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

// ===================== Large-table scan guard (generic Database Tables browser) =====================
// Dedicated pages (Subscriptions, Complaint Investigation) already require a bounded/indexed lookup
// for the two tables known to be huge (subscription ~89M rows, audit_log ~227M rows). This guard
// applies the same protection generically to the plain table browser/export, so ANY table that turns
// out to be this large — including ones added after this was written — gets it automatically instead
// of relying on someone remembering to build a dedicated page first.
const LARGE_TABLE_ROW_THRESHOLD = 1000000;

function indexed_columns(string $schema, string $table): array {
    $st = pdo($schema)->prepare('SELECT DISTINCT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $st->execute([$schema, $table]);
    return array_column($st->fetchAll(), 'COLUMN_NAME');
}

// Returns null if the browse/export is safe to run as-is, or ['level'=>'block'|'warn','message'=>...]
// if it's a large table being scanned without an index to lean on. 'block' means the caller should
// not run the query at all; 'warn' means it's safe to run but the UI should say why it may be slow.
function large_table_guard(string $schema, string $table, array $filters): ?array {
    if (approx_table_count($schema, $table) < LARGE_TABLE_ROW_THRESHOLD) return null;
    if (!$filters) {
        return ['level' => 'block', 'message' => 'This table has an estimated '.number_format(approx_table_count($schema,$table)).' rows. Browsing or exporting it without a filter would scan the whole table — add a filter (ideally on an indexed column) first.'];
    }
    $indexed = indexed_columns($schema, $table);
    $filteredCols = array_unique(array_filter(array_column($filters, 'col')));
    $anyIndexed = false;
    foreach ($filteredCols as $c) if (in_array($c, $indexed, true)) { $anyIndexed = true; break; }
    if (!$anyIndexed) {
        return ['level' => 'warn', 'message' => 'This table has an estimated '.number_format(approx_table_count($schema,$table)).' rows and none of your filter columns ('.implode(', ',$filteredCols).') has a database index — this query will scan the full table and may be slow.'];
    }
    return null;
}
function list_records(string $schema,string $table,array $filters,int $page,int $perPage): array {
    $params=[]; $where=table_filter_where($schema,$table,$filters,$params);
    $sql='FROM '.ident($table).$where;
    $st=pdo($schema)->prepare('SELECT COUNT(*) c '.$sql); $st->execute($params); $total=(int)$st->fetch()['c'];
    $offset=max(0,($page-1)*$perPage); $q='SELECT * '.$sql.order_by_pk($schema,$table).' LIMIT '.(int)$perPage.' OFFSET '.(int)$offset; $st=pdo($schema)->prepare($q); $st->execute($params); return ['rows'=>$st->fetchAll(),'total'=>$total];
}
const PAGE_SIZE_OPTIONS = [10, 20, 25];
function resolve_page_size(array $get, int $default = 25): int {
    $v = (int)($get['per_page'] ?? $default);
    return in_array($v, PAGE_SIZE_OPTIONS, true) ? $v : $default;
}
function export_records(string $schema,string $table,array $filters,int $limit=10000): array {
    $params=[]; $where=table_filter_where($schema,$table,$filters,$params);
    $q='SELECT * FROM '.ident($table).$where.order_by_pk($schema,$table).' LIMIT '.(int)$limit;
    $st=pdo($schema)->prepare($q); $st->execute($params); return $st->fetchAll();
}
function normalize_value($v){ return $v === '' ? null : $v; }
// What an update really changed (column => [from, to]), for the audit trail / Offer history. Sensitive
// columns are recorded as changed but never with their values.
function changed_fields(?array $before, array $data): array {
    $out = [];
    foreach ($data as $col => $new) {
        $old = $before[$col] ?? null; $newN = normalize_value($new);
        if ((string)$old === (string)$newN) continue;
        $out[$col] = is_sensitive_column((string)$col) ? ['from' => '••••', 'to' => '••••'] : ['from' => $old, 'to' => $newN];
    }
    return $out;
}
function insert_record(string $schema,string $table,array $data): void { $cols=editable_columns($schema,$table,false); $names=[];$vals=[];$params=[]; foreach($cols as $c){ if(array_key_exists($c['name'],$data)){ $names[]=ident($c['name']); $vals[]='?'; $params[]=normalize_value($data[$c['name']]); }} if(!$names) throw new RuntimeException('Nothing to insert'); pdo($schema)->prepare('INSERT INTO '.ident($table).'('.implode(',',$names).') VALUES('.implode(',',$vals).')')->execute($params); $newId=(int)pdo($schema)->lastInsertId(); audit('insert',$schema,$table,$newId>0?json_encode(['id'=>$newId]):null,json_encode(redact_row($data))); }
function update_record(string $schema,string $table,array $keys,array $data): void { $set=[];$params=[]; foreach(editable_columns($schema,$table,false) as $c){ if(in_array($c['name'],primary_columns($schema,$table),true)) continue; if(array_key_exists($c['name'],$data)){ $set[]=ident($c['name']).'=?'; $params[]=normalize_value($data[$c['name']]); }} if(!$set) throw new RuntimeException('Nothing to update'); $before=fetch_record($schema,$table,$keys); $where=build_pk_where($schema,$table,$keys,$params); pdo($schema)->prepare('UPDATE '.ident($table).' SET '.implode(',',$set).' WHERE '.$where.' LIMIT 1')->execute($params); audit('update',$schema,$table,json_encode($keys),json_encode(['changed'=>changed_fields($before,$data)])); }
function copy_record(string $from,string $to,string $table,array $keys,array $overrides=[]): void { assert_copy_schemas($from,$to,false); if(!table_exists($to,$table)) throw new RuntimeException('Table does not exist in the destination schema.'); $row=fetch_record($from,$table,$keys); if(!$row) throw new RuntimeException('Source record not found'); foreach($overrides as $k=>$v) if(array_key_exists($k,$row)) $row[$k]=normalize_value($v); $cols=columns($to,$table); $names=[];$vals=[];$params=[]; foreach($cols as $c){ if(is_auto_col($c)) continue; if(array_key_exists($c['name'],$row)){ $names[]=ident($c['name']); $vals[]='?'; $params[]=$row[$c['name']]; }} pdo($to)->prepare('REPLACE INTO '.ident($table).'('.implode(',',$names).') VALUES('.implode(',',$vals).')')->execute($params); audit('copy_record',$to,$table,json_encode($keys),"from=$from to=$to"); }
function assert_copy_schemas(string $from, string $to, bool $mustDiffer): void {
    if (!in_array($from, user_schemas(), true) || !in_array($to, user_schemas(), true)) throw new RuntimeException('Unknown source or destination schema, or you do not have access to it.');
    if ($mustDiffer && $from === $to) throw new RuntimeException('Source and destination must be different schemas.');
}
function sync_table(string $from,string $to,string $table): int {
    assert_copy_schemas($from, $to, true);
    if (!table_exists($from, $table) || !table_exists($to, $table)) throw new RuntimeException('Table must exist in both schemas.');
    // Destructive TRUNCATE+reload is only ever allowed when the destination is HeraTesting.
    // HeraProduction can only be updated via merge_table(), which never deletes existing rows.
    if (is_protected_schema($to)) throw new RuntimeException('Full-table sync cannot target '.$to.' (would truncate live data). Use Merge instead.');
    $cols=array_column(columns($from,$table),'name'); $colsql=implode(',',array_map('ident',$cols));
    pdo($to)->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        pdo($to)->exec('TRUNCATE TABLE '.ident($table));
        $affected=pdo($to)->exec('INSERT INTO '.ident($table).'('.$colsql.') SELECT '.$colsql.' FROM '.ident($from).'.'.ident($table));
    } finally { pdo($to)->exec('SET FOREIGN_KEY_CHECKS=1'); } // this connection is reused: never leave the checks off
    audit('sync_table',$to,$table,null,"from=$from rows=$affected");
    return (int)$affected;
}

function merge_table(string $from,string $to,string $table): int {
    assert_copy_schemas($from, $to, true);
    if (!table_exists($from, $table) || !table_exists($to, $table)) throw new RuntimeException('Table must exist in both schemas.');
    // Non-destructive alternative: upserts rows by primary key, never deletes/truncates.
    $pk = primary_columns($from,$table);
    if (!$pk) throw new RuntimeException('Table has no primary key; merge requires one to avoid duplicating rows.');
    $cols=array_column(columns($from,$table),'name'); $colsql=implode(',',array_map('ident',$cols));
    $updateCols = array_diff($cols, $pk);
    $updateSql = implode(',', array_map(fn($c)=>ident($c).'=VALUES('.ident($c).')', $updateCols));
    $sql = 'INSERT INTO '.ident($table).'('.$colsql.') SELECT '.$colsql.' FROM '.ident($from).'.'.ident($table);
    $sql .= $updateSql !== '' ? ' ON DUPLICATE KEY UPDATE '.$updateSql : '';
    $affected=pdo($to)->exec($sql);
    audit('merge_table',$to,$table,null,"from=$from rows=$affected");
    return (int)$affected;
}

function all_channels(): array { return portal_pdo()->query('SELECT id, channel_type, short_code, service_name, provider, status FROM portal_short_codes ORDER BY short_code, channel_type, service_name')->fetchAll(); }
function project_channel_ids(int $projectId): array { $st=portal_pdo()->prepare('SELECT channel_id FROM portal_project_channels WHERE project_id=?'); $st->execute([$projectId]); return array_map('intval', array_column($st->fetchAll(),'channel_id')); }
function project_channels_label(int $projectId): string { $st=portal_pdo()->prepare('SELECT c.channel_type, c.short_code, c.service_name FROM portal_project_channels pc JOIN portal_short_codes c ON c.id=pc.channel_id WHERE pc.project_id=? ORDER BY c.short_code,c.channel_type'); $st->execute([$projectId]); $items=[]; foreach($st->fetchAll() as $r) $items[]=$r['channel_type'].' '.$r['short_code'].' - '.$r['service_name']; return implode(', ', $items); }
function save_shortcode(array $data, ?int $id=null): void { $payload=[normalize_value($data['channel_type']??'USSD'), normalize_value($data['short_code']??''), normalize_value($data['service_name']??''), normalize_value($data['provider']??''), normalize_value($data['status']??'Pending'), normalize_value($data['description']??'')]; if(!$payload[1] || !$payload[2]) throw new RuntimeException('Short Code and Service Name are required.'); if($id){ $payload[]=$id; portal_pdo()->prepare('UPDATE portal_short_codes SET channel_type=?, short_code=?, service_name=?, provider=?, status=?, description=?, updated_at=NOW() WHERE id=?')->execute($payload); audit('save_shortcode','vas_portal','portal_short_codes',(string)$id,json_encode($data)); } else { portal_pdo()->prepare('INSERT INTO portal_short_codes(channel_type,short_code,service_name,provider,status,description) VALUES(?,?,?,?,?,?)')->execute($payload); audit('save_shortcode','vas_portal','portal_short_codes',null,json_encode($data)); } }
function save_project(array $data, array $channelIds=[], ?int $id=null): void { $payload=[normalize_value($data['project_name']??''), normalize_value($data['short_code']??''), normalize_value($data['status']??'Planning'), normalize_value($data['start_date']??''), normalize_value($data['launch_date']??''), normalize_value($data['description']??'')]; if(!$payload[0]) throw new RuntimeException('Project Name is required.'); $db=portal_pdo(); if($id){ $payload[]=$id; $db->prepare('UPDATE portal_projects SET project_name=?, short_code=?, status=?, start_date=?, launch_date=?, description=?, updated_at=NOW() WHERE id=?')->execute($payload); } else { $db->prepare('INSERT INTO portal_projects(project_name,short_code,status,start_date,launch_date,description) VALUES(?,?,?,?,?,?)')->execute($payload); $id=(int)$db->lastInsertId(); } $db->prepare('DELETE FROM portal_project_channels WHERE project_id=?')->execute([$id]); foreach($channelIds as $cid){ $cid=(int)$cid; if($cid<=0) continue; $st=$db->prepare('SELECT short_code FROM portal_short_codes WHERE id=?'); $st->execute([$cid]); $row=$st->fetch(); if($row) $db->prepare('INSERT IGNORE INTO portal_project_channels(project_id,channel_id,short_code) VALUES(?,?,?)')->execute([$id,$cid,$row['short_code']]); } audit('save_project','vas_portal','portal_projects',(string)$id,json_encode(['data'=>$data,'channels'=>$channelIds])); }

function make_confirmation(string $action,array $payload): string { $token=bin2hex(random_bytes(24)); portal_pdo()->prepare('INSERT INTO operation_confirmations(token,username,action,payload) VALUES(?,?,?,?)')->execute([$token,user()['username']??'guest',$action,json_encode($payload)]); return $token; }
// The permission needed to carry out a confirmed action, checked again at the moment it runs (a role may have changed since the preview).
function confirmation_permission(string $action): string {
    return ['insert' => 'create_records', 'update' => 'edit_records', 'duplicate' => 'duplicate_records', 'copy_record' => 'copy_records', 'sync_table' => 'copy_records', 'merge_table' => 'copy_records',
        'sql' => 'run_sql', 'save_project' => 'manage_projects', 'save_shortcode' => 'manage_shortcodes'][$action] ?? throw new RuntimeException('Unknown action');
}
function get_confirmation(string $token): ?array { $st=portal_pdo()->prepare('SELECT * FROM operation_confirmations WHERE token=? AND status="pending" AND created_at > (NOW() - INTERVAL 30 MINUTE) LIMIT 1'); $st->execute([$token]); return $st->fetch() ?: null; }
function mark_confirmation(string $token,string $status): void { portal_pdo()->prepare('UPDATE operation_confirmations SET status=?, confirmed_at=NOW() WHERE token=?')->execute([$status,$token]); }

const SQL_READONLY_KINDS = ['SELECT','SHOW','DESCRIBE','EXPLAIN'];
// A "WITH ... AS (...) SELECT ..." (or INSERT/UPDATE) common table expression starts with WITH, not
// its real statement type, so safe_sql_kind() needs to see past the CTE header to classify it
// correctly — a paren-depth scan rather than assuming WITH always means SELECT, since MySQL also
// allows "WITH x AS (...) INSERT/UPDATE ...", which must still go through the write/confirmation path.
function sql_after_cte_header(string $sql): string {
    $s = preg_replace('/^WITH\s+(RECURSIVE\s+)?/i', '', ltrim($sql), 1);
    $len = strlen($s); $i = 0; $depth = 0;
    while ($i < $len) {
        if ($s[$i] === '(') {
            $depth = 1; $i++;
            while ($i < $len && $depth > 0) { if ($s[$i]==='(') $depth++; elseif ($s[$i]===')') $depth--; $i++; }
            while ($i < $len && ctype_space($s[$i])) $i++;
            if ($i < $len && $s[$i] === ',') { $i++; continue; }
            break;
        }
        $i++;
    }
    return ltrim(substr($s, $i));
}
// The console works in the database chosen at the top. It must not read the portal's own database (password hashes, the encryption key),
// the server's account tables, or write into a live database by naming it from another one; and it must not create accounts or stored code.
function sql_assert_in_scope(string $sql, bool $isRead): void {
    $bare = preg_replace(['~/\*.*?\*/~s', '~(--|#)[^\r\n]*~'], ' ', $sql);
    $portal = preg_quote((string)app_config('portal_db'), '/');
    if (preg_match('/`?\b(?:'.$portal.'|mysql)\b`?\s*\.\s*[`\w]/i', $bare)) throw new RuntimeException("The SQL Console cannot read the portal's own database or the server's account tables.");
    if (!$isRead && preg_match('/`?\b(?:'.implode('|', array_map(fn($s) => preg_quote($s, '/'), PROTECTED_SCHEMAS)).')\b`?\s*\.\s*[`\w]/i', $bare)) throw new RuntimeException('Writes cannot name a live database. Switch to that database and use the record forms.');
    if (!$isRead && preg_match('/\b(CREATE|ALTER)\s+(DEFINER\s*=\s*\S+\s+)?(USER|ROLE|SERVER|EVENT|TRIGGER|PROCEDURE|FUNCTION)\b/i', $bare)) throw new RuntimeException('Accounts, events, triggers and stored code cannot be created from the SQL Console.');
}
function safe_sql_kind(string $sql): string {
    $trim=ltrim($sql);
    if (substr_count(rtrim(trim($sql), ';'), ';') > 0) throw new RuntimeException('Only a single statement is allowed per run.');
    $effective = preg_match('/^WITH\s+/i', $trim) ? sql_after_cte_header($trim) : $trim;
    if(!preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN|INSERT|UPDATE|REPLACE|CREATE|ALTER)\b/i',$effective,$m)) throw new RuntimeException('Only SELECT, SHOW, DESCRIBE, EXPLAIN, INSERT, UPDATE, REPLACE, CREATE and ALTER (optionally preceded by a WITH common table expression) are allowed. DELETE, DROP and TRUNCATE are disabled.');
    if(preg_match('/\b(DELETE|DROP|TRUNCATE|GRANT|REVOKE|LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i',$sql)) throw new RuntimeException('Dangerous SQL command blocked. Delete/drop/truncate are disabled in this portal.');
    sql_assert_in_scope($sql, in_array(strtoupper($m[1]), SQL_READONLY_KINDS, true));
    return strtoupper($m[1]);
}
const SQL_CONSOLE_MAX_ROWS = 1000;
const SQL_CONSOLE_CSV_MAX_ROWS = 100000;
const SQL_CONSOLE_TIMEOUT_MS = 30000;
function run_sql(string $schema,string $sql,int $maxRows=SQL_CONSOLE_MAX_ROWS): array {
    $kind=safe_sql_kind($sql);
    // HeraProduction is read-only from the free-form SQL console; production writes must go through the
    // audited, confirmation-gated record forms (insert_record/update_record/copy_record), which are scoped
    // to a single primary key and cannot run an unbounded UPDATE/CREATE/ALTER against live data.
    if (is_protected_schema($schema) && !in_array($kind, SQL_READONLY_KINDS, true)) {
        throw new RuntimeException($schema.' is read-only in the SQL Console. Use the record forms (Add/Edit/Copy) for production writes.');
    }
    $db=pdo($schema);
    if(in_array($kind,SQL_READONLY_KINDS,true)){
        // Read only what will be shown (unbuffered, so the rest is never held in memory) and cap the run time;
        // otherwise SELECT * on audit_log/subscription would exhaust PHP memory or pin the production server.
        try { $db->exec('SET SESSION max_execution_time='.SQL_CONSOLE_TIMEOUT_MS); } catch (Throwable $e) {}
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
        try {
            $st=$db->query($sql); $rows=[]; $truncated=false;
            while (($r=$st->fetch())!==false) { if (count($rows)>=$maxRows) { $truncated=true; break; } $rows[]=$r; }
            $st->closeCursor();
        } finally { $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true); }
        return ['kind'=>$kind,'rows'=>$rows,'affected'=>null,'truncated'=>$truncated,'limit'=>$maxRows];
    }
    $affected=$db->exec($sql); audit('sql_'.$kind,$schema,null,null,$sql); return ['kind'=>$kind,'rows'=>[],'affected'=>$affected];
}

// ===================== Complaint / Transaction Investigation report (audit_log) =====================
// audit_log is a huge table partitioned by RANGE(month(create_date)) with day-of-month subpartitions.
// Every query here still carries a bounded create_date range, but note MySQL does NOT prune partitions
// from a date range on that scheme (month() is not monotonic) — the range is served by an index, so
// the table's size directly affects speed. See Admin > Data Retention (it can run the EXPLAIN to check).
const AUDIT_LOG_TABLE = 'audit_log';
// Real production stores result_status as a numeric code (0 = success; anything else is a failure
// code with the reason in result_description), and vas_offers.status as 1/0 — the demo data used
// 'SUCCESS' and 'active'. Accept both so neither environment reads as "everything failed".
const AUDIT_SUCCESS_SQL = "COALESCE(UPPER(CAST(result_status AS CHAR)) IN ('0','SUCCESS'), 0)";
const OFFER_ACTIVE_SQL = "status IN ('1','active')";
// A cell that starts with = + - @ (or a tab/CR) is run as a formula when the CSV is opened in Excel/Sheets, and these
// exports contain text supplied by subscribers and vendors. Prefix such text with ' so it stays plain text.
function csv_safe_row(array $row): array {
    foreach ($row as $k => $v) if (is_string($v) && $v !== '' && preg_match('/^[=+\-@\t\r]/', $v) && !is_numeric($v)) $row[$k] = "'".$v;
    return $row;
}
function is_success_status($v): bool { return in_array(strtoupper(trim((string)$v)), ['0','SUCCESS'], true); }
function offer_is_active($v): bool { return in_array(strtolower(trim((string)$v)), ['1','active'], true); }
const AUDIT_LOG_MAX_RANGE_DAYS = 31;

function audit_log_filters_from_request(array $q): array {
    $today = date('Y-m-d');
    $from = trim((string)($q['date_from'] ?? '')) ?: $today;
    $to = trim((string)($q['date_to'] ?? '')) ?: $today;
    if (strtotime($from) === false || strtotime($to) === false) throw new RuntimeException('Invalid date.');
    if (strtotime($to) < strtotime($from)) throw new RuntimeException('"Date to" must not be before "date from".');
    $days = (strtotime($to) - strtotime($from)) / 86400;
    if ($days > AUDIT_LOG_MAX_RANGE_DAYS) throw new RuntimeException('Date range too wide (max '.AUDIT_LOG_MAX_RANGE_DAYS.' days) — audit_log is a very large partitioned table; narrow the range.');
    return [
        'date_from' => $from, 'date_to' => $to,
        'msisdn' => trim((string)($q['msisdn'] ?? '')),
        'transaction_id' => trim((string)($q['transaction_id'] ?? '')),
        'result_status' => trim((string)($q['result_status'] ?? '')),
        'vendor' => trim((string)($q['vendor'] ?? '')),
        'channel' => trim((string)($q['channel'] ?? '')),
        'result_desc' => trim((string)($q['result_desc'] ?? '')),
    ];
}

function audit_log_where(array $f, array &$params): string {
    $where = ['create_date BETWEEN ? AND ?'];
    $params[] = $f['date_from'].' 00:00:00'; $params[] = $f['date_to'].' 23:59:59';
    if ($f['msisdn'] !== '') { $where[] = 'msisdn = ?'; $params[] = $f['msisdn']; }
    if ($f['transaction_id'] !== '') { $where[] = 'transaction_id = ?'; $params[] = $f['transaction_id']; }
    if ($f['result_status'] !== '') { $where[] = 'result_status = ?'; $params[] = $f['result_status']; }
    if ($f['vendor'] !== '') { $where[] = 'vendor_entity_name LIKE ?'; $params[] = '%'.$f['vendor'].'%'; }
    // 'USSD' also matches per-session labels such as 'USSD-866195620-42018' (what Monitoring groups under 'USSD')
    if ($f['channel'] !== '') { $where[] = '(channel = ? OR channel LIKE ?)'; $params[] = $f['channel']; $params[] = str_replace(['%', '_'], ['\\%', '\\_'], $f['channel']).'-%'; }
    if ($f['result_desc'] !== '') { $where[] = 'result_description LIKE ?'; $params[] = '%'.$f['result_desc'].'%'; }
    return implode(' AND ', $where);
}

const AUDIT_LOG_SELECT_COLS = 'id, transaction_id, create_date, msisdn, vendor_entity_name, channel, result_status, result_description, response_time, CONVERT(input USING utf8mb4) AS input_text, CONVERT(output USING utf8mb4) AS output_text';

function search_audit_log(string $schema, array $f, int $page, int $perPage): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) throw new RuntimeException(AUDIT_LOG_TABLE.' does not exist in '.$schema);
    $db = pdo($schema);
    $params = []; $where = audit_log_where($f, $params);
    $countSt = $db->prepare('SELECT COUNT(*) c FROM '.ident(AUDIT_LOG_TABLE).' WHERE '.$where);
    $countSt->execute($params); $total = (int)$countSt->fetch()['c'];
    $offset = max(0, ($page-1)*$perPage);
    $q = 'SELECT '.AUDIT_LOG_SELECT_COLS.' FROM '.ident(AUDIT_LOG_TABLE).' WHERE '.$where.' ORDER BY create_date DESC LIMIT '.(int)$perPage.' OFFSET '.(int)$offset;
    $st = $db->prepare($q); $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

// One transaction's full request/response for the Complaint Investigation viewer. audit_log's
// primary key is (id, create_date) and it's partitioned by create_date, so looking a row up by both
// touches a single partition instead of searching the table. Payloads are capped for display; the
// CSV export still has the full text.
const AUDIT_DETAIL_MAX_CHARS = 500000;
function audit_log_detail(string $schema, int $id, string $createDate): ?array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) throw new RuntimeException(AUDIT_LOG_TABLE.' does not exist in '.$schema);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createDate)) throw new RuntimeException('Invalid transaction date.');
    // Raw bytes, decoded here: MySQL's CONVERT(... USING utf8mb4) returns nothing at all for a
    // payload with an invalid byte, which would show as an empty request — misleading in an
    // investigation. Invalid bytes are shown as '?' instead, with a flag so the page can say so.
    $st = pdo($schema)->prepare('SELECT id, transaction_id, create_date, msisdn, vendor_entity_name, channel, result_status, result_description, response_time, input AS input_raw, output AS output_raw FROM '.ident(AUDIT_LOG_TABLE).' WHERE id=? AND create_date=? LIMIT 1');
    $st->execute([$id, $createDate]);
    $r = $st->fetch();
    if (!$r) return null;
    $decode = function ($v) {
        $v = (string)$v;
        $bad = !mb_check_encoding($v, 'UTF-8');
        if ($bad) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        $cut = strlen($v) > AUDIT_DETAIL_MAX_CHARS;
        if ($cut) $v = mb_strcut($v, 0, AUDIT_DETAIL_MAX_CHARS, 'UTF-8');
        return [$v, $cut, $bad];
    };
    [$in, $inCut, $inBad] = $decode($r['input_raw']); [$out, $outCut, $outBad] = $decode($r['output_raw']);
    return ['id' => (int)$r['id'], 'transaction_id' => $r['transaction_id'], 'create_date' => $r['create_date'], 'msisdn' => $r['msisdn'],
        'vendor' => $r['vendor_entity_name'], 'channel' => $r['channel'], 'result_status' => (string)$r['result_status'],
        'result_description' => $r['result_description'], 'response_time' => $r['response_time'] === null ? null : (int)$r['response_time'],
        'success' => is_success_status($r['result_status']), 'input' => $in, 'output' => $out, 'input_truncated' => $inCut, 'output_truncated' => $outCut, 'input_binary' => $inBad, 'output_binary' => $outBad];
}
// ===================== Complaint Investigation: choose where to look =====================
// audit_log holds the full request/response but is trimmed over time to free disk; the subscription
// table keeps the outcome of every subscription attempt for longer. Both can answer "what happened to
// this customer's purchase", so the page lets you pick — and a complaint about a date audit_log no
// longer covers can still be investigated from subscription.
const INVESTIGATE_SOURCES = [
    'audit_log' => 'audit_log — full request and response',
    'subscription' => 'subscription — subscription attempts and results',
];
// subscription is ~100M+ rows and a filter like MSISDN can't narrow it beyond the date range (only
// the date index helps), so the span is kept much shorter than audit_log's.
const SUBSCRIPTION_LOG_MAX_RANGE_DAYS = 7;
const SUBSCRIPTION_LOG_COLS = 'id, date, purchase_sequence, subscriber_msisdn, receiver_msisdn, transaction_id, subscription_type, channel, result_desc, data_volume, sms_volume, minutes_volume, data_expiry, sms_expiry, minutes_expiry';

function investigate_sources(string $schema): array {
    return array_filter(INVESTIGATE_SOURCES, fn($k) => table_exists($schema, $k), ARRAY_FILTER_USE_KEY);
}
function investigate_source(array $q, string $schema): string {
    $want = (string)($q['source'] ?? 'audit_log');
    return isset(investigate_sources($schema)[$want]) ? $want : 'audit_log';
}
function is_subscription_success($desc): bool {
    return (bool)preg_match('/^\s*(operation succe|success)/i', (string)$desc);
}
// Tabs to switch source. Carries over the fields both sources share, and clamps the date range to what
// the target allows, so switching never lands on a "date range too wide" error.
function investigate_source_tabs(array $sources, string $current, array $get): string {
    if (count($sources) < 2) return '';
    $carry = [];
    foreach (['msisdn', 'transaction_id', 'channel', 'result_desc'] as $k) if (trim((string)($get[$k] ?? '')) !== '') $carry[$k] = trim((string)$get[$k]);
    $to = strtotime((string)($get['date_to'] ?? '')) ?: strtotime('today');
    $from = strtotime((string)($get['date_from'] ?? '')) ?: $to;
    $desc = ['audit_log' => 'Full request &amp; response', 'subscription' => 'Subscription attempts &amp; results'];
    $icon = ['audit_log' => 'fa-file-lines', 'subscription' => 'fa-user-check'];
    $html = '<div class="source-tabs">';
    foreach ($sources as $key => $label) {
        $max = $key === 'subscription' ? SUBSCRIPTION_LOG_MAX_RANGE_DAYS : AUDIT_LOG_MAX_RANGE_DAYS;
        $q = array_merge(['page' => 'investigate', 'source' => $key, 'date_from' => date('Y-m-d', max($from, $to - ($max - 1) * 86400)), 'date_to' => date('Y-m-d', $to)], $carry);
        $name = explode(' — ', (string)$label)[0];
        $html .= '<a class="source-tab'.($key === $current ? ' active' : '').'" href="?'.e(http_build_query($q)).'"><i class="fa-solid '.($icon[$key] ?? 'fa-database').'"></i><span><strong>'.e($name).'</strong><small>'.($desc[$key] ?? '').'</small></span></a>';
    }
    return $html.'</div>';
}

function subscription_log_filters_from_request(array $q): array {
    $today = date('Y-m-d');
    $from = trim((string)($q['date_from'] ?? '')) ?: $today;
    $to = trim((string)($q['date_to'] ?? '')) ?: $today;
    if (strtotime($from) === false || strtotime($to) === false) throw new RuntimeException('Invalid date.');
    if (strtotime($to) < strtotime($from)) throw new RuntimeException('"Date to" must not be before "date from".');
    if ((strtotime($to) - strtotime($from)) / 86400 > SUBSCRIPTION_LOG_MAX_RANGE_DAYS) throw new RuntimeException('Date range too wide for subscription (max '.SUBSCRIPTION_LOG_MAX_RANGE_DAYS.' days) — it is a very large table; narrow the range, or use audit_log for a longer span.');
    $msisdn = trim((string)($q['msisdn'] ?? ''));
    if ($msisdn !== '') {
        $msisdn = preg_replace('/\D+/', '', $msisdn);
        if ($msisdn === '' || strlen($msisdn) > 15) throw new RuntimeException('MSISDN must be digits only.');
    }
    return [
        'date_from' => $from, 'date_to' => $to, 'msisdn' => $msisdn,
        'transaction_id' => trim((string)($q['transaction_id'] ?? '')),
        'channel' => trim((string)($q['channel'] ?? '')),
        'subscription_type' => trim((string)($q['subscription_type'] ?? '')),
        'result_desc' => trim((string)($q['result_desc'] ?? '')),
    ];
}
function subscription_log_where(array $f, array &$params): string {
    $where = ['date >= ? AND date < ?'];
    $params[] = $f['date_from'].' 00:00:00'; $params[] = date('Y-m-d', strtotime($f['date_to'].' +1 day')).' 00:00:00';
    if ($f['msisdn'] !== '') { $where[] = '(subscriber_msisdn = ? OR receiver_msisdn = ?)'; $params[] = $f['msisdn']; $params[] = $f['msisdn']; }
    if ($f['transaction_id'] !== '') { $where[] = 'transaction_id = ?'; $params[] = $f['transaction_id']; }
    if ($f['channel'] !== '') { $where[] = 'channel = ?'; $params[] = $f['channel']; }
    if ($f['subscription_type'] !== '') { $where[] = 'subscription_type = ?'; $params[] = $f['subscription_type']; }
    if ($f['result_desc'] !== '') { $where[] = 'result_desc LIKE ?'; $params[] = '%'.$f['result_desc'].'%'; }
    return implode(' AND ', $where);
}
function search_subscription_log(string $schema, array $f, int $page, int $perPage): array {
    if (!table_exists($schema, 'subscription')) throw new RuntimeException('subscription does not exist in '.$schema);
    $db = pdo($schema);
    $params = []; $where = subscription_log_where($f, $params);
    $c = $db->prepare('SELECT COUNT(*) c FROM subscription WHERE '.$where); $c->execute($params);
    $total = (int)$c->fetch()['c'];
    $offset = max(0, ($page - 1) * $perPage);
    $st = $db->prepare('SELECT '.SUBSCRIPTION_LOG_COLS.' FROM subscription WHERE '.$where.' ORDER BY date DESC, id DESC LIMIT '.(int)$perPage.' OFFSET '.(int)$offset);
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}
function export_subscription_log(string $schema, array $f, int $limit = 20000): array {
    $params = []; $where = subscription_log_where($f, $params);
    $st = pdo($schema)->prepare('SELECT '.SUBSCRIPTION_LOG_COLS.' FROM subscription WHERE '.$where.' ORDER BY date DESC, id DESC LIMIT '.(int)$limit);
    $st->execute($params);
    return $st->fetchAll();
}
// Same JSON shape as audit_log_detail() so the viewer window works for both; `labels` renames its panes.
function subscription_log_detail(string $schema, int $id): ?array {
    if (!table_exists($schema, 'subscription')) throw new RuntimeException('subscription does not exist in '.$schema);
    $st = pdo($schema)->prepare('SELECT '.SUBSCRIPTION_LOG_COLS.' FROM subscription WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $lines = [];
    foreach ($r as $k => $v) $lines[] = str_pad($k, 18).' '.($v === null ? '' : $v);
    $ok = is_subscription_success($r['result_desc']);
    return ['id' => (int)$r['id'], 'transaction_id' => $r['transaction_id'], 'create_date' => $r['date'], 'msisdn' => $r['subscriber_msisdn'],
        'vendor' => null, 'channel' => $r['channel'], 'result_status' => $ok ? 'Success' : 'Failed', 'result_description' => $r['result_desc'],
        'response_time' => null, 'success' => $ok, 'input' => implode("\n", $lines), 'output' => (string)$r['result_desc'],
        'input_truncated' => false, 'output_truncated' => false, 'input_binary' => false, 'output_binary' => false,
        'labels' => ['input' => 'Subscription record', 'output' => 'Result']];
}

function export_audit_log(string $schema, array $f, int $limit = 20000): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) throw new RuntimeException(AUDIT_LOG_TABLE.' does not exist in '.$schema);
    $params = []; $where = audit_log_where($f, $params);
    $q = 'SELECT '.AUDIT_LOG_SELECT_COLS.' FROM '.ident(AUDIT_LOG_TABLE).' WHERE '.$where.' ORDER BY create_date DESC LIMIT '.(int)$limit;
    $st = pdo($schema)->prepare($q); $st->execute($params);
    return $st->fetchAll();
}

// ===================== Subscriptions search =====================
// subscription has ~89M rows and NO index beyond the primary key (id) — not even on
// subscriber_msisdn or date. An unrestricted browse or date-only range would force a full table
// scan, so a search here always requires an exact MSISDN or Transaction ID.
// A real fix needs a DBA-run `CREATE INDEX ... ON subscription (subscriber_msisdn, date)` —
// see database/migrations/2026-09-17_MANUAL_add_subscription_index.sql.
function subscription_filters_from_request(array $q): array {
    $msisdn = trim((string)($q['msisdn'] ?? ''));
    $txn = trim((string)($q['transaction_id'] ?? ''));
    if ($msisdn === '' && $txn === '') throw new RuntimeException('Enter an MSISDN or Transaction ID — subscription has no supporting index, so an unrestricted search would scan ~89M rows.');
    return [
        'msisdn' => $msisdn,
        'transaction_id' => $txn,
        'subscription_type' => trim((string)($q['subscription_type'] ?? '')),
        'channel' => trim((string)($q['channel'] ?? '')),
        'date_from' => trim((string)($q['date_from'] ?? '')),
        'date_to' => trim((string)($q['date_to'] ?? '')),
    ];
}
function subscription_where(array $f, array &$params): string {
    $where = [];
    if ($f['msisdn'] !== '') { $where[] = 'subscriber_msisdn = ?'; $params[] = $f['msisdn']; }
    if ($f['transaction_id'] !== '') { $where[] = 'transaction_id = ?'; $params[] = $f['transaction_id']; }
    if ($f['subscription_type'] !== '') { $where[] = 'subscription_type = ?'; $params[] = $f['subscription_type']; }
    if ($f['channel'] !== '') { $where[] = 'channel = ?'; $params[] = $f['channel']; }
    if ($f['date_from'] !== '') { $where[] = 'date >= ?'; $params[] = $f['date_from'].' 00:00:00'; }
    if ($f['date_to'] !== '') { $where[] = 'date <= ?'; $params[] = $f['date_to'].' 23:59:59'; }
    return implode(' AND ', $where);
}
function search_subscriptions(string $schema, array $f, int $page, int $perPage): array {
    $params = []; $where = subscription_where($f, $params);
    $db = pdo($schema);
    $count = $db->prepare('SELECT COUNT(*) c FROM subscription WHERE '.$where); $count->execute($params);
    $total = (int)$count->fetch()['c'];
    $offset = max(0, ($page-1)*$perPage);
    $st = $db->prepare('SELECT * FROM subscription WHERE '.$where.' ORDER BY date DESC LIMIT '.(int)$perPage.' OFFSET '.(int)$offset);
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}
function export_subscriptions(string $schema, array $f, int $limit = 10000): array {
    $params = []; $where = subscription_where($f, $params);
    $st = pdo($schema)->prepare('SELECT * FROM subscription WHERE '.$where.' ORDER BY date DESC LIMIT '.(int)$limit);
    $st->execute($params);
    return $st->fetchAll();
}
function subscription_status(array $row): array {
    $now = time();
    $check = function ($expiry) use ($now) {
        if ($expiry === null || $expiry === '') return 'n/a';
        $ts = strtotime((string)$expiry);
        if ($ts === false) return 'unknown';
        return $ts >= $now ? 'active' : 'expired';
    };
    return ['data' => $check($row['data_expiry'] ?? null), 'sms' => $check($row['sms_expiry'] ?? null), 'minutes' => $check($row['minutes_expiry'] ?? null)];
}

// ===================== Promotions + Promotion Performance report =====================
// A promotion is just a named group of offer codes (portal governance data, vas_portal, never Hera).
// The report below joins that group against subscription — same shape as the "Buy for Other" report
// operators were hand-writing in the SQL Console, but generated, and offer_code_for_other is read
// live from vas_offers instead of being duplicated into the promotion.
const PROMOTION_REPORT_MAX_RANGE_DAYS = 31;
const REPORT_TIMEOUT_SECONDS = 240;

function list_promotions(string $schema): array {
    $st = portal_pdo()->prepare('SELECT id,name FROM promotions WHERE schema_name=? ORDER BY name');
    $st->execute([$schema]);
    return $st->fetchAll();
}
function get_promotion(int $id): ?array {
    $st = portal_pdo()->prepare('SELECT * FROM promotions WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    $st2 = portal_pdo()->prepare('SELECT offer_code FROM promotion_offers WHERE promotion_id=? ORDER BY offer_code');
    $st2->execute([$id]);
    $row['offer_codes'] = array_column($st2->fetchAll(), 'offer_code');
    return $row;
}
function save_promotion(string $schema, string $name, array $offerCodes, ?int $id = null): int {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Promotion name is required.');
    $offerCodes = array_values(array_unique(array_filter(array_map('trim', $offerCodes), fn($c) => $c !== '')));
    if (!$offerCodes) throw new RuntimeException('Select at least one offer code.');
    $db = portal_pdo();
    $db->beginTransaction();
    try {
        if ($id) {
            $db->prepare('UPDATE promotions SET name=? WHERE id=?')->execute([$name, $id]);
            $db->prepare('DELETE FROM promotion_offers WHERE promotion_id=?')->execute([$id]);
        } else {
            $db->prepare('INSERT INTO promotions(name,schema_name,created_by) VALUES(?,?,?)')->execute([$name, $schema, user()['username'] ?? null]);
            $id = (int)$db->lastInsertId();
        }
        $ins = $db->prepare('INSERT INTO promotion_offers(promotion_id,offer_code) VALUES(?,?)');
        foreach ($offerCodes as $code) $ins->execute([$id, $code]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit('save_promotion', $schema, 'promotions', (string)$id, $name.': '.implode(',', $offerCodes));
    return $id;
}

// $offerCodes empty means "every offer" — a saved Promotion is just one convenient way to fill this
// list, never required; the report works for a single offer, an ad-hoc selection, or everything.
function offer_performance_report(string $schema, array $offerCodes, string $channel, string $dateFrom, string $dateTo): array {
    if (strtotime($dateFrom) === false || strtotime($dateTo) === false) throw new RuntimeException('Invalid date.');
    if (strtotime($dateTo) < strtotime($dateFrom)) throw new RuntimeException('"Date to" must not be before "date from".');
    if ((strtotime($dateTo) - strtotime($dateFrom)) / 86400 > PROMOTION_REPORT_MAX_RANGE_DAYS) throw new RuntimeException('Date range cannot exceed '.PROMOTION_REPORT_MAX_RANGE_DAYS.' days — subscription has no supporting index, so a wider scan would be very slow.');
    if (!table_exists($schema, 'subscription') || !table_exists($schema, 'vas_offers')) return [];
    $offerCodes = array_values(array_unique(array_filter(array_map('trim', $offerCodes), fn($c) => $c !== '')));
    $db = pdo($schema);

    $directWhere = ['1=1']; $directParams = [];
    $otherWhere = ["offer_code_for_other IS NOT NULL", "offer_code_for_other != ''"]; $otherParams = [];
    if ($offerCodes) {
        $ph = implode(',', array_fill(0, count($offerCodes), '?'));
        $directWhere[] = "offer_code IN ($ph)"; $directParams = $offerCodes;
        $otherWhere[] = "offer_code IN ($ph)"; $otherParams = $offerCodes;
    }
    // txn_offer_suffix is a generated column + index added manually as a DBA operation (see
    // docs/ARCHITECTURE.md or the deploy history) — use it when present for an indexed join instead
    // of computing RIGHT(transaction_id,5) per row; fall back gracefully where it hasn't been added
    // yet (local dev, HeraTesting) rather than hard-requiring it everywhere.
    $txnSuffixExpr = column_exists($schema, 'subscription', 'txn_offer_suffix') ? 's.txn_offer_suffix' : 'RIGHT(s.transaction_id, 5)';

    // Offer codes are not unique in vas_offers (several rows can share one code), so offers are de-duplicated
    // above: joining raw rows multiplied every attempt by the number of rows sharing the code.
    // For a short range, drive the query from the date index (one or two days of rows) instead of letting MySQL
    // walk every row an offer has ever had through the suffix index and check the date afterwards.
    $indexHint = '';
    if (strtotime($dateTo) - strtotime($dateFrom) <= 3 * 86400 && !index_exists($schema, 'subscription', 'idx_subscription_suffix_date') && index_exists($schema, 'subscription', 'idx_subscription_date_channel')) {
        $indexHint = 'FORCE INDEX (idx_subscription_date_channel)';
    }
    $sql = "SELECT DATE(s.date) AS ReportDate, s.channel AS Channel, m.base_offer_code AS OfferCode, m.offer_code_used AS TransactionOfferCode, m.purchase_type AS PurchaseType, v.name AS OfferName,
        CASE WHEN s.result_desc = 'Operation successfully.' THEN 'Successful' ELSE 'Unsuccessful' END AS ResultStatus,
        CASE WHEN s.result_desc = 'Operation successfully.' THEN 'N/A' WHEN s.result_desc IS NULL OR TRIM(s.result_desc) = '' THEN 'Unknown failure reason' ELSE s.result_desc END AS FailureReason,
        COUNT(*) AS TotalAttempts,
        SUM(CASE WHEN s.result_desc = 'Operation successfully.' THEN 1 ELSE 0 END) AS SuccessfulAttempts,
        SUM(CASE WHEN s.result_desc = 'Operation successfully.' THEN 0 ELSE 1 END) AS UnsuccessfulAttempts,
        COUNT(DISTINCT s.subscriber_msisdn) AS TotalDistinctUsers
        FROM subscription s $indexHint
        INNER JOIN (
            SELECT DISTINCT offer_code AS offer_code_used, offer_code AS base_offer_code, 'Direct' AS purchase_type FROM vas_offers WHERE ".implode(' AND ',$directWhere)."
            UNION
            SELECT DISTINCT offer_code_for_other, offer_code, 'Buy for Other' FROM vas_offers WHERE ".implode(' AND ',$otherWhere)."
        ) m ON $txnSuffixExpr = m.offer_code_used
        INNER JOIN (
            SELECT offer_code, SUBSTRING_INDEX(GROUP_CONCAT(name ORDER BY (status IN ('1','active')) DESC, id DESC SEPARATOR '||'), '||', 1) AS name FROM vas_offers GROUP BY offer_code
        ) v ON m.base_offer_code = v.offer_code
        WHERE s.date >= ? AND s.date < ?";
    $params = array_merge($directParams, $otherParams, [$dateFrom.' 00:00:00', date('Y-m-d', strtotime($dateTo.' +1 day')).' 00:00:00']);
    if ($channel !== '') { $sql .= ' AND s.channel = ?'; $params[] = $channel; }
    $sql .= " GROUP BY ReportDate, Channel, OfferCode, TransactionOfferCode, PurchaseType, OfferName, s.result_desc ORDER BY ReportDate, OfferName, PurchaseType, ResultStatus, FailureReason";
    // This aggregates every subscription row in the range, so a busy day can outlast the ingress timeout.
    // Give it a bounded budget (matched by proxy-read-timeout in deploy/k8s/04-ingress.yaml) and turn a
    // MySQL "query execution was interrupted" into an actionable message instead of a bare 504.
    @set_time_limit(REPORT_TIMEOUT_SECONDS + 20);
    try { $db->exec('SET SESSION max_execution_time='.(REPORT_TIMEOUT_SECONDS * 1000)); } catch (Throwable $e) {}
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'max_execution_time') || str_contains($e->getMessage(), 'interrupted')) {
            throw new RuntimeException('The report took longer than '.REPORT_TIMEOUT_SECONDS.'s and was stopped. Narrow it: pick one channel or specific offers, or use a shorter time range.');
        }
        throw $e;
    }
}

// ===================== Operational dashboard + alerts =====================
// All of these are bounded to short, recent create_date windows so they only ever touch one or two
// audit_log partitions, never a full-table scan of a 200M+ row table.

function dashboard_kpis(...$a) { return cached('dashboard_kpis:'.md5(serialize($a)), 60, fn() => dashboard_kpis_uncached(...$a)); }
function dashboard_kpis_uncached(string $schema): array {
    $out = ['tx_today'=>0, 'tx_today_success'=>0, 'tx_today_failed'=>0, 'lookups_today'=>0, 'lookups_failed'=>0, 'offers_active'=>0, 'offers_inactive'=>0, 'subscription_rows_est'=>0];
    if (table_exists($schema, AUDIT_LOG_TABLE)) {
        $db = pdo($schema);
        $st = $db->query("SELECT COUNT(*) total, SUM(CASE WHEN ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) ok, SUM(CASE WHEN ".LOOKUPS_SQL." THEN 1 ELSE 0 END) lk, SUM(CASE WHEN ".LOOKUPS_SQL." AND ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) lk_ok FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= CURDATE()");
        $r = $st->fetch();
        $out['lookups_today'] = (int)($r['lk'] ?? 0); $out['lookups_failed'] = (int)($r['lk'] ?? 0) - (int)($r['lk_ok'] ?? 0);
        $out['tx_today'] = (int)($r['total'] ?? 0) - $out['lookups_today'];
        $out['tx_today_success'] = (int)($r['ok'] ?? 0) - (int)($r['lk_ok'] ?? 0);
        $out['tx_today_failed'] = $out['tx_today'] - $out['tx_today_success'];
    }
    if (table_exists($schema, 'vas_offers')) {
        $st = pdo($schema)->query("SELECT SUM(".OFFER_ACTIVE_SQL.") active, SUM(NOT COALESCE(".OFFER_ACTIVE_SQL.",0)) inactive FROM ".ident('vas_offers'));
        $r = $st->fetch();
        $out['offers_active'] = (int)($r['active'] ?? 0);
        $out['offers_inactive'] = (int)($r['inactive'] ?? 0);
    }
    if (table_exists($schema, 'subscription')) $out['subscription_rows_est'] = approx_table_count($schema, 'subscription');
    return $out;
}

function top_vendors_today(...$a) { return cached('top_vendors_today:'.md5(serialize($a)), 60, fn() => top_vendors_today_uncached(...$a)); }
function top_vendors_today_uncached(string $schema, int $limit = 6): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return [];
    $st = pdo($schema)->prepare("SELECT vendor_entity_name, COUNT(*) total, SUM(CASE WHEN NOT ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) failed FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= CURDATE() GROUP BY vendor_entity_name ORDER BY total DESC LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT); $st->execute();
    return $st->fetchAll();
}

// Hourly buckets over the last $hours (bounded, same one-or-two-partition footprint as the rest of
// this section) for the trend charts on the Dashboard and Alerts pages.
function hourly_transaction_trend(...$a) { return cached('hourly_transaction_trend:'.md5(serialize($a)), 60, fn() => hourly_transaction_trend_uncached(...$a)); }
function hourly_transaction_trend_uncached(string $schema, int $hours = 24): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return [];
    $hours = max(1, min(168, $hours));
    [$cf, $params] = alert_counted_failure_sql();
    $st = pdo($schema)->prepare("SELECT DATE_FORMAT(create_date, '%Y-%m-%d %H:00') hr, COUNT(*) total, SUM(CASE WHEN NOT ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) failed, SUM(CASE WHEN $cf THEN 1 ELSE 0 END) counted_failed FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL $hours HOUR AND NOT ".LOOKUPS_SQL." GROUP BY hr ORDER BY hr");
    $st->execute($params);
    return $st->fetchAll();
}

function recent_activity(int $limit = 10): array {
    $st = portal_pdo()->prepare('SELECT * FROM portal_audit_trail ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT); $st->execute();
    return $st->fetchAll();
}

function integrations_health_summary(string $schema): array {
    $out = ['active'=>0, 'inactive'=>0, 'testing'=>0, 'last_check_ok'=>0, 'last_check_failed'=>0, 'never_checked'=>0];
    if (!table_exists($schema, 'integrations')) return $out;
    $rows = pdo($schema)->query('SELECT status, last_check_ok FROM integrations')->fetchAll();
    foreach ($rows as $r) {
        $out[$r['status']] = ($out[$r['status']] ?? 0) + 1;
        if ($r['last_check_ok'] === null) $out['never_checked']++;
        elseif ((int)$r['last_check_ok'] === 1) $out['last_check_ok']++;
        else $out['last_check_failed']++;
    }
    return $out;
}

function recent_alert_history(string $schema, int $limit = 15): array {
    $st = portal_pdo()->prepare("SELECT * FROM portal_audit_trail WHERE action='alert_fired' AND schema_name=? ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $schema); $st->bindValue(2, $limit, PDO::PARAM_INT); $st->execute();
    return $st->fetchAll();
}

const ALERT_CONFIG_DEFAULTS = ['failure_rate_enabled' => 1, 'failure_rate_pct' => 20, 'failure_min_sample' => 20, 'vendor_silent_enabled' => 1, 'vendor_silent_min_baseline' => 5,
    'vendor_slow_enabled' => 0, 'vendor_slow_ms' => 3000, 'vendor_slow_min_sample' => 20, 'summary_enabled' => 0, 'summary_hour' => 7, 'retention_months' => 3];
function alert_config(): array {
    $cfg = ALERT_CONFIG_DEFAULTS;
    try { foreach (portal_pdo()->query('SELECT name,value FROM alert_config')->fetchAll() as $r) if (isset($cfg[$r['name']])) $cfg[$r['name']] = (int)$r['value']; }
    catch (Throwable $e) {}
    return $cfg;
}
function save_alert_config(array $d): void {
    $int = function (string $k, int $min, int $max, string $label) use ($d): int {
        $v = filter_var($d[$k] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max) throw new RuntimeException("$label must be a whole number between $min and $max.");
        return $v;
    };
    $vals = [
        'failure_rate_enabled' => empty($d['failure_rate_enabled']) ? 0 : 1,
        'failure_rate_pct' => $int('failure_rate_pct', 1, 100, 'Failure rate %'),
        'failure_min_sample' => $int('failure_min_sample', 1, 1000000, 'Minimum transactions'),
        'vendor_silent_enabled' => empty($d['vendor_silent_enabled']) ? 0 : 1,
        'vendor_silent_min_baseline' => $int('vendor_silent_min_baseline', 1, 1000000, 'Vendor baseline'),
        'vendor_slow_enabled' => empty($d['vendor_slow_enabled']) ? 0 : 1,
        'vendor_slow_ms' => $int('vendor_slow_ms', 50, 600000, 'Slow limit (ms)'),
        'vendor_slow_min_sample' => $int('vendor_slow_min_sample', 1, 1000000, 'Slow-vendor minimum transactions'),
    ];
    $st = portal_pdo()->prepare('INSERT INTO alert_config(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
    foreach ($vals as $k => $v) $st->execute([$k, (string)$v]);
    audit('alert_config_save', null, 'alert_config', null, json_encode($vals));
    cache_clear();
}
function save_summary_config(array $d): void {
    $hour = filter_var($d['summary_hour'] ?? null, FILTER_VALIDATE_INT);
    if ($hour === false || $hour < 0 || $hour > 23) throw new RuntimeException('Send hour must be between 0 and 23.');
    $vals = ['summary_enabled' => empty($d['summary_enabled']) ? 0 : 1, 'summary_hour' => $hour];
    $st = portal_pdo()->prepare('INSERT INTO alert_config(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
    foreach ($vals as $k => $v) $st->execute([$k, (string)$v]);
    audit('summary_config_save', null, 'alert_config', null, json_encode($vals));
}
function alert_ignored_reasons(): array {
    try { return array_column(portal_pdo()->query('SELECT reason FROM alert_ignored_reasons ORDER BY reason')->fetchAll(), 'reason'); }
    catch (Throwable $e) { return []; }
}
function save_alert_ignored_reasons(array $reasons): void {
    $reasons = array_values(array_unique(array_filter(array_map(fn($r) => trim((string)$r), $reasons), fn($r) => $r !== '' && mb_strlen($r) <= 500)));
    $db = portal_pdo();
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM alert_ignored_reasons');
        $ins = $db->prepare('INSERT INTO alert_ignored_reasons(reason) VALUES(?)');
        foreach ($reasons as $r) $ins->execute([$r]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit('alert_ignored_save', null, 'alert_ignored_reasons', null, count($reasons).' reason(s) ignored');
    cache_clear();
}

// SQL condition (+ bind params) for a failure that counts toward the failure-rate alert: not a
// success, and not one of the failure reasons the admin chose to ignore. Shared by the alert itself,
// the trend chart and the summary tiles so they can never disagree about what "counted" means.
function alert_counted_failure_sql(): array {
    $ignored = alert_ignored_reasons();
    $sql = '(NOT '.AUDIT_SUCCESS_SQL.')'; $params = [];
    if ($ignored) { $sql .= " AND COALESCE(result_description,'') NOT IN (".implode(',', array_fill(0, count($ignored), '?')).")"; $params = $ignored; }
    return [$sql, $params, $ignored];
}
function alert_window_stats(...$a) { return cached('alert_window_stats:'.md5(serialize($a)), 60, fn() => alert_window_stats_uncached(...$a)); }
function alert_window_stats_uncached(string $schema, int $hours = 1): array {
    $out = ['total' => 0, 'failed' => 0, 'counted' => 0];
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return $out;
    $hours = max(1, min(168, $hours));
    [$cf, $params] = alert_counted_failure_sql();
    $st = pdo($schema)->prepare("SELECT COUNT(*) total, SUM(CASE WHEN NOT ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) failed, SUM(CASE WHEN $cf THEN 1 ELSE 0 END) counted FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL $hours HOUR AND NOT ".LOOKUPS_SQL);
    $st->execute($params);
    $r = $st->fetch();
    return ['total' => (int)($r['total'] ?? 0), 'failed' => (int)($r['failed'] ?? 0), 'counted' => (int)($r['counted'] ?? 0)];
}

function compute_alerts(...$a) { return cached('compute_alerts:'.md5(serialize($a)), 60, fn() => compute_alerts_uncached(...$a)); }
function compute_alerts_uncached(string $schema): array {
    $alerts = [];
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return $alerts;
    $db = pdo($schema);
    $cfg = alert_config();

    if ($cfg['failure_rate_enabled']) {
        // Reasons the admin chose to ignore (customer-side failures such as insufficient balance)
        // still count in the total but not as failures, so they can't trip the alert on their own.
        [$cf, $params, $ignored] = alert_counted_failure_sql();
        // Customer traffic only: Hera's own background calls to its vendors (no channel, ~70k/day, nearly all
        // successful) are left out so they can't dilute the failure rate.
        $st = $db->prepare("SELECT COUNT(*) total, SUM(CASE WHEN $cf THEN 1 ELSE 0 END) failed FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL 1 HOUR AND NOT ".LOOKUPS_SQL);
        $st->execute($params);
        $r = $st->fetch(); $total = (int)($r['total'] ?? 0); $failed = (int)($r['failed'] ?? 0);
        if ($total >= $cfg['failure_min_sample']) {
            $rate = $failed / $total;
            if ($rate * 100 > $cfg['failure_rate_pct']) {
                $alerts[] = ['key'=>'high_failure_rate:'.$schema, 'type'=>'failure_rate', 'level'=>'danger', 'message'=>sprintf('High failure rate in the last hour: %d of %d customer transactions failed (%.0f%%).%s', $failed, $total, $rate*100, $ignored ? ' Failure reasons you chose to ignore are not counted.' : '')];
            }
        }
    }

    if ($cfg['vendor_silent_enabled']) {
        $st = $db->prepare("SELECT vendor_entity_name, COUNT(*) c FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL 1 HOUR - INTERVAL 1 DAY AND create_date < NOW() - INTERVAL 1 DAY GROUP BY vendor_entity_name HAVING c >= ?");
        $st->execute([$cfg['vendor_silent_min_baseline']]);
        $baseline = array_column($st->fetchAll(), 'c', 'vendor_entity_name');
        if ($baseline) {
            $st = $db->query("SELECT DISTINCT vendor_entity_name FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL 1 HOUR");
            $activeNow = array_column($st->fetchAll(), 'vendor_entity_name');
            foreach ($baseline as $vendor => $count) {
                if (!in_array($vendor, $activeNow, true)) {
                    $alerts[] = ['key'=>'vendor_silent:'.$schema.':'.$vendor, 'type'=>'vendor_silent', 'level'=>'warning', 'message'=>sprintf('%s sent %d transactions in this hour yesterday but none in the last hour — may be down.', $vendor, $count)];
                }
            }
        }
    }
    if ($cfg['vendor_slow_enabled']) {
        // response_time is milliseconds. Judged on the last hour, per vendor, with a minimum sample so
        // one slow call on a quiet vendor can't trigger it.
        $st = $db->prepare("SELECT COALESCE(NULLIF(vendor_entity_name,''),'(none)') v, COUNT(*) c, AVG(response_time) a FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL 1 HOUR AND response_time IS NOT NULL GROUP BY 1 HAVING c >= ? AND a > ?");
        $st->execute([$cfg['vendor_slow_min_sample'], $cfg['vendor_slow_ms']]);
        foreach ($st->fetchAll() as $r) {
            $alerts[] = ['key'=>'vendor_slow:'.$schema.':'.$r['v'], 'type'=>'vendor_slow', 'level'=>'warning', 'message'=>sprintf('%s is slow: averaged %s ms over the last hour (%d transactions) — your limit is %s ms.', $r['v'], number_format((float)$r['a']), $r['c'], number_format($cfg['vendor_slow_ms']))];
        }
    }
    return $alerts;
}

const ALERT_RENOTIFY_MINUTES = 30;

function alert_cron_token(): string {
    static $token = null;
    if ($token !== null) return $token;
    $st = portal_pdo()->prepare('SELECT value FROM app_secrets WHERE name=?');
    $st->execute(['alert_cron_token']);
    $row = $st->fetch();
    if (!$row) {
        portal_pdo()->prepare('INSERT IGNORE INTO app_secrets(name,value) VALUES(?,?)')->execute(['alert_cron_token', bin2hex(random_bytes(24))]);
        $st->execute(['alert_cron_token']); $row = $st->fetch();
    }
    $token = $row['value'];
    return $token;
}

function send_slack_alert(string $schema, string $message): void {
    if (!table_exists($schema, 'integrations')) return;
    $st = pdo($schema)->prepare("SELECT base_url FROM integrations WHERE service_type='monitoring' AND status='active' AND base_url IS NOT NULL AND base_url != ''");
    $st->execute();
    foreach ($st->fetchAll() as $row) {
        $ch = curl_init($row['base_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['text' => $message]),
        ]);
        curl_exec($ch); curl_close($ch);
    }
}

// Email alerts. The container has no mail transport (PHP's mail() has nothing to hand off to), so
// this speaks SMTP directly. The mail server is configured on the Alerts page (password encrypted
// at rest); SMTP_HOST / SMTP_PORT / SMTP_SECURE (tls = STARTTLS, ssl = implicit TLS, none) /
// SMTP_USER / SMTP_PASSWORD / SMTP_FROM / SMTP_TLS_VERIFY=0 environment variables still work as a
// fallback when nothing is saved there. Recipients live in alert_recipients (plus optional
// ALERT_EMAIL_TO).
function alert_recipients(): array {
    try { return portal_pdo()->query('SELECT id,email,active,notify_failure,notify_vendor,notify_slow,notify_summary FROM alert_recipients ORDER BY email')->fetchAll(); }
    catch (Throwable $e) { return []; }
}
function save_alert_recipient(string $email): void {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException('Enter a valid email address.');
    portal_pdo()->prepare('INSERT INTO alert_recipients(email,created_by) VALUES(?,?) ON DUPLICATE KEY UPDATE active=1')->execute([$email, user()['username'] ?? null]);
    audit('alert_recipient_add', null, 'alert_recipients', $email, 'enabled');
}
function update_alert_recipient_prefs(int $id, bool $failure, bool $vendor, bool $slow, bool $summary): void {
    portal_pdo()->prepare('UPDATE alert_recipients SET notify_failure=?, notify_vendor=?, notify_slow=?, notify_summary=? WHERE id=?')->execute([$failure ? 1 : 0, $vendor ? 1 : 0, $slow ? 1 : 0, $summary ? 1 : 0, $id]);
    audit('alert_recipient_prefs', null, 'alert_recipients', (string)$id, 'failure='.(int)$failure.' vendor='.(int)$vendor.' slow='.(int)$slow.' summary='.(int)$summary);
}
function toggle_alert_recipient(int $id): void {
    $st = portal_pdo()->prepare('UPDATE alert_recipients SET active = 1 - active WHERE id=?');
    $st->execute([$id]);
    audit('alert_recipient_toggle', null, 'alert_recipients', (string)$id, null);
}
function smtp_db_row(): ?array {
    try { $r = portal_pdo()->query('SELECT * FROM alert_smtp WHERE id=1')->fetch(); return $r && trim((string)$r['host']) !== '' ? $r : null; }
    catch (Throwable $e) { return null; }
}
function save_smtp_settings(array $d): void {
    $host = trim((string)($d['host'] ?? ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $host)) throw new RuntimeException('Enter the mail server hostname (letters, numbers, dots and dashes only).');
    $port = (int)($d['port'] ?? 0);
    if ($port < 1 || $port > 65535) throw new RuntimeException('Port must be between 1 and 65535.');
    $secure = in_array($d['secure'] ?? '', ['tls', 'ssl', 'none'], true) ? $d['secure'] : 'tls';
    $user = trim((string)($d['username'] ?? ''));
    $from = trim((string)($d['from_email'] ?? ''));
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('"From" must be a valid email address.');
    $pass = (string)($d['password'] ?? '');
    $existing = smtp_db_row();
    $passEnc = $pass !== '' ? encrypt_secret($pass) : ($existing['password_enc'] ?? null);
    portal_pdo()->prepare('INSERT INTO alert_smtp(id,host,port,secure,username,password_enc,from_email,tls_verify,updated_by) VALUES(1,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE host=VALUES(host),port=VALUES(port),secure=VALUES(secure),username=VALUES(username),password_enc=VALUES(password_enc),from_email=VALUES(from_email),tls_verify=VALUES(tls_verify),updated_by=VALUES(updated_by)')
        ->execute([$host, $port, $secure, $user, $passEnc, $from, empty($d['tls_verify']) ? 0 : 1, user()['username'] ?? null]);
    audit('alert_smtp_save', null, 'alert_smtp', '1', "$host:$port $secure user=".($user !== '' ? $user : '(none)').($pass !== '' ? ' password changed' : ''));
}
// Mail server: the settings saved on the Alerts page win; SMTP_* environment variables are the
// fallback. Returns null if neither has a host. 'source' says which one is in effect.
function smtp_server_settings(): ?array {
    if ($r = smtp_db_row()) {
        $user = (string)$r['username'];
        $from = trim((string)$r['from_email']) ?: $user;
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $from = 'vas-cloud@localhost';
        return ['host' => $r['host'], 'port' => (int)$r['port'], 'secure' => $r['secure'], 'user' => $user,
            'pass' => decrypt_secret($r['password_enc'] ?? ''), 'from' => $from, 'verify' => (int)$r['tls_verify'] === 1,
            'source' => 'app', 'has_password' => !empty($r['password_enc'])];
    }
    $host = trim((string)getenv('SMTP_HOST'));
    if ($host === '') return null;
    $user = (string)getenv('SMTP_USER');
    $from = trim((string)getenv('SMTP_FROM')) ?: $user;
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $from = 'vas-cloud@localhost';
    return [
        'host' => $host, 'port' => (int)(getenv('SMTP_PORT') ?: 587),
        'secure' => strtolower(trim((string)(getenv('SMTP_SECURE') ?: 'tls'))),
        'user' => $user, 'pass' => (string)getenv('SMTP_PASSWORD'),
        'from' => $from, 'verify' => getenv('SMTP_TLS_VERIFY') !== '0',
        'source' => 'environment', 'has_password' => getenv('SMTP_PASSWORD') !== false && getenv('SMTP_PASSWORD') !== '',
    ];
}
// Server + recipients (enabled ones from the Alerts page, plus ALERT_EMAIL_TO if set) — null unless both exist.
// $type limits recipients to those who chose that alert type ('failure_rate' / 'vendor_silent');
// null means everyone enabled (used by the test email). ALERT_EMAIL_TO addresses get every type.
function smtp_settings(?string $type = null): ?array {
    $c = smtp_server_settings();
    if (!$c) return null;
    $env = array_map('trim', explode(',', (string)getenv('ALERT_EMAIL_TO')));
    $want = ['failure_rate' => 'notify_failure', 'vendor_silent' => 'notify_vendor', 'vendor_slow' => 'notify_slow', 'daily_summary' => 'notify_summary'][$type ?? ''] ?? null;
    $db = array_column(array_filter(alert_recipients(), fn($r) => (int)$r['active'] === 1 && ($want === null || (int)$r[$want] === 1)), 'email');
    $to = array_values(array_unique(array_filter(array_map('strtolower', array_merge($db, $env)), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL))));
    if (!$to) return null;
    $c['to'] = $to;
    return $c;
}
// Returns null on success, or a short error string (server response text only — never credentials).
function smtp_send(array $c, string $subject, string $body): ?string {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $c['verify'], 'verify_peer_name' => $c['verify'], 'allow_self_signed' => !$c['verify']]]);
    $fp = @stream_socket_client(($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://').$c['host'].':'.$c['port'], $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return "could not connect to {$c['host']}:{$c['port']} ($errstr)";
    stream_set_timeout($fp, 8);
    $read = function () use ($fp): string {
        $resp = '';
        while (($line = fgets($fp, 1024)) !== false) { $resp .= $line; if (strlen($line) < 4 || $line[3] !== '-') break; }
        return $resp;
    };
    $cmd = function (string $line, array $ok) use ($fp, $read): string {
        if ($line !== '') fwrite($fp, $line."\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException($r === '' ? 'no response from mail server (timed out)' : trim(preg_replace('/\s+/', ' ', $r)));
        return $r;
    };
    try {
        $helo = preg_replace('/[^A-Za-z0-9.-]/', '', (string)gethostname()) ?: 'vas-cloud';
        $cmd('', [220]);
        $cmd("EHLO $helo", [250]);
        if ($c['secure'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS handshake failed (if the server uses an internal certificate, set SMTP_TLS_VERIFY=0)');
            $cmd("EHLO $helo", [250]);
        }
        if ($c['user'] !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($c['user']), [334]);
            $cmd(base64_encode($c['pass']), [235]);
        }
        $cmd('MAIL FROM:<'.$c['from'].'>', [250]);
        foreach ($c['to'] as $rcpt) $cmd('RCPT TO:<'.$rcpt.'>', [250, 251]);
        $cmd('DATA', [354]);
        $headers = [
            'Date: '.date('r'), 'From: '.$c['from'], 'To: '.implode(', ', $c['to']),
            'Subject: =?UTF-8?B?'.base64_encode(str_replace(["\r", "\n"], ' ', $subject)).'?=',
            'Message-ID: <'.bin2hex(random_bytes(8)).'@'.$helo.'>', 'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
        ];
        fwrite($fp, implode("\r\n", $headers)."\r\n\r\n".chunk_split(base64_encode($body))."\r\n.\r\n");
        $cmd('', [250]);
        @fwrite($fp, "QUIT\r\n");
        return null;
    } catch (Throwable $e) { return $e->getMessage(); }
    finally { @fclose($fp); }
}
function send_email_alert(string $subject, string $body, ?string $type = null): ?string {
    $c = smtp_settings($type);
    return $c ? smtp_send($c, $subject, $body) : 'email is not configured (SMTP_HOST / ALERT_EMAIL_TO)';
}

// Called both opportunistically (whenever a logged-in user views the Dashboard/Alerts page) and via
// the unauthenticated ?page=alert_cron endpoint (a Kubernetes CronJob hits that on a schedule so
// alerts go out even if nobody has the app open) — see deploy/k8s/05-alert-cronjob.yaml.
function dispatch_pending_alert_notifications(string $schema): void {
    $alerts = compute_alerts($schema);
    if (!$alerts) return;
    $db = portal_pdo();
    foreach ($alerts as $a) {
        if (empty($a['key'])) continue;
        $st = $db->prepare('SELECT last_sent_at FROM alert_notification_log WHERE alert_key=?');
        $st->execute([$a['key']]);
        $row = $st->fetch();
        if ($row && strtotime($row['last_sent_at']) > time() - ALERT_RENOTIFY_MINUTES * 60) continue;
        send_slack_alert($schema, '['.$schema.'] '.$a['message']);
        if (smtp_settings($a['type'] ?? null)) { try { send_email_alert('[VAS Cloud] '.$schema.' alert', '['.$schema.'] '.$a['message'], $a['type'] ?? null); } catch (Throwable $e) {} }
        audit('alert_fired', $schema, null, $a['key'], $a['message']);
        $db->prepare('INSERT INTO alert_notification_log(alert_key,last_sent_at) VALUES(?,NOW()) ON DUPLICATE KEY UPDATE last_sent_at=NOW()')->execute([$a['key']]);
    }
}

function failure_reasons_breakdown(...$a) { return cached('failure_reasons_breakdown:'.md5(serialize($a)), 60, fn() => failure_reasons_breakdown_uncached(...$a)); }
function failure_reasons_breakdown_uncached(string $schema, int $hours = 1, int $limit = 8): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return [];
    $hours = max(1, $hours); $limit = max(1, $limit);
    $st = pdo($schema)->prepare("SELECT COALESCE(NULLIF(TRIM(result_description),''),'(no reason given)') reason, COUNT(*) c FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL $hours HOUR AND NOT ".AUDIT_SUCCESS_SQL." GROUP BY reason ORDER BY c DESC LIMIT $limit");
    $st->execute();
    return $st->fetchAll();
}

// ===================== Offer Catalog health =====================
// Most recent purchase attempt per offer code. Uses the suffix index backwards (newest id first) so each code
// costs one index seek instead of scanning a month of the 100M-row subscription table. Codes that are not
// exactly 5 characters can never appear: subscription only records the last 5 characters of the transaction.
function offer_last_purchase_dates(string $schema, array $codes, int $budgetSeconds = 20): array {
    $out = ['dates' => [], 'partial' => false];
    if (!table_exists($schema, 'subscription')) return $out;
    $col = column_exists($schema, 'subscription', 'txn_offer_suffix') ? 'txn_offer_suffix' : 'RIGHT(transaction_id, 5)';
    $st = pdo($schema)->prepare("SELECT date FROM subscription WHERE $col = ? ORDER BY id DESC LIMIT 1");
    $start = microtime(true);
    foreach (array_values(array_unique($codes)) as $code) {
        if (strlen((string)$code) !== 5) continue;
        if (microtime(true) - $start > $budgetSeconds) { $out['partial'] = true; break; }
        $st->execute([$code]); $d = $st->fetchColumn();
        $out['dates'][$code] = $d === false ? null : $d;
    }
    return $out;
}
function offer_health(string $schema, int $days): array {
    $offers = pdo($schema)->query('SELECT id, vendor, offer_code, offer_code_for_other, name, status, one_time_price, rental_price, validity_amount FROM vas_offers ORDER BY offer_code, id')->fetchAll();
    $empty = fn($v) => $v === null || trim((string)$v) === '' || (is_numeric($v) && (float)$v == 0.0);
    $byCode = []; $byOther = []; $directCodes = [];
    foreach ($offers as $o) {
        $c = strtolower(trim((string)$o['offer_code'])); $byCode[$c][] = $o; $directCodes[$c] = true;
        $x = strtolower(trim((string)$o['offer_code_for_other'])); if ($x !== '') $byOther[$x][] = $o;
    }
    $dups = [];
    foreach ($byCode as $c => $rows) {
        if (count($rows) < 2) continue;
        $active = count(array_filter($rows, fn($r) => offer_is_active($r['status'])));
        $dups[] = ['code' => $rows[0]['offer_code'], 'rows' => $rows, 'active' => $active, 'conflict' => $active > 1];
    }
    usort($dups, fn($a, $b) => [$b['conflict'], count($b['rows'])] <=> [$a['conflict'], count($a['rows'])]);
    $otherClash = [];
    foreach ($byOther as $x => $rows) {
        $bases = array_unique(array_map(fn($r) => strtolower(trim((string)$r['offer_code'])), $rows));
        if (count($bases) > 1) $otherClash[] = ['code' => $rows[0]['offer_code_for_other'], 'rows' => $rows, 'why' => 'shared by '.count($bases).' different offers'];
        elseif (isset($directCodes[$x]) && $x !== $bases[array_key_first($bases)]) $otherClash[] = ['code' => $rows[0]['offer_code_for_other'], 'rows' => $rows, 'why' => 'also used as another offer\'s own code'];
    }
    $active = array_values(array_filter($offers, fn($o) => offer_is_active($o['status'])));
    $incomplete = [];
    foreach ($active as $o) {
        $why = [];
        if (trim((string)$o['name']) === '') $why[] = 'no name';
        if ($empty($o['one_time_price']) && $empty($o['rental_price'])) $why[] = 'no price';
        if ($empty($o['validity_amount'])) $why[] = 'no validity';
        if ($why) $incomplete[] = ['offer' => $o, 'why' => $why];
    }
    $codes = []; foreach ($active as $o) { $codes[] = (string)$o['offer_code']; if (trim((string)$o['offer_code_for_other']) !== '') $codes[] = (string)$o['offer_code_for_other']; }
    $last = cached('offer_last_purchase:'.$schema, 1800, fn() => offer_last_purchase_dates($schema, $codes));
    $cut = date('Y-m-d H:i:s', strtotime("-$days days")); $quiet = []; $unchecked = [];
    foreach ($active as $o) {
        $mine = array_filter([$o['offer_code'], trim((string)$o['offer_code_for_other'])], fn($c) => $c !== '' && strlen((string)$c) === 5);
        if (!$mine) { $unchecked[] = $o; continue; }
        $latest = null; foreach ($mine as $c) { $d = $last['dates'][$c] ?? null; if ($d !== null && ($latest === null || $d > $latest)) $latest = $d; }
        $known = false; foreach ($mine as $c) if (array_key_exists($c, $last['dates'])) $known = true;
        if (!$known) continue; // not looked up (time budget) — don't guess
        if ($latest === null || $latest < $cut) $quiet[] = ['offer' => $o, 'last' => $latest];
    }
    usort($quiet, fn($a, $b) => strcmp((string)$a['last'], (string)$b['last']));
    // One entry per offer with all of its problems, so the page can filter/sort a single list.
    $items = [];
    foreach ($offers as $o) $items[(int)$o['id']] = $o + ['issues' => [], 'last' => null, 'score' => 0];
    $add = function (int $id, string $key, string $label, string $sev, int $score) use (&$items) { if (isset($items[$id])) { $items[$id]['issues'][] = ['key' => $key, 'label' => $label, 'sev' => $sev]; $items[$id]['score'] += $score; } };
    foreach ($dups as $d) foreach ($d['rows'] as $r) $d['conflict'] && offer_is_active($r['status'])
        ? $add((int)$r['id'], 'dup', 'Repeated code — '.$d['active'].' active rows', 'danger', 50)
        : $add((int)$r['id'], 'dup', 'Repeated code'.(offer_is_active($r['status']) ? ' (newest active)' : ' (old version)'), 'secondary', 5);
    foreach ($otherClash as $d) foreach ($d['rows'] as $r) $add((int)$r['id'], 'other', '"Buy for other" code: '.$d['why'], 'warning', 30);
    foreach ($incomplete as $x) foreach ($x['why'] as $w) $add((int)$x['offer']['id'], 'missing', ucfirst($w), 'warning', 20);
    foreach ($quiet as $q) $add((int)$q['offer']['id'], 'quiet', $q['last'] ? 'No purchases in '.$days.' days' : 'Never bought', 'warning', 10);
    foreach ($unchecked as $o) $add((int)$o['id'], 'invisible', 'Code not 5 characters — reports can\'t see it', 'info', 8);
    foreach ($active as $o) {
        $mine = array_filter([$o['offer_code'], trim((string)$o['offer_code_for_other'])], fn($c) => $c !== '' && strlen((string)$c) === 5);
        $latest = null; foreach ($mine as $c) { $d = $last['dates'][$c] ?? null; if ($d !== null && ($latest === null || $d > $latest)) $latest = $d; }
        $items[(int)$o['id']]['last'] = $latest;
    }
    return ['total' => count($offers), 'active' => count($active), 'dups' => $dups, 'other_clash' => $otherClash, 'incomplete' => $incomplete, 'quiet' => $quiet,
        'unchecked' => $unchecked, 'partial' => !empty($last['partial']), 'days' => $days, 'items' => array_values($items)];
}

// ===================== Result cache =====================
// cached(key, ttl, fn): returns a stored result younger than ttl seconds, else runs fn and stores it. Kept in
// vas_portal (not on the Hera server) so all replicas share it, and anything unexpected simply falls back
// to running the query. Settings that change what a result means call cache_clear().
function cached(string $key, int $ttl, callable $fn) {
    static $memo = [];
    $k = strlen($key) > 190 ? substr($key, 0, 120).md5($key) : $key;
    if (array_key_exists($k, $memo)) return $memo[$k];
    try {
        $st = portal_pdo()->prepare('SELECT value FROM metric_cache WHERE cache_key=? AND expires_at > ?');
        $st->execute([$k, time()]); $v = $st->fetchColumn();
        if ($v !== false) { $d = json_decode((string)$v, true); if (is_array($d) && array_key_exists('v', $d)) return $memo[$k] = $d['v']; }
    } catch (Throwable $e) { return $fn(); }
    $val = $fn();
    try {
        $json = json_encode(['v' => $val], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) {
            portal_pdo()->prepare('REPLACE INTO metric_cache(cache_key,value,expires_at) VALUES(?,?,?)')->execute([$k, $json, time() + $ttl]);
            if (random_int(1, 50) === 1) portal_pdo()->prepare('DELETE FROM metric_cache WHERE expires_at < ?')->execute([time() - 3600]);
        }
    } catch (Throwable $e) {}
    return $memo[$k] = $val;
}
function cache_clear(): void { try { portal_pdo()->exec('DELETE FROM metric_cache'); } catch (Throwable $e) {} }

// ===================== Data retention (audit_log), complaint notes, token rotation =====================
const MONTH_NAMES = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
function retention_months(): int { return max(1, min(10, (int)alert_config()['retention_months'])); }
function save_retention_config(array $d): void {
    $n = filter_var($d['retention_months'] ?? null, FILTER_VALIDATE_INT);
    if ($n === false || $n < 1 || $n > 10) throw new RuntimeException('Keep between 1 and 10 months (each calendar month shares one partition, so 11 or 12 would mix this year with last year).');
    portal_pdo()->prepare('INSERT INTO alert_config(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute(['retention_months', (string)$n]);
    audit('retention_save', null, 'alert_config', null, 'retention_months='.$n);
}
// Per-calendar-month totals for audit_log, from information_schema (read-only; row counts are InnoDB estimates).
// audit_log is RANGE(month(create_date)) with partitions named January..December that are REUSED every year,
// so nothing is ever "dropped": a month is emptied (TRUNCATE PARTITION) before that month comes round again.
function audit_log_partition_report(string $schema): array {
    $st = pdo($schema)->prepare('SELECT PARTITION_NAME p, PARTITION_DESCRIPTION d, SUBPARTITION_NAME sp, TABLE_ROWS r, DATA_LENGTH dl, INDEX_LENGTH il FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $st->execute([$schema, AUDIT_LOG_TABLE]); $rows = $st->fetchAll();
    $out = ['partitioned' => false, 'months' => [], 'total_bytes' => 0, 'total_rows' => 0];
    foreach ($rows as $r) {
        $out['total_bytes'] += (int)$r['dl'] + (int)$r['il']; $out['total_rows'] += (int)$r['r'];
        if ($r['p'] === null) continue;
        $out['partitioned'] = true;
        $desc = (string)$r['d']; $m = null;
        if (ctype_digit($desc)) $m = (int)$desc - 1; elseif (strtoupper($desc) === 'MAXVALUE') $m = 12;
        if ($m === null || $m < 1 || $m > 12) { $k = array_search(ucfirst(strtolower((string)$r['p'])), MONTH_NAMES, true); $m = $k === false ? null : $k; }
        if ($m === null) continue;
        $out['months'][$m] ??= ['month' => $m, 'name' => $r['p'], 'rows' => 0, 'bytes' => 0, 'subs' => 0, 'nonempty' => 0];
        $out['months'][$m]['rows'] += (int)$r['r']; $out['months'][$m]['bytes'] += (int)$r['dl'] + (int)$r['il']; $out['months'][$m]['subs']++;
        if ((int)$r['r'] > 0 || (int)$r['dl'] > 16384) $out['months'][$m]['nonempty']++;
    }
    ksort($out['months']);
    return $out;
}
// Which months to keep (the current one plus the previous $keep) and what can be emptied. Pure planning:
// the app never runs the TRUNCATE itself — it only writes the statement for a DBA to run.
function audit_log_retention_plan(array $report, int $keep, ?int $currentMonth = null, string $schema = ''): array {
    $cur = $currentMonth ?? (int)date('n'); $keepSet = [];
    for ($k = 0; $k <= $keep; $k++) $keepSet[(($cur - 1 - $k) % 12 + 12) % 12 + 1] = $k;
    $plan = ['rows' => [], 'stale' => [], 'stale_bytes' => 0, 'kept_bytes' => 0, 'sql' => null];
    foreach ($report['months'] as $m => $r) {
        $isKept = isset($keepSet[$m]);
        $status = $isKept ? ($m === $cur ? 'current' : 'kept') : ($r['rows'] > 0 ? 'stale' : 'empty');
        $plan['rows'][$m] = $r + ['status' => $status, 'age' => $isKept ? $keepSet[$m] : null];
        if ($status === 'stale') { $plan['stale'][] = $r['name']; $plan['stale_bytes'] += $r['bytes']; }
        if ($isKept) $plan['kept_bytes'] += $r['bytes'];
    }
    if ($plan['stale']) $plan['sql'] = 'ALTER TABLE '.($schema !== '' ? '`'.$schema.'`.' : '').'`'.AUDIT_LOG_TABLE.'` TRUNCATE PARTITION '.implode(', ', $plan['stale']).';';
    // An empty partition still occupies ~1 MB, so "has rows" (not "has bytes") decides what counts. The average is
    // taken over finished months; the current month is only used if nothing else has data yet.
    $full = array_filter($report['months'], fn($r) => $r['rows'] > 0 && $r['month'] !== $cur);
    $use = $full ?: array_filter($report['months'], fn($r) => $r['rows'] > 0);
    $plan['avg_month_bytes'] = $use ? (int)(array_sum(array_column($use, 'bytes')) / count($use)) : 0;
    $plan['steady_state_bytes'] = $plan['avg_month_bytes'] * ($keep + 1);
    $plan['options'] = []; for ($n = 1; $n <= 6; $n++) $plan['options'][$n] = $plan['avg_month_bytes'] * ($n + 1);
    return $plan;
}
// Oldest/newest create_date inside ONE month partition: reveals last year's rows sitting in a month we keep.
// Capped at 30s so it can't pin the server; only runs when someone clicks it.
function audit_log_partition_dates(string $schema, string $partition, array $report): array {
    $known = array_column($report['months'], 'name');
    if (!in_array($partition, $known, true)) throw new RuntimeException('Unknown partition.');
    $db = pdo($schema);
    try { $db->exec('SET SESSION max_execution_time=30000'); } catch (Throwable $e) {}
    try {
        $r = $db->query('SELECT MIN(create_date) mn, MAX(create_date) mx FROM `'.AUDIT_LOG_TABLE.'` PARTITION (`'.$partition.'`)')->fetch();
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'max_execution_time') || str_contains($e->getMessage(), 'interrupted')) return ['timeout' => true];
        throw $e;
    }
    return ['mn' => $r['mn'], 'mx' => $r['mx'], 'timeout' => false];
}
// EXPLAIN for a one-day search exactly like Complaint Investigation runs, to show how many partitions a date
// search touches and which index serves it (EXPLAIN is read-only).
function audit_log_pruning_check(string $schema): array {
    $day = date('Y-m-d', strtotime('yesterday'));
    $st = pdo($schema)->prepare('EXPLAIN SELECT COUNT(*) FROM `'.AUDIT_LOG_TABLE.'` WHERE create_date BETWEEN ? AND ?');
    $st->execute([$day.' 00:00:00', $day.' 23:59:59']); $r = $st->fetch();
    $parts = (string)($r['partitions'] ?? ''); $n = $parts === '' ? 0 : count(explode(',', $parts));
    return ['partitions' => $n, 'key' => $r['key'] ?? null, 'type' => $r['type'] ?? null, 'rows' => $r['rows'] ?? null, 'day' => $day];
}

function add_complaint_note(string $schema, string $msisdn, string $note, ?string $txid = null): void {
    $msisdn = preg_replace('/\D+/', '', $msisdn); $note = trim($note);
    if ($msisdn === '' || strlen($msisdn) > 15) throw new RuntimeException('MSISDN must be digits only.');
    if ($note === '' || mb_strlen($note) > 2000) throw new RuntimeException('Write a note of up to 2,000 characters.');
    portal_pdo()->prepare('INSERT INTO complaint_notes(schema_name,msisdn,transaction_id,note,created_by) VALUES(?,?,?,?,?)')->execute([$schema, $msisdn, $txid !== null && trim($txid) !== '' ? substr(trim($txid), 0, 64) : null, $note, user()['username'] ?? null]);
    audit('complaint_note', $schema, 'complaint_notes', $msisdn, mb_strimwidth($note, 0, 200, '...'));
}
function complaint_notes_for(string $schema, string $msisdn): array {
    $st = portal_pdo()->prepare('SELECT id,transaction_id,note,created_by,created_at FROM complaint_notes WHERE schema_name=? AND msisdn=? ORDER BY id DESC LIMIT 100');
    $st->execute([$schema, preg_replace('/\D+/', '', $msisdn)]);
    return $st->fetchAll();
}
function rotate_alert_cron_token(): string {
    $new = bin2hex(random_bytes(24));
    portal_pdo()->prepare('INSERT INTO app_secrets(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute(['alert_cron_token', $new]);
    audit('alert_cron_token_rotated', null, 'app_secrets', 'alert_cron_token', 'rotated');
    return $new;
}

// ===================== System status =====================
// One look at whether everything the USSD service depends on is working. Read-only: it only connects and reads
// (the Mobius check is a login, nothing is sent to a customer or to Hera).
function system_status(): array {
    $row = fn(string $label, string $state, string $detail, ?string $link = null) => ['label' => $label, 'state' => $state, 'detail' => $detail, 'link' => $link];
    $ms = fn(float $t0) => (int)round((microtime(true) - $t0) * 1000).' ms';
    $tcp = function (string $url) use ($ms): array {
        $p = parse_url($url); $host = $p['host'] ?? ''; $port = (int)($p['port'] ?? (($p['scheme'] ?? '') === 'https' ? 443 : 80));
        if ($host === '') return [false, 'no address saved'];
        $t0 = microtime(true); $c = @fsockopen($host, $port, $en, $es, 3);
        if (!$c) return [false, (trim((string)$es) ?: 'cannot connect').' ('.$host.':'.$port.')']; fclose($c);
        return [true, $host.' answers in '.$ms($t0)];
    };
    $age = function (?string $ts): string { if (!$ts) return 'never'; $d = time() - strtotime($ts); return $d < 90 ? $d.' s ago' : ($d < 5400 ? round($d / 60).' min ago' : ($d < 172800 ? round($d / 3600).' h ago' : round($d / 86400).' days ago')); };
    $cfg = ussd_proxy_config(); $out = [];

    $sys = [$row('Version', 'info', substr((string)(getenv('APP_VERSION') ?: 'dev'), 0, 12)), $row('PHP', 'info', PHP_VERSION),
        $row('Your address', 'info', client_ip().' (connection '.(string)($_SERVER['REMOTE_ADDR'] ?? '?').', forwarded '.((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') ?: 'none').') — what login lock-out and the audit trail record for you')];
    try { $t0 = microtime(true); portal_pdo()->query('SELECT 1'); $sys[] = $row('Portal database', 'ok', 'answers in '.$ms($t0)); } catch (Throwable $e) { $sys[] = $row('Portal database', 'bad', 'not reachable'); }
    foreach ((array)app_config('allowed_schemas') as $s) {
        try { $t0 = microtime(true); pdo($s)->query('SELECT 1'); $sys[] = $row('Database '.$s, 'ok', 'answers in '.$ms($t0).(is_protected_schema($s) ? ' · live data, read-only here' : '')); }
        catch (Throwable $e) { $sys[] = $row('Database '.$s, 'warn', 'not reachable from the portal'); }
    }
    $out[] = ['title' => 'System', 'rows' => $sys];

    $conn = [];
    [$ok, $d] = $tcp((string)$cfg['purchase_url']); $conn[] = $row('Hera (purchases)', $ok ? 'ok' : 'bad', $d, '?page=ussd_proxy');
    if (parse_url((string)$cfg['share_base'], PHP_URL_HOST) !== parse_url((string)$cfg['purchase_url'], PHP_URL_HOST)) { [$ok, $d] = $tcp((string)$cfg['share_base']); $conn[] = $row('Hera (Shared Bundle)', $ok ? 'ok' : 'bad', $d, '?page=ussd_proxy'); }
    if ($cfg['mobius_user'] === '' || $cfg['mobius_pass'] === '') $conn[] = $row('Mobius', 'warn', 'API user not set up', '?page=ussd_proxy');
    else foreach (mobius_test($cfg) as $m) $conn[] = $row('Mobius '.(parse_url($m['base'], PHP_URL_HOST) ?: ''), $m['ok'] ? 'ok' : 'bad', $m['msg'], '?page=ussd_proxy');
    $out[] = ['title' => 'Connections', 'rows' => $conn];

    $u = [];
    $live = $cfg['enabled'] === '1' && $cfg['mode'] === 'live' && $cfg['push_enabled'] === '1';
    $u[] = $row('USSD endpoint', $live ? 'ok' : 'warn', $cfg['enabled'] !== '1' ? 'switched off' : ($cfg['mode'] !== 'live' ? 'in Capture mode (not serving menus)' : ($cfg['push_enabled'] !== '1' ? 'answering is switched off' : 'on, Live, answering through Mobius')), '?page=ussd_proxy');
    try {
        $db = portal_pdo();
        $r = $db->query("SELECT COUNT(*) n, SUM(note LIKE 'PUSH FAILED%') f, ROUND(AVG(ms)) a, MAX(created_at) last FROM ussd_proxy_log WHERE created_at >= NOW() - INTERVAL 1 HOUR")->fetch();
        $lastAny = $db->query('SELECT MAX(created_at) FROM ussd_proxy_log')->fetchColumn();
        $u[] = $row('Requests, last hour', 'info', (int)$r['n'].' (last one '.$age($lastAny ?: null).')'.((int)$r['n'] ? ' · average '.(int)$r['a'].' ms' : ''));
        $u[] = $row('Screens that failed to reach Mobius', (int)$r['f'] > 0 ? 'bad' : 'ok', (int)$r['f'] > 0 ? (int)$r['f'].' in the last hour — see the captured requests' : 'none in the last hour', '?page=ussd_proxy');
        ussd_purchase_table();
        $st = $db->query("SELECT status, COUNT(*) n FROM ussd_purchases WHERE created_at >= NOW() - INTERVAL 1 DAY GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $parts = []; foreach ($st as $k => $n) $parts[] = $k.' '.$n;
        $u[] = $row('Purchases, last 24 h', (($st['failed'] ?? 0) > 0) ? 'warn' : 'info', $parts ? implode(' · ', $parts) : 'none', '?page=ussd_proxy');
        ussd_quiz_tables(); $u[] = $row('Quiz games, last 24 h', 'info', (string)(int)$db->query("SELECT COUNT(*) FROM ussd_quiz_plays WHERE created_at >= NOW() - INTERVAL 1 DAY")->fetchColumn(), '?page=ussd_quiz');
    } catch (Throwable $e) { $u[] = $row('USSD activity', 'warn', 'could not be read'); }
    $out[] = ['title' => 'USSD service', 'rows' => $u];

    $m = [];
    try {
        foreach (menu_shortcodes() as $sc) {
            $h = menu_health($sc); $e = count(array_filter($h, fn($x) => $x['level'] === 'error')); $w = count(array_filter($h, fn($x) => $x['level'] === 'warn'));
            $m[] = $row('Menu '.$sc, $e ? 'bad' : ($w ? 'warn' : 'ok'), $e ? $e.' to fix, '.$w.' to look at' : ($w ? $w.' to look at' : 'all good'), '?page=ussd_menu&short_code='.urlencode($sc));
        }
    } catch (Throwable $e) {}
    $out[] = ['title' => 'Menus', 'rows' => $m ?: [$row('Menus', 'info', 'no short codes yet')]];

    // secrets: are they set, and when were they last changed (from the audit trail); older than 90 days is flagged
    $last = function (array $actions) { try { $in = implode(',', array_fill(0, count($actions), '?')); $st = portal_pdo()->prepare("SELECT MAX(created_at) FROM portal_audit_trail WHERE action IN ($in)"); $st->execute($actions); return $st->fetchColumn() ?: null; } catch (Throwable $e) { return null; } };
    $sec = []; $secRow = function (string $label, bool $set, array $actions, string $link, string $how) use (&$sec, $row, $last, $age) {
        $ts = $last($actions); $old = $ts && time() - strtotime($ts) > 90 * 86400;
        $sec[] = $row($label, !$set ? 'warn' : (($old || !$ts) ? 'warn' : 'ok'), ($set ? 'set' : 'not set').' · last changed '.$age($ts).($set && (!$ts || $old) ? ' — rotate it ('.$how.')' : ''), $link);
    };
    $secRow('USSD endpoint token', ussd_proxy_token() !== '', ['ussd_proxy_token_rotated'], '?page=ussd_proxy', 'New token on the USSD Proxy page, then update the URL in Mobius');
    $secRow('Mobius API password', $cfg['mobius_pass'] !== '', ['ussd_proxy_mobius_save'], '?page=ussd_proxy', 'use a dedicated Mobius API user');
    $secRow('Hera headers (API key)', $cfg['purchase_auth'] !== '', ['ussd_purchase_save'], '?page=ussd_proxy', 'ask the Hera owner for a new key, enter it on the purchase card');
    $secRow('Alert cron token', true, ['alert_cron_token_rotated'], '?page=alert_settings', 'Alert Settings, rotate, then update the CronJob');
    $out[] = ['title' => 'Secrets (rotate at least every 90 days)', 'rows' => $sec];
    return $out;
}
// ===================== Vendor detail, customer timeline, offer history, daily summary =====================
function valid_day(string $d, int $maxBackDays = 31): string {
    $t = strtotime($d);
    if ($t === false || $t > strtotime('today') || $t < strtotime("-$maxBackDays days")) return date('Y-m-d');
    return date('Y-m-d', $t);
}
function failure_reasons_day(...$a) { return cached('failure_reasons_day:'.md5(serialize($a)), 60, fn() => failure_reasons_day_uncached(...$a)); }
function failure_reasons_day_uncached(string $schema, string $date, int $limit = 8, ?string $vendor = null): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return [];
    $params = [$date.' 00:00:00', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00']; $vsql = '';
    if ($vendor !== null) { $vsql = " AND COALESCE(NULLIF(vendor_entity_name,''),'(none)') = ?"; $params[] = $vendor; }
    $st = pdo($schema)->prepare("SELECT COALESCE(NULLIF(TRIM(result_description),''),'(no reason given)') reason, COUNT(*) c FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= ? AND create_date < ? AND NOT ".AUDIT_SUCCESS_SQL."$vsql GROUP BY reason ORDER BY c DESC LIMIT ".max(1, min(50, $limit)));
    $st->execute($params);
    return $st->fetchAll();
}
// Everything the vendor page shows, for one vendor on one day (a bounded single-day create_date range, so
// it touches one partition set like the other pages).
function vendor_detail(...$a) { return cached('vendor_detail:'.md5(serialize($a)), 60, fn() => vendor_detail_uncached(...$a)); }
function vendor_detail_uncached(string $schema, string $vendor, string $date): array {
    $out = ['total' => 0, 'ok' => 0, 'failed' => 0, 'avg_ms' => null, 'max_ms' => null, 'slow' => 0, 'hourly' => [], 'channels' => [], 'reasons' => [], 'recent_failures' => [], 'prev_total' => 0];
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return $out;
    $db = pdo($schema); $t = ident(AUDIT_LOG_TABLE);
    $from = $date.' 00:00:00'; $to = date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00';
    $v = "COALESCE(NULLIF(vendor_entity_name,''),'(none)') = ?"; $slowMs = (int)alert_config()['vendor_slow_ms'];
    $st = $db->prepare("SELECT COUNT(*) total, SUM(CASE WHEN ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) ok, AVG(response_time) avg_ms, MAX(response_time) max_ms, SUM(response_time > $slowMs) slow FROM $t WHERE create_date >= ? AND create_date < ? AND $v");
    $st->execute([$from, $to, $vendor]); $r = $st->fetch();
    $out['total'] = (int)$r['total']; $out['ok'] = (int)$r['ok']; $out['failed'] = $out['total'] - $out['ok'];
    $out['avg_ms'] = $r['avg_ms'] === null ? null : (int)round((float)$r['avg_ms']); $out['max_ms'] = $r['max_ms'] === null ? null : (int)$r['max_ms']; $out['slow'] = (int)$r['slow'];
    $st = $db->prepare("SELECT HOUR(create_date) h, COUNT(*) total, SUM(CASE WHEN ".AUDIT_SUCCESS_SQL." THEN 0 ELSE 1 END) failed, AVG(response_time) avg_ms FROM $t WHERE create_date >= ? AND create_date < ? AND $v GROUP BY h ORDER BY h");
    $st->execute([$from, $to, $vendor]); $out['hourly'] = $st->fetchAll();
    $st = $db->prepare("SELECT COALESCE(NULLIF(channel,''),'(none)') channel, COUNT(*) total, SUM(CASE WHEN ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) ok, AVG(response_time) avg_ms FROM $t WHERE create_date >= ? AND create_date < ? AND $v GROUP BY 1 ORDER BY total DESC");
    $st->execute([$from, $to, $vendor]); $out['channels'] = $st->fetchAll();
    $out['reasons'] = failure_reasons_day($schema, $date, 8, $vendor);
    $st = $db->prepare("SELECT id, create_date, transaction_id, msisdn, channel, result_description, response_time FROM $t WHERE create_date >= ? AND create_date < ? AND $v AND NOT ".AUDIT_SUCCESS_SQL." ORDER BY create_date DESC LIMIT 15");
    $st->execute([$from, $to, $vendor]); $out['recent_failures'] = $st->fetchAll();
    $st = $db->prepare("SELECT COUNT(*) FROM $t WHERE create_date >= ? AND create_date < ? AND $v");
    $st->execute([date('Y-m-d', strtotime($date.' -1 day')).' 00:00:00', $from, $vendor]); $out['prev_total'] = (int)$st->fetchColumn();
    return $out;
}

// One subscriber across both log sources, newest first. audit_log allows 31 days and subscription 7, so the
// subscription side is clipped to its allowed window ending at $to (and the page says so).
function customer_timeline(string $schema, string $msisdn, string $from, string $to): array {
    $msisdn = preg_replace('/\D+/', '', $msisdn);
    if ($msisdn === '' || strlen($msisdn) > 15) throw new RuntimeException('Enter the MSISDN as digits only.');
    $events = []; $notes = [];
    if (table_exists($schema, AUDIT_LOG_TABLE)) {
        $f = audit_log_filters_from_request(['date_from' => $from, 'date_to' => $to, 'msisdn' => $msisdn]);
        $d = search_audit_log($schema, $f, 1, 200);
        foreach ($d['rows'] as $r) $events[] = ['when' => $r['create_date'], 'source' => 'audit_log', 'id' => $r['id'], 'date' => $r['create_date'], 'transaction_id' => $r['transaction_id'],
            'channel' => $r['channel'], 'what' => (string)$r['vendor_entity_name'], 'ok' => is_success_status($r['result_status']), 'result' => (string)$r['result_description']];
        if ($d['total'] > 200) $notes[] = 'audit_log: showing the newest 200 of '.number_format($d['total']).' — narrow the date range to see the rest.';
    }
    if (table_exists($schema, 'subscription')) {
        $sf = max(strtotime($from), strtotime($to) - (SUBSCRIPTION_LOG_MAX_RANGE_DAYS - 1) * 86400);
        if ($sf > strtotime($from)) $notes[] = 'subscription is only searched for the last '.SUBSCRIPTION_LOG_MAX_RANGE_DAYS.' days of the range (from '.date('Y-m-d', $sf).').';
        $f = subscription_log_filters_from_request(['date_from' => date('Y-m-d', $sf), 'date_to' => $to, 'msisdn' => $msisdn]);
        $d = search_subscription_log($schema, $f, 1, 200);
        foreach ($d['rows'] as $r) $events[] = ['when' => $r['date'], 'source' => 'subscription', 'id' => $r['id'], 'date' => $r['date'], 'transaction_id' => $r['transaction_id'],
            'channel' => $r['channel'], 'what' => (string)$r['subscription_type'], 'ok' => is_subscription_success($r['result_desc']), 'result' => (string)$r['result_desc']];
        if ($d['total'] > 200) $notes[] = 'subscription: showing the newest 200 of '.number_format($d['total']).'.';
    }
    usort($events, fn($a, $b) => strcmp($b['when'], $a['when']));
    return ['events' => $events, 'notes' => $notes, 'msisdn' => $msisdn];
}

// Who changed an offer and what, from the audit trail. Old entries (before changes were recorded as
// from/to pairs) only know the values that were submitted, and are shown as such.
function offer_history(string $schema, int $id): array {
    $st = portal_pdo()->prepare("SELECT id, created_at, username, action, details FROM portal_audit_trail WHERE schema_name=? AND target_table='vas_offers' AND target_key IN (?,?) AND action IN ('insert','update') ORDER BY id DESC LIMIT 200");
    $st->execute([$schema, json_encode(['id' => $id]), json_encode(['id' => (string)$id])]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $d = json_decode((string)$r['details'], true); $changes = []; $legacy = false;
        if (is_array($d) && isset($d['changed']) && is_array($d['changed'])) $changes = $d['changed'];
        elseif (is_array($d)) { $legacy = true; foreach ($d as $k => $v) $changes[$k] = ['from' => null, 'to' => is_scalar($v) || $v === null ? $v : json_encode($v)]; }
        $rows[] = ['at' => $r['created_at'], 'user' => $r['username'], 'action' => $r['action'], 'changes' => $changes, 'legacy' => $legacy];
    }
    return $rows;
}

function daily_summary_data(string $schema, string $date): array {
    $t = ident(AUDIT_LOG_TABLE); $db = pdo($schema);
    $from = $date.' 00:00:00'; $to = date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00'; $pfrom = date('Y-m-d', strtotime($date.' -1 day')).' 00:00:00';
    $ok = AUDIT_SUCCESS_SQL;
    $q = function (string $col) use ($db, $t, $from, $to, $ok) {
        // per-session channel labels such as USSD-866195620-42018 are grouped under their prefix (USSD)
        $expr = $col === 'channel' ? "COALESCE(NULLIF(SUBSTRING_INDEX(channel,'-',1),''),'(none)')" : "COALESCE(NULLIF($col,''),'(none)')";
        $st = $db->prepare("SELECT $expr name, COUNT(*) total, SUM(CASE WHEN $ok THEN 1 ELSE 0 END) ok, AVG(response_time) avg_ms FROM $t WHERE create_date >= ? AND create_date < ? GROUP BY 1 ORDER BY total DESC LIMIT 15");
        $st->execute([$from, $to]); return $st->fetchAll();
    };
    $lk = LOOKUPS_SQL;
    $st = $db->prepare("SELECT COUNT(*) total, SUM(CASE WHEN $ok THEN 1 ELSE 0 END) ok, AVG(response_time) avg_ms FROM $t WHERE create_date >= ? AND create_date < ? AND NOT $lk");
    $st->execute([$from, $to]); $tot = $st->fetch();
    $st = $db->prepare("SELECT COUNT(*) total, SUM(CASE WHEN $ok THEN 1 ELSE 0 END) ok, AVG(response_time) avg_ms FROM $t WHERE create_date >= ? AND create_date < ? AND $lk");
    $st->execute([$from, $to]); $lookups = $st->fetch();
    // Failures split into those the admin marked as customer-side (e.g. no credit) and the rest, which are the
    // ones that point at a system problem; plus the previous day's volume for comparison.
    [$cf, $cfParams, $ignoredReasons] = alert_counted_failure_sql();
    $st = $db->prepare("SELECT SUM(CASE WHEN $cf AND NOT $lk THEN 1 ELSE 0 END) counted_customer, SUM(CASE WHEN $cf AND $lk THEN 1 ELSE 0 END) counted_lookups FROM $t WHERE create_date >= ? AND create_date < ?");
    $st->execute(array_merge($cfParams, $cfParams, [$from, $to])); $cr = $st->fetch(); $counted = (int)$cr['counted_customer']; $countedLookups = (int)$cr['counted_lookups'];
    $st = $db->prepare("SELECT COUNT(*) FROM $t WHERE create_date >= ? AND create_date < ? AND NOT $lk"); $st->execute([$pfrom, $from]); $prevTotal = (int)$st->fetchColumn();
    $cfg = alert_config();
    $st = $db->prepare("SELECT COALESCE(NULLIF(vendor_entity_name,''),'(none)') name, COUNT(*) c FROM $t WHERE create_date >= ? AND create_date < ? GROUP BY 1 HAVING c >= ?");
    $st->execute([$pfrom, $from, $cfg['vendor_silent_min_baseline']]); $before = array_column($st->fetchAll(), 'c', 'name');
    $vendors = $q('vendor_entity_name'); $seen = array_column($vendors, 'name');
    $silent = []; foreach ($before as $name => $c) if (!in_array($name, $seen, true)) $silent[$name] = (int)$c;
    return ['date' => $date, 'prev_total' => $prevTotal, 'system_failed' => $counted, 'lookups' => ['total' => (int)$lookups['total'], 'ok' => (int)$lookups['ok'], 'avg_ms' => $lookups['avg_ms'] === null ? null : (int)round((float)$lookups['avg_ms']), 'system_failed' => $countedLookups], 'ignored_reasons' => $ignoredReasons, 'total' => (int)$tot['total'], 'ok' => (int)$tot['ok'], 'avg_ms' => $tot['avg_ms'] === null ? null : (int)round((float)$tot['avg_ms']),
        'channels' => $q('channel'), 'vendors' => $vendors, 'reasons' => failure_reasons_day($schema, $date, 5), 'silent' => $silent, 'retention' => (function () use ($schema) {
            try {
                $rep = audit_log_partition_report($schema); if (!$rep['partitioned']) return null;
                $plan = audit_log_retention_plan($rep, retention_months());
                return $plan['stale_bytes'] > 512 * 1048576 ? sprintf('Data retention: %.1f GB of audit_log is older than your %d-month window and can be freed (Admin > Data Retention).', $plan['stale_bytes'] / 1073741824, retention_months()) : null;
            } catch (Throwable $e) { return null; }
        })()];
}
function daily_summary_text(string $schema, array $d): string {
    $pct = fn($ok, $t) => $t > 0 ? number_format(100 * $ok / $t, 1).'%' : '-';
    $ms = fn($v) => $v === null ? '-' : number_format((float)$v).' ms';
    $l = ['VAS Cloud daily summary - '.$schema.' - '.$d['date'], str_repeat('=', 50), ''];
    $failed = $d['total'] - $d['ok']; $customer = max(0, $failed - $d['system_failed']);
    $delta = $d['prev_total'] > 0 ? sprintf(' (%s%d%% vs day before: %s)', $d['total'] >= $d['prev_total'] ? '+' : '-', abs(round(100 * ($d['total'] - $d['prev_total']) / $d['prev_total'])), number_format($d['prev_total'])) : '';
    $l[] = sprintf('Customer transactions: %s%s', number_format($d['total']), $delta);
    $l[] = sprintf('Success:      %s   Avg response: %s', $pct($d['ok'], $d['total']), $ms($d['avg_ms']));
    $l[] = sprintf('Failed:       %s   of which customer-side (reasons you ignore in alerts): %s', number_format($failed), number_format($customer));
    $l[] = sprintf('SYSTEM FAILURES: %s (%s of customer transactions)', number_format($d['system_failed']), $pct($d['system_failed'], $d['total']));
    $lk = $d['lookups'];
    $l[] = sprintf('System lookups (no channel): %s   %s ok   %s   [Hera\'s own background calls to its vendors, e.g. free-unit queries - not customer purchases%s]', number_format($lk['total']), $pct($lk['ok'], $lk['total']), $ms($lk['avg_ms']), $lk['system_failed'] > 0 ? '; '.number_format($lk['system_failed']).' failed' : '');
    foreach (['channels' => 'By channel', 'vendors' => 'By vendor'] as $k => $title) {
        $l[] = ''; $l[] = $title.':';
        if (!$d[$k]) $l[] = '  (no traffic)';
        foreach ($d[$k] as $r) $l[] = sprintf('  %-28s %9s   %7s ok   %s', mb_strimwidth(activity_label($r['name'], $k === 'channels' ? 'channel' : 'vendor'), 0, 28, '..'), number_format((int)$r['total']), $pct((int)$r['ok'], (int)$r['total']), $ms($r['avg_ms'] === null ? null : round((float)$r['avg_ms'])));
    }
    $l[] = ''; $l[] = 'Top failure reasons:';
    if (!$d['reasons']) $l[] = '  (none)';
    foreach ($d['reasons'] as $r) $l[] = sprintf('  %9s  %s%s', number_format((int)$r['c']), $r['reason'], in_array($r['reason'], $d['ignored_reasons'], true) ? '   [customer-side, ignored in alerts]' : '');
    if (!empty($d['retention'])) { $l[] = ''; $l[] = $d['retention']; }
    if ($d['silent']) { $l[] = ''; $l[] = 'Vendors active the day before but silent this day:'; foreach ($d['silent'] as $n => $c) $l[] = '  '.$n.' ('.number_format($c).' the day before)'; }
    return implode("\n", $l)."\n";
}
// $force sends regardless of the schedule/duplicate check (the "Send now" button). Returns null on success.
function send_daily_summary(string $schema, ?string $date = null, bool $force = false): ?string {
    if (!smtp_settings('daily_summary')) return 'email is not configured or no recipient receives the daily summary';
    $date = $date ?: date('Y-m-d', strtotime('yesterday'));
    $text = daily_summary_text($schema, daily_summary_data($schema, $date));
    $err = send_email_alert('[VAS Cloud] Daily summary '.$schema.' '.$date, $text, 'daily_summary');
    audit('daily_summary', $schema, null, $date, $err ?? 'sent'.($force ? ' (manual)' : ''));
    return $err;
}
// Called by the 5-minute cron. Sends yesterday's summary once per day, after the configured hour. The row
// in alert_notification_log is claimed before sending so two overlapping runs can't both send it.
function maybe_send_daily_summary(string $schema): void {
    $cfg = alert_config();
    if (!$cfg['summary_enabled'] || (int)date('G') < $cfg['summary_hour'] || !table_exists($schema, AUDIT_LOG_TABLE)) return;
    $key = 'daily_summary:'.$schema.':'.date('Y-m-d');
    $db = portal_pdo();
    $claim = $db->prepare('INSERT IGNORE INTO alert_notification_log(alert_key,last_sent_at) VALUES(?,NOW())'); $claim->execute([$key]);
    if ($claim->rowCount() !== 1) return;
    if (send_daily_summary($schema) !== null) $db->prepare('DELETE FROM alert_notification_log WHERE alert_key=?')->execute([$key]);
}

// ===================== Sales Orders & Invoices (relational lookup) =====================
// All three tables are small (order_no is the natural join key; none of them carry an index on it,
// but each table is tiny, so an equality scan is fine — unlike subscription/audit_log).
function find_sales_order(string $schema, string $orderNo): ?array {
    if (!table_exists($schema,'sales_order')) return null;
    $st = pdo($schema)->prepare('SELECT * FROM sales_order WHERE order_no = ? LIMIT 1');
    $st->execute([$orderNo]);
    return $st->fetch() ?: null;
}
function find_sales_orders_by_iccid(string $schema, string $iccid): array {
    if (!table_exists($schema,'sales_order')) return [];
    $st = pdo($schema)->prepare('SELECT * FROM sales_order WHERE iccid = ? ORDER BY id DESC');
    $st->execute([$iccid]);
    return $st->fetchAll();
}
function sales_order_items_for(string $schema, string $orderNo): array {
    if (!table_exists($schema,'sales_order_items')) return [];
    $st = pdo($schema)->prepare('SELECT * FROM sales_order_items WHERE order_no = ? ORDER BY id');
    $st->execute([$orderNo]);
    return $st->fetchAll();
}
function sales_invoices_for(string $schema, string $orderNo): array {
    if (!table_exists($schema,'sales_invoice')) return [];
    $st = pdo($schema)->prepare('SELECT * FROM sales_invoice WHERE order_no = ? ORDER BY id');
    $st->execute([$orderNo]);
    return $st->fetchAll();
}

// ===================== Voting Service =====================
function voting_tally(string $schema): array {
    if (!table_exists($schema,'voting_service') || !table_exists($schema,'voting_contestant')) return [];
    $db = pdo($schema);
    $votes = $db->query('SELECT content, COUNT(*) c FROM voting_service GROUP BY content ORDER BY c DESC')->fetchAll();
    $byNumber = [];
    foreach ($db->query('SELECT number, name, status FROM voting_contestant')->fetchAll() as $c) $byNumber[(string)$c['number']] = $c;
    $out = [];
    foreach ($votes as $v) {
        $c = $byNumber[(string)$v['content']] ?? null;
        $out[] = ['content' => $v['content'], 'votes' => (int)$v['c'], 'contestant' => $c['name'] ?? null, 'status' => $c['status'] ?? null];
    }
    return $out;
}

// ===================== Partner API (read-only, API-key authenticated) =====================
const API_RATE_LIMIT_PER_MINUTE = 60;

function create_api_key(string $label): string {
    $plain = 'vasapi_' . bin2hex(random_bytes(24));
    $hash = hash('sha256', $plain);
    portal_pdo()->prepare('INSERT INTO api_keys(label,key_hash,status,created_by) VALUES(?,?,"active",?)')->execute([$label, $hash, user()['username'] ?? null]);
    audit('create_api_key', null, 'api_keys', null, $label);
    return $plain;
}
function revoke_api_key(int $id): void {
    portal_pdo()->prepare("UPDATE api_keys SET status='revoked' WHERE id=?")->execute([$id]);
    audit('revoke_api_key', null, 'api_keys', (string)$id, null);
}
function list_api_keys(): array {
    return portal_pdo()->query('SELECT id, label, status, created_by, created_at, last_used_at FROM api_keys ORDER BY id DESC')->fetchAll();
}

function api_error(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
function api_json($data): never {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}
function authenticate_api_key(string $endpoint): array {
    $header = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($header === '') api_error(401, 'Missing X-Api-Key header');
    $hash = hash('sha256', $header);
    $st = portal_pdo()->prepare("SELECT * FROM api_keys WHERE key_hash=? AND status='active' LIMIT 1");
    $st->execute([$hash]);
    $key = $st->fetch();
    if (!$key) api_error(401, 'Invalid or revoked API key');
    $st = portal_pdo()->prepare('SELECT COUNT(*) c FROM api_request_log WHERE key_id=? AND created_at > (NOW() - INTERVAL 1 MINUTE)');
    $st->execute([$key['id']]);
    if ((int)$st->fetch()['c'] >= API_RATE_LIMIT_PER_MINUTE) api_error(429, 'Rate limit exceeded ('.API_RATE_LIMIT_PER_MINUTE.'/minute)');
    portal_pdo()->prepare('INSERT INTO api_request_log(key_id,endpoint,ip_address) VALUES(?,?,?)')->execute([$key['id'], $endpoint, client_ip()]);
    portal_pdo()->prepare('UPDATE api_keys SET last_used_at=NOW() WHERE id=?')->execute([$key['id']]);
    return $key;
}

// ===================== USSD & IVR =====================
// channel_service_code (routing: shortcode -> service_code -> offer_code, per USSD/IVR) and
// agent_queue (live session/queue state) are both small operational tables in HeraProduction/
// HeraTesting — no bounding needed, unlike subscription/audit_log.
function channel_route_activity(string $schema, ?string $type = null): array {
    if (!table_exists($schema, 'channel_service_code')) return [];
    $sql = 'SELECT * FROM channel_service_code';
    $params = [];
    if ($type) { $sql .= ' WHERE type = ?'; $params[] = $type; }
    $sql .= ' ORDER BY shortcode, type';
    $st = pdo($schema)->prepare($sql); $st->execute($params);
    return $st->fetchAll();
}
function agent_queue_snapshot(string $schema): array {
    if (!table_exists($schema, 'agent_queue')) return [];
    return pdo($schema)->query('SELECT * FROM agent_queue ORDER BY id DESC LIMIT 200')->fetchAll();
}
// Transaction activity for a given channel set (USSD, IVR, SMS, ...), reusing the same bounded,
// partition-aware audit_log query the Complaint Investigation report uses.
function channel_activity_today(...$a) { return cached('channel_activity_today:'.md5(serialize($a)), 60, fn() => channel_activity_today_uncached(...$a)); }
function channel_activity_today_uncached(string $schema, array $channels): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return ['total' => 0, 'success' => 0, 'failed' => 0];
    $placeholders = implode(',', array_fill(0, count($channels), '?'));
    $st = pdo($schema)->prepare("SELECT COUNT(*) total, SUM(CASE WHEN ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) ok FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= CURDATE() AND channel IN ($placeholders)");
    $st->execute($channels);
    $r = $st->fetch();
    $total = (int)($r['total'] ?? 0); $ok = (int)($r['ok'] ?? 0);
    return ['total' => $total, 'success' => $ok, 'failed' => $total - $ok];
}

// ===================== Integrations (unified connection registry for external systems) =====================
// One place to register and live-check every external system this platform connects to: SMSC, USSD
// gateway, IVR platform, monitoring/observability endpoints, or anything else. Credentials are
// encrypted at rest (AES-256-CBC, key in app_secrets or APP_ENCRYPTION_KEY) because — unlike a
// stored password — a health check actually has to send this value on the wire.
function app_encryption_key(): string {
    static $key = null;
    if ($key !== null) return $key;
    $env = getenv('APP_ENCRYPTION_KEY');
    if ($env) { $key = hash('sha256', $env, true); return $key; }
    $st = portal_pdo()->prepare('SELECT value FROM app_secrets WHERE name=?');
    $st->execute(['encryption_key']);
    $row = $st->fetch();
    if (!$row) {
        portal_pdo()->prepare('INSERT IGNORE INTO app_secrets(name,value) VALUES(?,?)')->execute(['encryption_key', base64_encode(random_bytes(32))]);
        $st->execute(['encryption_key']); $row = $st->fetch();
    }
    $key = base64_decode($row['value']);
    return $key;
}
function encrypt_secret(string $plain): string {
    if ($plain === '') return '';
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', app_encryption_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}
function decrypt_secret(?string $encoded): string {
    if (!$encoded) return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', app_encryption_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

const INTEGRATION_TYPES = ['smsc', 'ussd', 'ivr', 'monitoring', 'other'];

function list_integrations(?string $type = null): array {
    $sql = 'SELECT * FROM integrations'; $params = [];
    if ($type) { $sql .= ' WHERE service_type=?'; $params[] = $type; }
    $sql .= ' ORDER BY service_type, name';
    $st = portal_pdo()->prepare($sql); $st->execute($params);
    return $st->fetchAll();
}
function get_integration(int $id): ?array {
    $st = portal_pdo()->prepare('SELECT * FROM integrations WHERE id=?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}
function save_integration(array $data, ?int $id = null): int {
    if (!in_array($data['service_type'] ?? '', INTEGRATION_TYPES, true)) throw new RuntimeException('Invalid service type.');
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Name is required.');
    $fields = [
        $data['service_type'], $name, in_array($data['protocol'] ?? '', ['http', 'tcp'], true) ? $data['protocol'] : 'http',
        normalize_value($data['host'] ?? ''), normalize_value($data['port'] ?? null),
        normalize_value($data['base_url'] ?? ''), normalize_value($data['health_check_path'] ?? ''),
        in_array($data['auth_type'] ?? '', ['none', 'basic', 'bearer', 'api_key'], true) ? $data['auth_type'] : 'none',
        in_array($data['status'] ?? '', ['active', 'inactive', 'testing'], true) ? $data['status'] : 'testing',
        normalize_value($data['notes'] ?? ''),
    ];
    $credential = trim((string)($data['auth_credential'] ?? ''));
    $db = portal_pdo();
    if ($id) {
        if ($credential !== '') {
            $db->prepare('UPDATE integrations SET service_type=?,name=?,protocol=?,host=?,port=?,base_url=?,health_check_path=?,auth_type=?,status=?,notes=?,auth_credential_enc=? WHERE id=?')
               ->execute([...$fields, encrypt_secret($credential), $id]);
        } else {
            $db->prepare('UPDATE integrations SET service_type=?,name=?,protocol=?,host=?,port=?,base_url=?,health_check_path=?,auth_type=?,status=?,notes=? WHERE id=?')
               ->execute([...$fields, $id]);
        }
    } else {
        $db->prepare('INSERT INTO integrations(service_type,name,protocol,host,port,base_url,health_check_path,auth_type,status,notes,auth_credential_enc,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([...$fields, $credential !== '' ? encrypt_secret($credential) : null, user()['username'] ?? null]);
        $id = (int)$db->lastInsertId();
    }
    audit('save_integration', null, 'integrations', (string)$id, json_encode(['name' => $name, 'type' => $data['service_type']]));
    return $id;
}
function test_integration(int $id): array {
    $row = get_integration($id) ?: throw new RuntimeException('Integration not found.');
    $start = microtime(true);
    $ok = false; $message = '';
    if ($row['protocol'] === 'tcp') {
        $host = (string)$row['host']; $port = (int)$row['port'];
        if ($host === '' || $port <= 0) { $message = 'Host and port are required for a TCP check.'; }
        else {
            $conn = @fsockopen($host, $port, $errno, $errstr, 5);
            if ($conn) { $ok = true; $message = 'TCP connect succeeded.'; fclose($conn); }
            else { $message = "TCP connect failed: $errstr (errno $errno)"; }
        }
    } else {
        $url = trim((string)$row['base_url']);
        if ($url === '' && $row['host']) $url = 'http://'.$row['host'].($row['port'] ? ':'.$row['port'] : '');
        if (trim((string)$row['health_check_path']) !== '') $url = rtrim($url, '/').'/'.ltrim($row['health_check_path'], '/');
        if ($url === '') { $message = 'Base URL or host is required for an HTTP check.'; }
        elseif (!extension_loaded('curl')) { $message = 'PHP curl extension is not available.'; }
        else {
            $headers = [];
            if ($row['auth_type'] !== 'none' && $row['auth_credential_enc']) {
                $cred = decrypt_secret($row['auth_credential_enc']);
                if ($row['auth_type'] === 'bearer') $headers[] = 'Authorization: Bearer '.$cred;
                elseif ($row['auth_type'] === 'api_key') $headers[] = 'X-Api-Key: '.$cred;
                elseif ($row['auth_type'] === 'basic') $headers[] = 'Authorization: Basic '.base64_encode($cred);
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
            curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err) { $message = "HTTP request failed: $err"; }
            else { $ok = $httpCode >= 200 && $httpCode < 400; $message = "HTTP $httpCode"; }
        }
    }
    $latency = (int)round((microtime(true) - $start) * 1000);
    portal_pdo()->prepare('UPDATE integrations SET last_check_at=NOW(), last_check_ok=?, last_check_latency_ms=?, last_check_message=? WHERE id=?')
        ->execute([$ok ? 1 : 0, $latency, $message, $id]);
    portal_pdo()->prepare('INSERT INTO integration_checks(integration_id,ok,latency_ms,message) VALUES(?,?,?,?)')
        ->execute([$id, $ok ? 1 : 0, $latency, $message]);
    audit('test_integration', null, 'integrations', (string)$id, "$message ({$latency}ms)");
    return ['ok' => $ok, 'latency_ms' => $latency, 'message' => $message];
}
function smsc_activity_today(...$a) { return cached('smsc_activity_today:'.md5(serialize($a)), 60, fn() => smsc_activity_today_uncached(...$a)); }
function smsc_activity_today_uncached(string $schema): array { return channel_activity_today($schema, ['SMS', 'SMSC']); }

function integration_check_history(int $id, int $limit = 20): array {
    $st = portal_pdo()->prepare('SELECT ok, latency_ms, message, checked_at FROM integration_checks WHERE integration_id=? ORDER BY checked_at DESC LIMIT ?');
    $st->bindValue(1, $id, PDO::PARAM_INT); $st->bindValue(2, $limit, PDO::PARAM_INT); $st->execute();
    return $st->fetchAll();
}
function integration_uptime_pct(int $id, int $sinceHours = 24): ?float {
    $st = portal_pdo()->prepare('SELECT COUNT(*) total, SUM(ok) up FROM integration_checks WHERE integration_id=? AND checked_at > (NOW() - INTERVAL ? HOUR)');
    $st->bindValue(1, $id, PDO::PARAM_INT); $st->bindValue(2, $sinceHours, PDO::PARAM_INT); $st->execute();
    $r = $st->fetch();
    if (!$r || (int)$r['total'] === 0) return null;
    return round(((int)$r['up'] / (int)$r['total']) * 100, 1);
}

// ===================== USSD Menu Builder =====================
// A design/staging tool: define and preview a USSD menu tree, export it as JSON. It does NOT push
// configuration to Mobius (or any other gateway) — that needs the gateway's own menu-config API,
// which this app does not have access to. Until that's wired up, this is the source of truth you'd
// hand-enter (or later auto-push) into the real gateway.
function menu_shortcodes(): array {
    return array_column(portal_pdo()->query("SELECT DISTINCT short_code FROM ussd_menu_nodes WHERE short_code NOT LIKE '% [archived %' ORDER BY short_code")->fetchAll(), 'short_code');
}
// ---- The USSD platform at a glance (Short Codes, USSD & IVR, USSD Proxy and the Menu Builder all use these) ----
// Is a short code answering customers right now, and is it in the Short Code register?
function shortcode_state(string $code, ?array $cfg = null, ?array $registry = null): array {
    $cfg = $cfg ?? ussd_proxy_config();
    if ($registry === null) { $registry = []; try { foreach (portal_pdo()->query("SELECT * FROM portal_short_codes WHERE channel_type='USSD'")->fetchAll() as $r) $registry[$r['short_code']] = $r; } catch (Throwable $e) {} }
    $why = $cfg['enabled'] !== '1' ? 'the USSD endpoint is switched off' : ($cfg['mode'] !== 'live' ? 'the USSD endpoint is in Capture mode' : ($cfg['push_enabled'] !== '1' ? 'screens are not being pushed back through Mobius' : ''));
    return ['live' => $why === '', 'why' => $why, 'registered' => $registry[$code] ?? null];
}
// One entry per short code that has a menu: items on / draft and what the Menu check says.
function menu_overview(): array {
    $out = [];
    foreach (menu_shortcodes() as $code) {
        $flat = menu_nodes_flat($code); $h = menu_health($code);
        $out[$code] = ['items' => count($flat), 'active' => count(array_filter($flat, fn($n) => $n['status'] === 'active')), 'draft' => count(array_filter($flat, fn($n) => $n['status'] === 'draft')),
            'errors' => count(array_filter($h, fn($x) => $x['level'] === 'error')), 'warns' => count(array_filter($h, fn($x) => $x['level'] === 'warn'))];
    }
    return $out;
}
// Menus (short code + item) that open a given service flow.
function flow_usage(string $flowKey): array {
    try { $st = portal_pdo()->prepare("SELECT short_code, prompt_text AS label, status FROM ussd_menu_nodes WHERE node_type='flow' AND offer_code=? AND short_code NOT LIKE '% [archived %' ORDER BY short_code, id"); $st->execute([$flowKey]); return $st->fetchAll(); }
    catch (Throwable $e) { return []; }
}
// The catalogue row behind each offer code used by a routing table (the active one when several share a code).
function ussd_route_offers(string $schema, array $codes): array {
    $codes = array_values(array_unique(array_filter(array_map(fn($c) => trim((string)$c), $codes), fn($c) => $c !== ''))); if (!$codes) return [];
    try {
        if (!table_exists($schema, 'vas_offers')) return [];
        $del = column_exists($schema, 'vas_offers', 'deleted_at') ? " AND (deleted_at IS NULL OR deleted_at='')" : '';
        $st = pdo($schema)->prepare('SELECT offer_code, name, one_time_price, status FROM vas_offers WHERE offer_code IN ('.implode(',', array_fill(0, count($codes), '?')).')'.$del.' ORDER BY id'); $st->execute($codes);
    } catch (Throwable $e) { return []; }
    $by = []; foreach ($st->fetchAll() as $o) { $c = (string)$o['offer_code']; if (!isset($by[$c]) || offer_is_active($o['status']) || !offer_is_active($by[$c]['status'])) $by[$c] = $o; }
    return $by;
}
function ussd_route_on($status): bool { return in_array(strtolower(trim((string)$status)), ['1', 'active', 'on', 'true', 'yes'], true); }
// What is wrong with each routing row: [route id => [['level' => 'error'|'warn', 'msg' => …], …]]. Only rows that are switched on can be a problem for customers.
function ussd_route_review(array $routes, array $offers): array {
    $out = []; $add = function ($r, string $lvl, string $msg) use (&$out) { $out[(int)$r['id']][] = ['level' => $lvl, 'msg' => $msg]; };
    $byService = []; foreach ($routes as $r) if (ussd_route_on($r['status']) && trim((string)$r['service_code']) !== '') $byService[$r['type'].'|'.trim((string)$r['service_code'])][] = $r;
    foreach ($routes as $r) {
        $on = ussd_route_on($r['status']); $code = trim((string)$r['offer_code']); if (!$on) continue;
        if ($code === '') $add($r, 'warn', 'No offer code — dialling this does not sell anything.');
        else {
            if (!ctype_digit($code)) $add($r, 'warn', 'The offer code "'.$code.'" is not a plain number — check it for a typo.');
            if (!isset($offers[$code])) $add($r, 'error', 'Offer '.$code.' is not in the catalogue.');
            elseif (!offer_is_active($offers[$code]['status'])) $add($r, 'error', 'Offer '.$code.' ('.$offers[$code]['name'].') is switched off in the catalogue.');
        }
        $same = $byService[$r['type'].'|'.trim((string)$r['service_code'])] ?? [];
        if (count($same) > 1) $add($r, 'warn', 'The service code '.$r['service_code'].' is also used by '.implode(', ', array_map(fn($x) => $x['shortcode'], array_filter($same, fn($x) => (int)$x['id'] !== (int)$r['id']))).'.');
    }
    return $out;
}
// The setup steps of the USSD Proxy page as a checklist: [['state' => ok|warn|off, 'title', 'detail', 'tab'], …].
function ussd_setup_checklist(array $cfg): array {
    $o = []; $add = function (string $state, string $title, string $detail, string $tab) use (&$o) { $o[] = ['state' => $state, 'title' => $title, 'detail' => $detail, 'tab' => $tab]; };
    if ($cfg['enabled'] !== '1') $add('off', 'Endpoint', 'Switched off — Mobius gets "Not found", customers are not served.', 'connect');
    elseif ($cfg['mode'] !== 'live') $add('warn', 'Endpoint', 'On, but in Capture mode: it only records what Mobius sends and answers with a fixed test text.', 'connect');
    else $add('ok', 'Endpoint', 'On and serving the menus.', 'connect');
    $add(trim((string)$cfg['allow_ips']) === '' ? 'warn' : 'ok', 'Who may call it', trim((string)$cfg['allow_ips']) === '' ? 'Anyone with the secret address can call it. Best practice: list the Mobius servers\' addresses.' : 'Only: '.$cfg['allow_ips'], 'connect');
    $mobiusOk = $cfg['push_enabled'] === '1' && $cfg['mobius_user'] !== '' && $cfg['mobius_pass'] !== '';
    $add($mobiusOk ? 'ok' : ($cfg['push_enabled'] === '1' ? 'warn' : 'off'), 'Answering through Mobius', $mobiusOk ? 'Switched on with a saved API user.' : ($cfg['push_enabled'] === '1' ? 'Switched on, but the API user or password is missing.' : 'Switched off — PROXY menus cannot get their screens back to the phone.'), 'connect');
    $pm = $cfg['purchase_mode'];
    if ($pm === 'off') $add('off', 'Buying offers', 'Off — customers are told it is not switched on.', 'buy');
    elseif ($pm !== 'live') $add('warn', 'Buying offers', 'In test mode: nothing is bought, customers are told it was a test.', 'buy');
    elseif (trim((string)$cfg['purchase_url']) === '') $add('warn', 'Buying offers', 'Live, but there is no purchase address.', 'buy');
    elseif ($cfg['purchase_auth'] === '') $add('warn', 'Buying offers', 'Live, but no headers (API key) are saved — Hera will refuse the requests.', 'buy');
    else $add('ok', 'Buying offers', 'Live, with an address and headers saved.', 'buy');
    $sm = $cfg['share_mode'];
    $add($sm === 'live' ? ($cfg['purchase_auth'] === '' ? 'warn' : 'ok') : ($sm === 'test' ? 'warn' : 'off'), 'Shared Bundle (Seddo)', $sm === 'live' ? ($cfg['purchase_auth'] === '' ? 'Live, but the headers saved under Buying are missing.' : 'Live.') : ($sm === 'test' ? 'In test mode: every answer is simulated.' : 'Off.'), 'share');
    return $o;
}
// ---- Creating a whole menu in one step (the Menus page) ----
function menu_normalize_shortcode(string $c): string { $c = preg_replace('/\s+/', '', trim($c)); if ($c !== '' && !str_ends_with($c, '#')) $c .= '#'; return $c; }
function menu_valid_shortcode(string $c): bool { return (bool)preg_match('/^\*\d{1,6}(\*\d{1,6}){0,4}#$/', $c); }
// Validates the short code and the name, adds the typed items as Drafts and writes the menu into the Short Code register (status Pending until it is
// live). Nothing is created unless everything is valid. Returns ['code' => …, 'items' => number of items added].
function menu_create(string $code, string $name, string $items = ''): array {
    $code = menu_normalize_shortcode($code); $name = trim($name);
    if (!menu_valid_shortcode($code)) throw new RuntimeException('A short code looks like *9606*7070# — a *, then numbers (more *numbers if you like), ending with #.');
    if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Give the menu a name (up to 150 letters), for example "Data bundles".');
    $r = portal_pdo()->prepare("SELECT id FROM portal_short_codes WHERE channel_type='USSD' AND short_code=?"); $r->execute([$code]);
    if (in_array($code, menu_shortcodes(), true) || $r->fetch()) throw new RuntimeException($code.' already exists — open it from the list, or pick another short code.');
    $n = trim($items) === '' ? 0 : menu_quick_add($code, null, $items, 'draft');
    save_shortcode(['channel_type' => 'USSD', 'short_code' => $code, 'service_name' => $name, 'provider' => 'Comium', 'status' => 'Pending', 'description' => 'Created from the Menus page']);
    audit('create_menu', null, 'ussd_menu_nodes', $code, json_encode(['name' => $name, 'items' => $n]));
    return ['code' => $code, 'items' => $n];
}
// An Action item (e.g. "Check Balance", key check_balance) is run by the service flow of that name once that flow exists, is on and is Live —
// so a menu that already has the item starts working the moment the flow is finished, with no change to the menu.
function ussd_action_flow_key(array $n): string { return trim(strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', (string)($n['action_key'] ?? ''))), '_'); }
function menu_node(int $id): ?array {
    $st = portal_pdo()->prepare('SELECT * FROM ussd_menu_nodes WHERE id=?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}
function menu_nodes_flat(string $shortCode): array {
    $st = portal_pdo()->prepare('SELECT * FROM ussd_menu_nodes WHERE short_code=? ORDER BY parent_id IS NULL DESC, parent_id, display_order, id');
    $st->execute([$shortCode]);
    return $st->fetchAll();
}
function menu_tree(string $shortCode): array {
    $flat = menu_nodes_flat($shortCode);
    $byParent = [];
    foreach ($flat as $n) $byParent[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    $build = function ($parentId) use (&$build, $byParent) {
        $out = [];
        foreach ($byParent[$parentId] ?? [] as $n) {
            $n['children'] = $build((int)$n['id']);
            $out[] = $n;
        }
        return $out;
    };
    return $build(0);
}
function save_menu_node(array $data, ?int $id = null, bool $snapshot = true): int {
    $shortCode = trim((string)($data['short_code'] ?? ''));
    $prompt = trim((string)($data['prompt_text'] ?? ''));
    if ($shortCode === '' || $prompt === '') throw new RuntimeException('Short code and prompt text are required.');
    if (mb_strlen($prompt) > 120) throw new RuntimeException('The label is at most 120 characters — it is a line in a menu. Put longer wording in "Screen text".');
    $parentId = trim((string)($data['parent_id'] ?? '')) !== '' ? (int)$data['parent_id'] : null;
    if ($id && $parentId === $id) throw new RuntimeException('An item cannot be its own parent.');
    $type = in_array($data['node_type'] ?? '', ['menu','offer','action','end','catalog','recipient','quiz','sharedbundle','flow'], true) ? $data['node_type'] : 'menu';
    $body = in_array($type, ['menu', 'catalog', 'end'], true) ? trim((string)($data['body_text'] ?? '')) : '';
    if (mb_strlen($body) > 400) throw new RuntimeException('Screen text is at most 400 characters (a phone screen holds about 180).');
    $fields = [$parentId, $shortCode, (int)($data['display_order'] ?? 0), $prompt, $type,
        normalize_value($data['offer_code'] ?? ''), normalize_value($data['action_key'] ?? ''),
        in_array($data['status'] ?? '', ['active','inactive','draft'], true) ? $data['status'] : 'draft',
        in_array($type, ['catalog', 'sharedbundle'], true) ? menu_catalog_filter($data['catalog_filter'] ?? '') : null,
        $body !== '' ? $body : null];
    if ($type === 'sharedbundle' && $fields[8] === '') $fields[8] = 'Seddo';
    if ($type === 'catalog' && $fields[8] === '') throw new RuntimeException('A catalogue list needs at least one sub-category.');
    if ($type === 'offer' && trim((string)$fields[5]) === '') throw new RuntimeException('Choose the offer this item sells.');
    if ($type === 'flow') {
        $fields[5] = trim((string)($data['flow_key'] ?? $data['offer_code'] ?? '')); flow_tables();
        if ($fields[5] === '' || !flow_get($fields[5])) throw new RuntimeException('Choose which service flow this item opens (create it on the Service Flows page first).');
    }
    if ($type === 'quiz') {
        $fields[5] = trim((string)($data['quiz_key'] ?? $data['offer_code'] ?? ''));
        ussd_quiz_tables(); $ex = portal_pdo()->prepare('SELECT 1 FROM ussd_quizzes WHERE quiz_key=?'); $ex->execute([$fields[5]]);
        if ($fields[5] === '' || !$ex->fetchColumn()) throw new RuntimeException('Choose which quiz this item plays (create it on the USSD Quiz page first).');
    }
    $db = portal_pdo();
    if ($id) {
        $db->prepare('UPDATE ussd_menu_nodes SET parent_id=?,short_code=?,display_order=?,prompt_text=?,node_type=?,offer_code=?,action_key=?,status=?,catalog_filter=?,body_text=? WHERE id=?')->execute([...$fields, $id]);
    } else {
        if ($fields[2] === 0) { $mx = $db->prepare('SELECT COALESCE(MAX(display_order),0)+1 FROM ussd_menu_nodes WHERE short_code=? AND parent_id <=> ?'); $mx->execute([$shortCode, $parentId]); $fields[2] = (int)$mx->fetchColumn(); }
        $db->prepare('INSERT INTO ussd_menu_nodes(parent_id,short_code,display_order,prompt_text,node_type,offer_code,action_key,status,catalog_filter,body_text,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([...$fields, user()['username'] ?? null]);
        $id = (int)$db->lastInsertId();
    }
    audit('save_menu_node', null, 'ussd_menu_nodes', (string)$id, json_encode(['short_code'=>$shortCode,'prompt'=>$prompt]));
    if ($snapshot) menu_snapshot($shortCode, 'Saved "'.mb_substr($prompt, 0, 60).'"');
    return $id;
}
// ---- Menu building tools: history, reordering, copying, quick add, health check ----
function menu_versions_table(): void {
    static $done = false; if ($done) return;
    portal_pdo()->exec("CREATE TABLE IF NOT EXISTS ussd_menu_versions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, short_code VARCHAR(80) NOT NULL, snapshot LONGTEXT NOT NULL, reason VARCHAR(160) NULL, nodes INT NOT NULL DEFAULT 0,
        created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_code_time (short_code, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
// The menu of one short code as nested nodes — the same shape Import / Export JSON use.
function menu_export_tree(string $code): array {
    $by = []; foreach (menu_nodes_flat($code) as $n) $by[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    $build = function (int $pid) use (&$build, $by): array {
        $out = [];
        foreach ($by[$pid] ?? [] as $n) {
            $x = ['prompt_text' => $n['prompt_text'], 'node_type' => $n['node_type'], 'status' => $n['status'], 'display_order' => (int)$n['display_order']];
            foreach (['offer_code', 'action_key', 'catalog_filter', 'body_text'] as $k) if (isset($n[$k]) && $n[$k] !== '') $x[$k] = $n[$k];
            $c = $build((int)$n['id']); if ($c) $x['children'] = $c; $out[] = $x;
        }
        return $out;
    };
    return $build(0);
}
// Every change keeps a copy of the whole menu, so any state can be brought back (last 60 per short code).
function menu_snapshot(string $code, string $reason): void {
    try {
        menu_versions_table(); $tree = menu_export_tree($code); $n = count(menu_nodes_flat($code)); $db = portal_pdo();
        $db->prepare('INSERT INTO ussd_menu_versions(short_code,snapshot,reason,nodes,created_by) VALUES(?,?,?,?,?)')->execute([$code, json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), mb_substr($reason, 0, 160), $n, user()['username'] ?? null]);
        $db->prepare('DELETE FROM ussd_menu_versions WHERE short_code=? AND id < (SELECT m FROM (SELECT MIN(id) m FROM (SELECT id FROM ussd_menu_versions WHERE short_code=? ORDER BY id DESC LIMIT 60) t) x)')->execute([$code, $code]);
    } catch (Throwable $e) { error_log('menu snapshot: '.$e->getMessage()); }
}
function menu_restore_version(int $vid): string {
    menu_versions_table(); $st = portal_pdo()->prepare('SELECT * FROM ussd_menu_versions WHERE id=?'); $st->execute([$vid]); $v = $st->fetch();
    if (!$v) throw new RuntimeException('That saved version is gone.');
    import_menu_json($v['short_code'], $v['snapshot']);
    audit('restore_menu', null, 'ussd_menu_versions', (string)$vid, $v['short_code']);
    return $v['short_code'];
}
function menu_move_node(int $id, string $dir): string {
    $n = menu_node($id) ?: throw new RuntimeException('That item is gone.');
    $db = portal_pdo(); $st = $db->prepare('SELECT id FROM ussd_menu_nodes WHERE short_code=? AND parent_id <=> ? ORDER BY display_order, id'); $st->execute([$n['short_code'], $n['parent_id']]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id')); $i = array_search((int)$id, $ids, true);
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($i !== false && isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; }
    $u = $db->prepare('UPDATE ussd_menu_nodes SET display_order=? WHERE id=?'); foreach ($ids as $k => $nid) $u->execute([$k + 1, $nid]);
    menu_snapshot($n['short_code'], 'Moved "'.mb_substr($n['prompt_text'], 0, 50).'" '.($dir === 'up' ? 'up' : 'down'));
    return $n['short_code'];
}
function menu_set_status(int $id, string $status, bool $branch = false): string {
    if (!in_array($status, ['active', 'inactive', 'draft'], true)) throw new RuntimeException('Unknown status.');
    $n = menu_node($id) ?: throw new RuntimeException('That item is gone.'); $db = portal_pdo(); $ids = [$id];
    if ($branch) { $by = []; foreach (menu_nodes_flat($n['short_code']) as $x) $by[$x['parent_id'] === null ? 0 : (int)$x['parent_id']][] = (int)$x['id']; $stack = [$id]; while ($stack) { $c = array_pop($stack); foreach ($by[$c] ?? [] as $k) { $ids[] = $k; $stack[] = $k; } } }
    $db->prepare('UPDATE ussd_menu_nodes SET status=? WHERE id IN ('.implode(',', array_map('intval', $ids)).')')->execute([$status]);
    menu_snapshot($n['short_code'], '"'.mb_substr($n['prompt_text'], 0, 50).'" set to '.$status.($branch ? ' (with everything under it)' : ''));
    return $n['short_code'];
}
function menu_activate_drafts(string $code): int {
    $st = portal_pdo()->prepare("UPDATE ussd_menu_nodes SET status='active' WHERE short_code=? AND status='draft'"); $st->execute([$code]); $n = $st->rowCount();
    if ($n) menu_snapshot($code, 'Activated '.$n.' draft items');
    return $n;
}
// Copies an item and everything under it, next to the original, as Drafts.
function menu_duplicate_node(int $id): string {
    $n = menu_node($id) ?: throw new RuntimeException('That item is gone.'); $db = portal_pdo();
    $by = []; foreach (menu_nodes_flat($n['short_code']) as $x) $by[$x['parent_id'] === null ? 0 : (int)$x['parent_id']][] = $x;
    $count = 0;
    $copy = function (array $src, ?int $parent, bool $top) use (&$copy, $by, $db, &$count): void {
        if (++$count > 150) throw new RuntimeException('That branch is too big to copy in one go (150 items).');
        $mx = $db->prepare('SELECT COALESCE(MAX(display_order),0)+1 FROM ussd_menu_nodes WHERE short_code=? AND parent_id <=> ?'); $mx->execute([$src['short_code'], $parent]);
        $db->prepare("INSERT INTO ussd_menu_nodes(parent_id,short_code,display_order,prompt_text,node_type,offer_code,action_key,status,catalog_filter,body_text,created_by) VALUES(?,?,?,?,?,?,?,'draft',?,?,?)")
            ->execute([$parent, $src['short_code'], $top ? (int)$mx->fetchColumn() : (int)$src['display_order'], $top ? mb_substr($src['prompt_text'].' (copy)', 0, 120) : $src['prompt_text'], $src['node_type'], $src['offer_code'], $src['action_key'], $src['catalog_filter'] ?? null, $src['body_text'] ?? null, user()['username'] ?? null]);
        $nid = (int)$db->lastInsertId();
        foreach ($by[(int)$src['id']] ?? [] as $c) $copy($c, $nid, false);
    };
    $copy($n, $n['parent_id'] === null ? null : (int)$n['parent_id'], true);
    audit('duplicate_menu_node', null, 'ussd_menu_nodes', (string)$id, $n['short_code']);
    menu_snapshot($n['short_code'], 'Copied "'.mb_substr($n['prompt_text'], 0, 50).'"');
    return $n['short_code'];
}
// One item per line:  Label | type | extra    (type and extra are optional; the default is a submenu)
//   Label | catalog | Sub-category A; Sub-category B     offers from the catalogue, live
//   Label | offer   | 40015                               one offer
//   Label | end     | text shown on its own screen
//   Label | quiz    | quiz key          Label | shared | Seddo          Label | other   (buy for another number)
function menu_quick_add(string $code, ?int $parent, string $lines, string $status = 'draft'): int {
    $code = trim($code); if ($code === '') throw new RuntimeException('Pick a short code first.');
    $map = ['menu' => 'menu', 'submenu' => 'menu', 'catalog' => 'catalog', 'list' => 'catalog', 'offer' => 'offer', 'end' => 'end', 'quiz' => 'quiz', 'shared' => 'sharedbundle', 'sharedbundle' => 'sharedbundle', 'other' => 'recipient', 'recipient' => 'recipient', 'flow' => 'flow'];
    $rows = [];
    foreach (preg_split('/\R/', $lines) as $n => $line) {
        $line = trim($line); if ($line === '' || $line[0] === '#') continue;
        $p = array_map('trim', explode('|', $line, 3)); $type = strtolower($p[1] ?? 'menu'); if ($type === '') $type = 'menu';
        if (!isset($map[$type])) throw new RuntimeException('Line '.($n + 1).': "'.$p[1].'" is not a type. Use menu, catalog, offer, end, quiz, flow, shared or other.');
        $t = $map[$type]; $extra = $p[2] ?? '';
        $d = ['short_code' => $code, 'parent_id' => $parent, 'prompt_text' => $p[0], 'node_type' => $t, 'status' => $status];
        if ($t === 'catalog') { if ($extra === '') throw new RuntimeException('Line '.($n + 1).': a catalogue list needs its sub-categories after the second |, separated by ;'); $d['catalog_filter'] = str_replace(';', "\n", $extra); }
        if ($t === 'sharedbundle') $d['catalog_filter'] = str_replace(';', "\n", $extra !== '' ? $extra : 'Seddo');
        if ($t === 'offer') { if ($extra === '') throw new RuntimeException('Line '.($n + 1).': an offer item needs the offer code after the second |'); $d['offer_code'] = $extra; }
        if ($t === 'quiz') $d['quiz_key'] = $extra;
        if ($t === 'flow') $d['flow_key'] = $extra;
        if ($t === 'end') $d['body_text'] = $extra;
        $rows[] = [$n + 1, $d];
    }
    if (!$rows) throw new RuntimeException('Nothing to add — write one item per line.');
    if (count($rows) > 60) throw new RuntimeException('At most 60 items at a time.');
    flow_tables(); menu_versions_table(); // creating a table inside a transaction would end it (MySQL commits on any CREATE TABLE)
    $db = portal_pdo(); $db->beginTransaction();
    try { foreach ($rows as [$ln, $d]) { try { save_menu_node($d, null, false); } catch (RuntimeException $e) { throw new RuntimeException('Line '.$ln.': '.$e->getMessage()); } } $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); throw $e; }
    menu_snapshot($code, 'Quick add: '.count($rows).' items');
    return count($rows);
}
function menu_copy_to(string $from, string $to, bool $confirmReplace): int {
    $to = trim($to); if ($to === '' || $to === $from) throw new RuntimeException('Enter a different short code to copy to.');
    if (menu_nodes_flat($to) && !$confirmReplace) throw new RuntimeException($to.' already has a menu. Tick the box to replace it (the old one is kept, inactive, as an archive).');
    $tree = menu_export_tree($from); if (!$tree) throw new RuntimeException('There is nothing to copy.');
    $draft = function (array $nodes) use (&$draft): array { foreach ($nodes as &$n) { $n['status'] = 'draft'; if (!empty($n['children'])) $n['children'] = $draft($n['children']); } return $nodes; };
    $r = import_menu_json($to, json_encode($draft($tree)));
    audit('copy_menu', null, 'ussd_menu_nodes', null, $from.' -> '.$to);
    return $r['nodes'];
}
// A check-up of one short code: what would confuse customers or fail on a phone. [level error|warn|info, node id, message]
function menu_health(string $code): array {
    $out = []; $add = function (string $lvl, ?int $id, string $msg) use (&$out) { $out[] = ['level' => $lvl, 'node' => $id, 'msg' => $msg]; };
    $flat = menu_nodes_flat($code);
    if (!$flat) return [['level' => 'info', 'node' => null, 'msg' => 'This short code has no items yet — add the first one, or use Quick add.']];
    $kids = []; foreach ($flat as $n) $kids[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    $isActive = fn($n) => $n['status'] === 'active';
    $drafts = count(array_filter($flat, fn($n) => $n['status'] === 'draft')); $offs = count(array_filter($flat, fn($n) => $n['status'] === 'inactive'));
    if (!array_filter($kids[0] ?? [], $isActive)) $add('error', null, 'Nothing is Active, so customers hear "No menu is set up" for this code.');
    if ($drafts) $add('warn', null, $drafts.' item'.($drafts > 1 ? 's are' : ' is').' still Draft — customers do not see '.($drafts > 1 ? 'them' : 'it').' until set Active (use "Activate all drafts").');
    if ($offs) $add('info', null, $offs.' item'.($offs > 1 ? 's are' : ' is').' switched off.');
    $cfg = ussd_proxy_config();
    if (!($cfg['enabled'] === '1' && $cfg['mode'] === 'live' && $cfg['push_enabled'] === '1')) $add('warn', null, 'The USSD Proxy is not fully live (endpoint on, Live mode, answering through Mobius) — phones will not get this menu yet.');
    $walked = 0; $siblingsSeen = [];
    $walk = function (array $n) use (&$walk, &$add, $kids, $isActive, &$walked, $code, $flat) {
        if (++$walked > 200) return;
        $id = (int)$n['id']; $label = '"'.mb_substr($n['prompt_text'], 0, 40).'"'; $t = $n['node_type'];
        $live = array_values(array_filter($kids[$id] ?? [], $isActive));
        if (mb_strlen($n['prompt_text']) > 45) $add('warn', $id, $label.' is a long line for a menu ('.mb_strlen($n['prompt_text']).' characters).');
        if ($t === 'menu') {
            if (!$live) $add('error', $id, $label.' is a submenu with nothing Active inside — customers would see an empty menu.');
            else {
                $len = mb_strlen(trim((string)($n['body_text'] ?? '')) !== '' ? $n['body_text'] : $n['prompt_text']) + 8; foreach ($live as $i => $c) $len += mb_strlen(($i + 1).'. '.$c['prompt_text']) + 1;
                if ($len > USSD_MAX_CHARS) $add('error', $id, $label.' is too long for one phone screen ('.$len.' characters, at most '.USSD_MAX_CHARS.') — move some items into a submenu.');
            }
        }
        if ($t === 'catalog') {
            $offers = ussd_catalog_offers($n); $subs = str_replace("\n", ', ', (string)$n['catalog_filter']);
            if (!$offers) $add('error', $id, $label.' lists offers from ['.$subs.'] but none are Active in the '.USSD_OFFER_SCHEMA.' catalogue — customers see "No offers are available right now".');
        }
        if ($t === 'offer') { if (!ussd_offer_lookup(trim((string)$n['offer_code']))) $add('error', $id, $label.' sells offer '.$n['offer_code'].', which is missing or not Active in '.USSD_OFFER_SCHEMA.' — customers see "not available".'); }
        if ($t === 'quiz') {
            $info = ussd_quiz_info(trim((string)$n['offer_code']));
            if (!$info) $add('error', $id, $label.' plays quiz "'.$n['offer_code'].'", which does not exist or is switched off.');
            else { $q = ussd_quiz_questions($info['quiz_key'], 'health', 0, 99); if (count($q) < (int)$info['per_game']) $add('warn', $id, $label.': the quiz has only '.count($q).' active questions but a game asks '.(int)$info['per_game'].'.'); }
        }
        if ($t === 'sharedbundle') {
            if (!ussd_catalog_offers($n)) $add('error', $id, $label.': no Active offer in ['.str_replace("\n", ', ', (string)$n['catalog_filter']).'] — customers cannot buy.');
            $c2 = ussd_proxy_config(); if ($c2['share_mode'] !== 'live') $add('info', $id, $label.': Shared Bundle is in '.strtoupper($c2['share_mode']).' mode — '.($c2['share_mode'] === 'test' ? 'answers are simulated, nothing is bought.' : 'it is switched off.'));
        }
        if ($t === 'flow') {
            $fl = flow_get(trim((string)$n['offer_code']));
            if (!$fl) $add('error', $id, $label.' opens the service flow "'.$n['offer_code'].'", which does not exist.');
            elseif ($fl['status'] !== 'active') $add('error', $id, $label.': the service flow "'.$n['offer_code'].'" is switched off.');
            else {
                $fv = flow_validate($fl['def']); $fe = array_values(array_filter($fv, fn($x) => $x['level'] === 'error')); $fw = array_filter($fv, fn($x) => $x['level'] === 'warn');
                if ($fe) $add('error', $id, $label.': the flow has '.count($fe).' thing(s) to fix — '.$fe[0]['msg']);
                if ($fw) $add('warn', $id, $label.': the flow has '.count($fw).' thing(s) to look at on the Service Flows page.');
                if ($fl['mode'] !== 'live') $add('info', $id, $label.': the flow is in TEST mode — every call is simulated ('.$fl['test_outcome'].'), nothing is sent.');
            }
        }
        if ($t === 'recipient') {
            $actKids = []; foreach ($flat as $x) if ($x['status'] === 'active') $actKids[$x['parent_id'] === null ? 0 : (int)$x['parent_id']][] = $x;
            $top = $actKids[0] ?? []; if (count($top) === 1 && $top[0]['node_type'] === 'menu') $top = $actKids[(int)$top[0]['id']] ?? [];
            $memo = []; $any = false; foreach ($top as $c) if (ussd_allowed_for_other($c, $actKids, 'ussd_offer_lookup', 'ussd_catalog_offers', $memo)) { $any = true; break; }
            if (!$any) $add('error', $id, $label.': nothing in this menu can be bought for another number (offers need an "other" code in the catalogue) — customers would see an empty list.');
        }
        if ($t === 'action') {
            $ak = ussd_action_flow_key($n); $afl = $ak !== '' ? flow_get($ak) : null;
            if (!$afl) $add('warn', $id, $label.' is an Action ('.$n['action_key'].') that is not connected to anything yet — customers are told it is not available. To connect it, create a service flow called "'.($ak ?: 'the action name').'" on Service Flows (for a balance there is a starter: "Check balance").');
            elseif ($afl['status'] !== 'active') $add('warn', $id, $label.' is connected to the service flow "'.$ak.'", which is switched off — customers are told it is not available.');
            elseif ($afl['mode'] !== 'live') $add('warn', $id, $label.' is connected to the service flow "'.$ak.'", which is still in Test mode — customers are told it is not available until the flow is set to Live.');
            else { $fe = array_filter(flow_validate($afl['def']), fn($x) => $x['level'] === 'error'); if ($fe) $add('error', $id, $label.': the service flow "'.$ak.'" has '.count($fe).' thing(s) to fix — '.array_values($fe)[0]['msg']); }
        }
        if ($t === 'end' && trim((string)($n['body_text'] ?? '')) === '' && mb_strlen($n['prompt_text']) > 60) $add('info', $id, $label.' shows its whole label as its screen; use "Screen text" for a longer message.');
        $seen = []; foreach ($live as $c) { $k = mb_strtolower($c['prompt_text']); if (isset($seen[$k])) $add('info', (int)$c['id'], 'Two items under '.$label.' are both called "'.$c['prompt_text'].'".'); $seen[$k] = 1; }
        foreach ($live as $c) $walk($c);
    };
    foreach (array_filter($kids[0] ?? [], $isActive) as $r) $walk($r);
    $rank = ['error' => 0, 'warn' => 1, 'info' => 2]; usort($out, fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
    return $out;
}
// The sub-categories a "catalogue list" node shows, one per line (a list, or text with line breaks or | between them).
function menu_catalog_filter($v): string {
    $parts = is_array($v) ? $v : preg_split('/[\r\n|]+/', (string)$v);
    $out = [];
    foreach ($parts as $p) { $p = trim((string)$p); if ($p === '') continue; if (mb_strlen($p) > 80) throw new RuntimeException('A sub-category name is at most 80 characters.'); $out[$p] = $p; }
    if (count($out) > 12) throw new RuntimeException('At most 12 sub-categories per list.');
    return implode("\n", $out);
}
// Replaces the menu of one short code with the tree in $json (the same shape "Export JSON" produces: a list of nodes,
// each with prompt_text, node_type, offer_code, action_key, status, catalog_filter and children). Nothing is deleted:
// the old nodes are kept under "<short code> [archived …]", inactive, and no longer shown or served.
function import_menu_json(string $shortCode, string $json): array {
    $shortCode = trim($shortCode); if ($shortCode === '' || mb_strlen($shortCode) > 40) throw new RuntimeException('Enter the short code (up to 40 characters).');
    $d = json_decode($json, true); if (!is_array($d)) throw new RuntimeException('That is not valid JSON.');
    $tree = isset($d['menu']) && is_array($d['menu']) ? $d['menu'] : $d;
    if (!$tree || !array_is_list($tree)) throw new RuntimeException('Expected a list of menu nodes (or an export with a "menu" list).');
    $flat = []; $count = 0;
    $walk = function (array $nodes, ?int $parent, int $depth) use (&$walk, &$flat, &$count) {
        if ($depth > 5) throw new RuntimeException('Menus can be at most 5 levels deep.');
        foreach (array_values($nodes) as $i => $n) {
            if (!is_array($n)) throw new RuntimeException('Every node must be an object.');
            if (++$count > 150) throw new RuntimeException('At most 150 nodes per import.');
            $prompt = trim((string)($n['prompt_text'] ?? '')); if ($prompt === '' || mb_strlen($prompt) > 300) throw new RuntimeException('Every node needs prompt_text (up to 300 characters).');
            $type = (string)($n['node_type'] ?? 'menu'); if (!in_array($type, ['menu', 'offer', 'action', 'end', 'catalog', 'recipient', 'quiz', 'sharedbundle', 'flow'], true)) throw new RuntimeException('"'.$type.'" is not a node type.');
            $status = (string)($n['status'] ?? 'draft'); if (!in_array($status, ['active', 'inactive', 'draft'], true)) throw new RuntimeException('"'.$status.'" is not a status.');
            $filter = in_array($type, ['catalog', 'sharedbundle'], true) ? menu_catalog_filter($n['catalog_filter'] ?? '') : null;
            if ($type === 'sharedbundle' && $filter === '') $filter = 'Seddo';
            if ($type === 'catalog' && $filter === '') throw new RuntimeException('"'.$prompt.'": a catalogue list needs catalog_filter (its sub-categories).');
            $bodyTxt = in_array($type, ['menu', 'catalog', 'end'], true) ? trim((string)($n['body_text'] ?? '')) : '';
            $key = count($flat); $flat[$key] = ['parent' => $parent, 'order' => (int)($n['display_order'] ?? ($i + 1)), 'prompt' => $prompt, 'type' => $type, 'body' => $bodyTxt !== '' ? mb_substr($bodyTxt, 0, 400) : null,
                'offer' => mb_substr(trim((string)($n['offer_code'] ?? '')), 0, 80) ?: null, 'action' => mb_substr(trim((string)($n['action_key'] ?? '')), 0, 80) ?: null, 'status' => $status, 'filter' => $filter];
            if (!empty($n['children'])) { if (!is_array($n['children'])) throw new RuntimeException('children must be a list.'); $walk($n['children'], $key, $depth + 1); }
        }
    };
    $walk($tree, null, 1);
    flow_tables(); menu_versions_table();
    $db = portal_pdo(); $db->beginTransaction();
    try {
        $archived = $db->prepare("UPDATE ussd_menu_nodes SET short_code=CONCAT(short_code,' [archived ',DATE_FORMAT(NOW(),'%m-%d %H:%i'),']'), status='inactive' WHERE short_code=?");
        $archived->execute([$shortCode]); $old = $archived->rowCount();
        $ins = $db->prepare('INSERT INTO ussd_menu_nodes(parent_id,short_code,display_order,prompt_text,node_type,offer_code,action_key,status,catalog_filter,body_text,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $ids = [];
        foreach ($flat as $k => $n) {
            $ins->execute([$n['parent'] === null ? null : $ids[$n['parent']], $shortCode, $n['order'], $n['prompt'], $n['type'], $n['offer'], $n['action'], $n['status'], $n['filter'], $n['body'], user()['username'] ?? null]);
            $ids[$k] = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit('import_menu', null, 'ussd_menu_nodes', null, json_encode(['short_code' => $shortCode, 'nodes' => count($flat), 'archived' => $old]));
    menu_snapshot($shortCode, 'Imported '.count($flat).' items');
    return ['nodes' => count($flat), 'archived' => $old];
}
// Renders a simple text simulation of walking the menu from its root nodes — what a subscriber
// would actually see on their phone, useful for reviewing the flow without a live gateway.
function render_menu_preview(array $tree, int $depth = 0): string {
    $out = '';
    $i = 1;
    foreach ($tree as $node) {
        $indent = str_repeat('  ', $depth);
        $suffix = $node['node_type'] === 'offer' ? ' → purchase '.e($node['offer_code'])
            : ($node['node_type'] === 'action' ? ' → '.e($node['action_key'])
            : ($node['node_type'] === 'flow' ? ' → service flow '.e($node['offer_code'])
            : ($node['node_type'] === 'sharedbundle' ? ' → Shared Bundle service (offers: '.e(str_replace("\n", ', ', (string)($node['catalog_filter'] ?? ''))).')'
            : ($node['node_type'] === 'quiz' ? ' → quiz game '.e($node['offer_code'])
            : ($node['node_type'] === 'recipient' ? ' → asks for another number, then shows the main menu'
            : ($node['node_type'] === 'end' ? ' → END'
            : ($node['node_type'] === 'catalog' ? ' → live list: '.e(str_replace("\n", ', ', (string)($node['catalog_filter'] ?? ''))) : '')))))));
        $out .= $indent.($depth===0 ? $i.'. ' : '- ').e($node['prompt_text']).$suffix."\n";
        if ($node['children']) $out .= render_menu_preview($node['children'], $depth + 1);
        $i++;
    }
    return $out;
}

// ===================== USSD screen engine =====================
// Turns the Menu Builder's tree into the screens a subscriber sees. It is deliberately independent of how the
// request arrives (the simulator now, the Mobius PROXY endpoint later): give it the short code and the replies
// typed so far, get back the next screen. Stateless — the "session" is just the list of replies — so any
// replica can serve any step.
const USSD_MAX_CHARS = 182;
// $statuses: which node states are served. Live traffic should use ['active']; the simulator also shows drafts.
const USSD_PAGE_SIZE = 5; // offers per screen in a catalogue list; "6. More" shows the next ones
// $hooks (for tests): 'offer' => fn(code): ?row, 'catalog' => fn(node): list of offer rows.
// Can this menu item lead to something that may be bought for ANOTHER number? Only offers that have an "other" code qualify
// (that is what Hera needs for it), so under "Buy for other" a menu or list that holds none of them is not shown at all.
// $kids: the Active items grouped by parent id (0 = top). $memo caches answers for one walk.
function ussd_allowed_for_other(array $n, array $kids, callable $offerLookup, callable $catalogLookup, array &$memo): bool {
    $key = (string)($n['id'] ?? ''); if ($key !== '' && isset($memo[$key])) return $memo[$key];
    $has = fn($o) => !array_key_exists('offer_code_for_other', $o) || trim((string)$o['offer_code_for_other']) !== '';
    $r = false;
    switch ($n['node_type'] ?? '') {
        case 'offer': $o = $offerLookup(trim((string)$n['offer_code'])); $r = $o && $has($o); break;
        case 'catalog': foreach ($catalogLookup($n) as $o) if ($has($o)) { $r = true; break; } break;
        case 'menu': foreach ($kids[(int)$n['id']] ?? [] as $c) if (ussd_allowed_for_other($c, $kids, $offerLookup, $catalogLookup, $memo)) { $r = true; break; } break;
    }
    if ($key !== '') $memo[$key] = $r;
    return $r;
}
// What a flow gets from the menu engine: the caller, how to find outcomes already stored for this session, how to look things up.
// $hooks['flow_sim'] = success|lowbal|fail makes every call and lookup simulated (the Simulator); $hooks['flow_hooks'] overrides (tests).
function ussd_flow_hooks(array $fl, array $hooks, string $shortCode): array {
    if (!empty($hooks['flow_hooks'])) return $hooks['flow_hooks'] + ['msisdn' => $hooks['msisdn'] ?? ''];
    $h = ['msisdn' => (string)($hooks['msisdn'] ?? '')];
    if (!empty($hooks['flow_sim'])) return $h + ['call' => flow_sim_call_hook((string)$hooks['flow_sim']), 'lookup' => 'flow_sim_lookup'];
    $seed = (string)($hooks['seed'] ?? ''); $ctx = (array)($hooks['ctx'] ?? []);
    return $h + ['call' => flow_stored_hook($fl['flow_key'], $seed), 'lookup' => flow_lookup_hook($fl, $ctx, $seed, $shortCode)];
}
// Direct codes: dialling "<short code>*2*1#" is the same as dialling the short code and then typing 2 and 1. Given the string that
// was dialled and the short codes that have a menu, returns [short code, [the digits to type for the customer]] — or null when
// the string is not one of our codes. An exact short code wins; otherwise the longest short code it starts with.
function ussd_resolve_dialled(string $dialled, array $known): ?array {
    $d = trim($dialled); if ($d === '') return null;
    if (in_array($d, $known, true)) return [$d, []];
    $bare = rtrim($d, '#'); $best = null;
    foreach ($known as $k) { $kb = rtrim((string)$k, '#'); if ($kb !== '' && str_starts_with($bare, $kb.'*') && ($best === null || strlen($kb) > strlen(rtrim($best, '#')))) $best = (string)$k; }
    if ($best === null) return null;
    $rest = substr($bare, strlen(rtrim($best, '#')) + 1); $extras = array_values(array_filter(explode('*', $rest), fn($x) => $x !== ''));
    foreach ($extras as $x) if (!ctype_digit($x)) return null; // only digits are shortcuts
    return [$best, array_slice($extras, 0, 8)];
}
// The direct code of every fixed item of a menu, e.g. [id => "*9606*9090*2*1#"], numbered the way customers see them (Active items only).
function menu_direct_codes(string $code): array {
    $kids = []; foreach (menu_nodes_flat($code) as $n) if ($n['status'] === 'active') $kids[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    $roots = $kids[0] ?? []; $top = (count($roots) === 1 && $roots[0]['node_type'] === 'menu' && !empty($kids[(int)$roots[0]['id']])) ? $kids[(int)$roots[0]['id']] : $roots;
    $out = []; $base = rtrim($code, '#');
    $walk = function (array $nodes, array $path) use (&$walk, &$out, $kids, $base) {
        foreach (array_values($nodes) as $i => $n) { $p = array_merge($path, [$i + 1]); $out[(int)$n['id']] = $base.'*'.implode('*', $p).'#'; if ($n['node_type'] === 'menu' && !empty($kids[(int)$n['id']]) && count($p) < 6) $walk($kids[(int)$n['id']], $p); }
    };
    $walk($top, []); return $out;
}
// A number as a customer reads it on a phone screen: without the 220 country code.
function ussd_local_number(string $n): string {
    $d = preg_replace('/\D+/', '', $n);
    return (str_starts_with($d, '220') && strlen($d) >= 10) ? substr($d, 3) : $d;
}
// A number typed for "Buy for another number": 7 or 9 digits get the country code, 220… is kept; anything else is refused.
function ussd_normalize_msisdn(string $s): ?string {
    $d = preg_replace('/\D+/', '', $s);
    if (str_starts_with($d, '220') && in_array(strlen($d), [10, 12], true)) return $d;
    if (in_array(strlen($d), [7, 9], true)) return '220'.$d;
    return null;
}
// $hooks (for tests): 'offer' => fn(code): ?row, 'catalog' => fn(node): list of offer rows.
function ussd_screen(string $shortCode, array $replies, array $statuses = ['active'], ?array $nodes = null, array $hooks = []): array {
    $nodes = $nodes ?? menu_nodes_flat($shortCode);
    $offerLookup = $hooks['offer'] ?? 'ussd_offer_lookup'; $catalogLookup = $hooks['catalog'] ?? 'ussd_catalog_offers';
    $nodes = array_values(array_filter($nodes, fn($n) => in_array($n['status'], $statuses, true)));
    $kids = [];
    foreach ($nodes as $n) $kids[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    if (!$nodes) return ussd_result('No menu is set up for '.$shortCode.' yet.', true, [], 'empty');
    // A single root "menu" node acts as the welcome screen (its text is the header, its children the options);
    // otherwise the root nodes themselves are the options under a plain header.
    $roots = $kids[0] ?? [];
    $cur = (count($roots) === 1 && $roots[0]['node_type'] === 'menu' && !empty($kids[(int)$roots[0]['id']])) ? $roots[0] : null;
    // A short code that is nothing but one service (a single flow or action item, e.g. a code that only checks the balance) opens it straight away
    // instead of showing a list with one line — the same as the customer pressing 1.
    if (!$cur && count($roots) === 1 && in_array($roots[0]['node_type'], ['flow', 'action'], true)) array_unshift($replies, '1'); // every rebuild of the screen starts from it
    $trail = [$cur]; $path = []; $note = null; $page = 0;
    $recipient = null; $recipientRaw = ''; // the other number, once typed under a "Buy for another number" item (normalised, and as typed)
    // A quiz node plays a round entirely from the replies: the questions are picked deterministically from the call id
    // (so every replica rebuilds the same game) and the score is just the answers checked against them.
    $quiz = null; $seed = (string)($hooks['seed'] ?? '');
    $quizInfo = $hooks['quiz_info'] ?? 'ussd_quiz_info'; $quizQs = $hooks['quiz_questions'] ?? 'ussd_quiz_questions'; $quizTop = $hooks['quiz_top'] ?? 'ussd_quiz_top';
    // A Shared Bundle node is a small service of its own: buy the bundle, add a sharing number, look at the account.
    // Reading things from Hera (validating a number, balance, numbers) happens here through a hook; anything that
    // changes an account (subscribe, add number) is returned as a result kind and carried out by the endpoint, once.
    $sb = null; $shareCall = $hooks['share'] ?? 'ussd_share_read'; $msisdnIn = (string)($hooks['msisdn'] ?? ''); $ctxIn = (array)($hooks['ctx'] ?? []);
    $sbNew = fn() => ['phase' => 'main', 'offers' => [], 'page' => 0, 'offer' => null, 'number' => null, 'text' => ''];
    $quizNew = function (array $pick) use ($quizInfo): array {
        $key = trim((string)$pick['offer_code']); $info = $quizInfo($key);
        return ['key' => $key, 'info' => $info, 'qs' => [], 'i' => 0, 'score' => 0, 'round' => 0, 'phase' => $info ? 'intro' : 'unavailable', 'fb' => null];
    };
    $quizStart = function (array &$qz) use ($quizQs, $seed): void {
        $qs = $quizQs($qz['key'], $seed, $qz['round'], (int)$qz['info']['per_game']);
        if (!$qs) { $qz['phase'] = 'unavailable'; return; }
        $qz['qs'] = $qs; $qz['i'] = 0; $qz['score'] = 0; $qz['fb'] = null; $qz['phase'] = 'q';
    };
    $confirm = null;   // [node, offer row, name] while the customer is being asked to confirm a purchase
    $hasRecipientNode = fn() => (bool)array_filter($trail, fn($t) => $t && $t['node_type'] === 'recipient');
    // The options on the current screen. A catalogue list builds them from the offer catalogue, a page at a time;
    // under "Buy for another number" they are the main menu again (without that item), once the number is known.
    $otherMemo = [];
    $allowedOther = function (array $n) use ($kids, $offerLookup, $catalogLookup, &$otherMemo): bool { return ussd_allowed_for_other($n, $kids, $offerLookup, $catalogLookup, $otherMemo); };
    $current = function () use (&$cur, &$page, &$recipient, &$trail, $kids, $roots, $catalogLookup, $allowedOther): array {
        if ($cur && $cur['node_type'] === 'recipient') {
            if ($recipient === null) return [[], false];
            $top = $trail[0]; $rootOptions = $top ? ($kids[(int)$top['id']] ?? []) : $roots;
            return [array_values(array_filter($rootOptions, $allowedOther)), false]; // only what can be bought for someone else
        }
        if ($cur && $cur['node_type'] === 'catalog') {
            $all = []; $rows = $catalogLookup($cur); $seen = [];
            // buying for someone else only works for offers that have an "other" code
            if ($recipient !== null) $rows = array_values(array_filter($rows, fn($o) => !array_key_exists('offer_code_for_other', $o) || trim((string)$o['offer_code_for_other']) !== ''));
            foreach ($rows as $o) { $k = strtolower(trim((string)($o['name'] ?? ''))); $seen[$k] = ($seen[$k] ?? 0) + 1; }
            foreach ($rows as $o) {
                $name = trim((string)($o['name'] ?? '')) ?: (string)$o['offer_code'];
                // several offers can share a name (e.g. 175MB for Facebook, TikTok…): tell them apart by their sub-category
                if (($seen[strtolower($name)] ?? 0) > 1 && trim((string)($o['sub_category'] ?? '')) !== '') $name .= ' '.trim(preg_replace('/\s*bundles?$/i', '', (string)$o['sub_category']));
                $all[] = ['id' => 'c'.$cur['id'].'-'.$o['offer_code'], 'parent_id' => $cur['id'], 'node_type' => 'offer', 'status' => 'active', 'offer_code' => (string)$o['offer_code'], 'action_key' => '', 'full_label' => $name,
                    'prompt_text' => mb_strimwidth($name, 0, 18, '…').(($o['one_time_price'] ?? '') !== '' ? ' - D'.$o['one_time_price'] : '')];
            }
            return [array_slice($all, $page * USSD_PAGE_SIZE, USSD_PAGE_SIZE), count($all) > ($page + 1) * USSD_PAGE_SIZE];
        }
        $opts = $cur ? ($kids[(int)$cur['id']] ?? []) : $roots;
        if ($recipient !== null) $opts = array_values(array_filter($opts, $allowedOther));
        return [$opts, false];
    };
    for ($ix = 0; $ix < count($replies); $ix++) {
        $r = trim((string)$replies[$ix], " \t\r\n*#"); // people sometimes type *2 or 2# — the star and hash are not part of the choice
        if ($confirm !== null) {
            [$pick, $o, $name] = $confirm;
            if ($r === '1') return ussd_result('Processing your purchase of '.$name.'...', true, $path, 'purchase', $pick)
                + ['purchase' => ['offer_code' => trim((string)$pick['offer_code']), 'name' => $name, 'price' => ($o['one_time_price'] !== null && $o['one_time_price'] !== '') ? (string)$o['one_time_price'] : '',
                    'vendor' => (string)($o['vendor'] ?? ''), 'other_offer_code' => (string)($o['offer_code_for_other'] ?? ''), 'recipient' => $recipient ?? '', 'recipient_raw' => $recipient !== null ? $recipientRaw : '']];
            if ($r === '2') return ussd_result('Cancelled. You were not charged.', true, $path, 'cancel', $pick);
            if ($r === '0') { $confirm = null; array_pop($path); $note = null; continue; }
            $note = 'Invalid choice.'; continue;
        }
        if ($r === '0' && $sb !== null && $cur && $cur['node_type'] === 'sharedbundle' && $sb['phase'] !== 'main') { // "0" inside the service goes back one step, not out of it
            $sb['phase'] = in_array($sb['phase'], ['balance', 'numbers'], true) ? 'account' : 'main'; $sb['offer'] = null; $note = null; continue;
        }
        if ($r === '0' && count($trail) > 1) { array_pop($trail); $cur = end($trail) ?: null; array_pop($path); $note = null; $page = 0; if (!$hasRecipientNode()) $recipient = null; if (!$cur || $cur['node_type'] !== 'quiz') $quiz = null; if (!$cur || $cur['node_type'] !== 'sharedbundle') $sb = null; continue; }
        if ($sb !== null && $cur && $cur['node_type'] === 'sharedbundle') {
            $ph = $sb['phase']; $note = null;
            if ($ph === 'main') {
                if ($r === '1') {
                    $sb['offers'] = array_values($catalogLookup($cur)); $sb['page'] = 0;
                    if (!$sb['offers']) $note = 'No Shared Bundle offer is available right now.';
                    elseif (count($sb['offers']) === 1) { $row = $offerLookup((string)$sb['offers'][0]['offer_code']); if ($row) { $sb['offer'] = ['code' => (string)$sb['offers'][0]['offer_code'], 'row' => $row]; $sb['phase'] = 'confirm'; } else $note = 'This offer is not available right now.'; }
                    else $sb['phase'] = 'list';
                } elseif ($r === '2') $sb['phase'] = 'num';
                elseif ($r === '3') $sb['phase'] = 'account';
                else $note = 'Invalid choice.';
                continue;
            }
            if ($ph === 'list') {
                $slice = array_slice($sb['offers'], $sb['page'] * USSD_PAGE_SIZE, USSD_PAGE_SIZE); $more = count($sb['offers']) > ($sb['page'] + 1) * USSD_PAGE_SIZE;
                if ($more && $r === (string)(USSD_PAGE_SIZE + 1)) { $sb['page']++; $note = null; continue; }
                if (!ctype_digit($r) || (int)$r < 1 || (int)$r > count($slice)) { $note = 'Invalid choice.'; continue; }
                $code = (string)$slice[(int)$r - 1]['offer_code']; $row = $offerLookup($code);
                if (!$row) { $note = 'This offer is not available right now.'; continue; }
                $sb['offer'] = ['code' => $code, 'row' => $row]; $sb['phase'] = 'confirm'; $note = null; continue;
            }
            if ($ph === 'confirm') {
                if ($r !== '1') { $note = 'Invalid choice.'; continue; }
                $row = $sb['offer']['row'];
                return ussd_result('Processing your subscription...', true, $path, 'share_subscribe', $cur)
                    + ['share' => ['op' => 'subscribe', 'offer_code' => $sb['offer']['code'], 'name' => trim((string)($row['name'] ?? '')) ?: $sb['offer']['code'], 'price' => (string)($row['one_time_price'] ?? ''),
                        'vendor' => (string)($row['vendor'] ?? ''), 'other_offer_code' => (string)($row['offer_code_for_other'] ?? '')]];
            }
            if ($ph === 'num') {
                $n = ussd_normalize_msisdn($r);
                if ($n === null) { $note = 'Invalid number.'; continue; }
                $rawNum = preg_replace('/\D+/', '', $r); $v = $shareCall('validate', ['msisdn' => $msisdnIn, 'other' => $n, 'other_raw' => $rawNum, 'ctx' => $ctxIn]);
                if (empty($v['ok'])) { $note = trim((string)($v['text'] ?? '')) ?: 'This number cannot be added.'; continue; }
                $sb['number'] = $n; $sb['number_raw'] = $rawNum; $sb['phase'] = 'numconfirm'; $note = null; continue;
            }
            if ($ph === 'numconfirm') {
                if ($r !== '1') { $note = 'Invalid choice.'; continue; }
                return ussd_result('Adding the number...', true, $path, 'share_add', $cur) + ['share' => ['op' => 'add', 'other' => $sb['number'], 'other_raw' => $sb['number_raw'] ?? $sb['number']]];
            }
            if ($ph === 'account') {
                if ($r === '1' || $r === '2') { $res = $shareCall($r === '1' ? 'balance' : 'numbers', ['msisdn' => $msisdnIn, 'ctx' => $ctxIn]); $sb['text'] = trim((string)($res['text'] ?? '')) ?: 'Not available right now.'; $sb['phase'] = $r === '1' ? 'balance' : 'numbers'; $note = null; }
                else $note = 'Invalid choice.';
                continue;
            }
            $note = 'Invalid choice.'; continue; // balance / numbers: only 0 (handled above)
        }
        if ($quiz !== null && $cur && $cur['node_type'] === 'quiz') { // playing a quiz: '0' (handled above) leaves it
            if ($quiz['phase'] === 'intro') { if ($r === '1') $quizStart($quiz); elseif ($r === '2') $quiz['phase'] = 'top'; else $note = 'Invalid choice.'; continue; }
            if ($quiz['phase'] === 'top') { if ($r === '1') $quiz['phase'] = 'intro'; else $note = 'Invalid choice.'; continue; }
            if ($quiz['phase'] === 'q') {
                $q = $quiz['qs'][$quiz['i']];
                if (!ctype_digit($r) || (int)$r < 1 || (int)$r > count($q['opts'])) { $note = 'Invalid choice.'; continue; }
                $good = (int)$r === (int)$q['correct']; if ($good) $quiz['score']++;
                $quiz['fb'] = $good ? 'Correct!' : 'Wrong. Answer was '.$q['correct'].'.';
                if (++$quiz['i'] >= count($quiz['qs'])) $quiz['phase'] = 'done';
                $note = null; continue;
            }
            if ($quiz['phase'] === 'done') { if ($r === '1') { $quiz['round']++; $quizStart($quiz); } else $note = 'Invalid choice.'; continue; }
            $note = 'Invalid choice.'; continue;
        }
        if ($cur && $cur['node_type'] === 'recipient' && $recipient === null) { // this reply is the other number
            $num = ussd_normalize_msisdn($r);
            if ($num === null) { $note = 'Invalid number.'; continue; }
            $recipient = $num; $recipientRaw = preg_replace('/\D+/', '', $r); $note = null; continue;
        }
        [$options, $more] = $current();
        if ($more && $r === (string)(USSD_PAGE_SIZE + 1)) { $page++; $note = null; continue; }
        if (!ctype_digit($r) || (int)$r < 1 || (int)$r > count($options)) { $note = 'Invalid choice.'; continue; }
        $pick = $options[(int)$r - 1]; $path[] = (int)$r; $note = null;
        if (($pick['node_type'] === 'menu' && !empty($kids[(int)$pick['id']])) || in_array($pick['node_type'], ['catalog', 'recipient', 'quiz', 'sharedbundle'], true)) { $cur = $pick; $trail[] = $cur; $page = 0; if ($pick['node_type'] === 'quiz') $quiz = $quizNew($pick); if ($pick['node_type'] === 'sharedbundle') $sb = $sbNew(); continue; }
        // an Action item whose flow is ready (Live for customers; in the Simulator whatever its mode) is opened as that flow
        if ($pick['node_type'] === 'action') { $ak = ussd_action_flow_key($pick); $afl = $ak !== '' ? flow_get($ak) : null; if ($afl && $afl['status'] === 'active' && ($afl['mode'] === 'live' || isset($hooks['flow_sim']))) { $pick['node_type'] = 'flow'; $pick['offer_code'] = $ak; } }
        // a service flow: its own screens from here on (until it leaves, when the menu carries on with the remaining replies)
        if ($pick['node_type'] === 'flow') {
            $fk = trim((string)$pick['offer_code']); $fl = flow_get($fk);
            if (!$fl || $fl['status'] !== 'active') return ussd_result('This service is not available right now.', true, $path, 'flow_unavailable', $pick);
            $fr = flow_screen($fl['def'], array_slice($replies, $ix + 1), ussd_flow_hooks($fl, $hooks, $shortCode));
            if (isset($fr['exit_at'])) { array_pop($path); $ix += $fr['exit_at']; $note = null; continue; }
            return ussd_result(isset($fr['pending']) ? 'Processing...' : (string)$fr['text'], isset($fr['pending']) ? false : (bool)$fr['end'], $path, isset($fr['pending']) ? 'flow_call' : 'flow', $pick)
                + ['flow_key' => $fk, 'flow_pending' => $fr['pending'] ?? null];
        }
        // an offer: show what it is and ask for a yes before anything is bought
        if ($pick['node_type'] === 'offer') {
            $o = $offerLookup(trim((string)$pick['offer_code']));
            if (!$o) return ussd_result('Sorry, this offer is not available right now.', true, $path, 'offer_unavailable', $pick);
            if ($recipient !== null && trim((string)($o['offer_code_for_other'] ?? '')) === '') return ussd_result('Sorry, this offer cannot be bought for another number.', true, $path, 'offer_unavailable', $pick);
            $confirm = [$pick, $o, trim((string)($pick['full_label'] ?? '')) ?: (trim((string)($o['name'] ?? '')) ?: trim((string)$pick['prompt_text']))];
            continue;
        }
        // a leaf: action / end / an empty submenu — the session ends here
        if ($pick['node_type'] === 'action') return ussd_result($pick['prompt_text']."\nThis service is not available right now.", true, $path, 'action', $pick);
        return ussd_result(trim((string)($pick['body_text'] ?? '')) !== '' ? $pick['body_text'] : $pick['prompt_text'], true, $path, 'end', $pick);
    }
    if ($confirm !== null) {
        [$pick, $o, $name] = $confirm;
        $line = 'Buy '.$name.(($o['one_time_price'] ?? '') !== '' ? ' - D'.$o['one_time_price'] : '').(!empty($o['validity_amount']) ? ' / '.$o['validity_amount'].' days' : '').($recipient ? ' for '.ussd_local_number($recipient) : '').'?';
        return ussd_result(($note ? $note."\n" : '').$line."\n1. Confirm\n2. Cancel\n0. Back", false, $path, 'confirm', $pick);
    }
    if ($cur && $cur['node_type'] === 'recipient' && $recipient === null) return ussd_result(($note ? $note."\n" : '')."Enter the other phone number:\n0. Back", false, $path, 'recipient', $cur);
    if ($sb !== null && $cur && $cur['node_type'] === 'sharedbundle') {
        $L = []; if ($note) $L[] = $note; $ph = $sb['phase'];
        if ($ph === 'main') return ussd_result(implode("\n", array_merge($L, ['Shared Bundle', '1. Buy Shared Bundle', '2. Add Sharing Number', '3. My Account', '0. Exit'])), false, $path, 'sharedbundle', $cur);
        if ($ph === 'list') {
            $slice = array_slice($sb['offers'], $sb['page'] * USSD_PAGE_SIZE, USSD_PAGE_SIZE); $more = count($sb['offers']) > ($sb['page'] + 1) * USSD_PAGE_SIZE; $L[] = 'Shared Bundle offers:';
            foreach ($slice as $i => $o) $L[] = ($i + 1).'. '.mb_strimwidth(trim((string)$o['name']) ?: (string)$o['offer_code'], 0, 18, '…').(($o['one_time_price'] ?? '') !== '' ? ' - D'.$o['one_time_price'] : '');
            if ($more) $L[] = (USSD_PAGE_SIZE + 1).'. More';
            $L[] = '0. Back'; return ussd_result(implode("\n", $L), false, $path, 'sharedbundle', $cur);
        }
        if ($ph === 'confirm') { $row = $sb['offer']['row']; $nm = trim((string)($row['name'] ?? '')) ?: $sb['offer']['code'];
            $L[] = 'Press 1 to subscribe to Seddo '.$nm.(($row['one_time_price'] ?? '') !== '' ? ' for D'.$row['one_time_price'] : '').(!empty($row['validity_amount']) ? ', valid for '.$row['validity_amount'].' days' : '').', or press 0 to return to the menu.';
            return ussd_result(implode("\n", $L), false, $path, 'sharedbundle', $cur); }
        if ($ph === 'num') return ussd_result(implode("\n", array_merge($L, ['Enter the beneficiary Seddo number:', '0. Back'])), false, $path, 'sharedbundle', $cur);
        if ($ph === 'numconfirm') return ussd_result(implode("\n", array_merge($L, ['Please confirm that your Seddo number '.ussd_local_number($sb['number']).' is correct. Press 1 to confirm or press 0 to return.'])), false, $path, 'sharedbundle', $cur);
        if ($ph === 'account') return ussd_result(implode("\n", array_merge($L, ['My Account', '1. Check Balance', '2. My Seddo Numbers', '0. Back'])), false, $path, 'sharedbundle', $cur);
        return ussd_result(implode("\n", array_merge($L, [$sb['text'], '0. Back'])), false, $path, 'sharedbundle', $cur);
    }
    if ($quiz !== null && $cur && $cur['node_type'] === 'quiz') {
        $L = []; if ($note) $L[] = $note; $info = $quiz['info'] ?? [];
        if ($quiz['phase'] === 'intro') return ussd_result(implode("\n", array_merge($L, [$info['title'], 'Answer '.(int)$info['per_game'].' questions, 1 point each.', '1. Start', '2. Top players', '0. Exit'])), false, $path, 'quiz', $cur);
        if ($quiz['phase'] === 'top') return ussd_result(implode("\n", array_merge($L, [$quizTop($quiz['key']), '1. Back', '0. Exit'])), false, $path, 'quiz', $cur);
        if ($quiz['phase'] === 'q') {
            $q = $quiz['qs'][$quiz['i']];
            if ($quiz['fb']) $L[] = $quiz['fb'];
            $L[] = 'Q'.($quiz['i'] + 1).'/'.count($quiz['qs']).': '.$q['q'];
            foreach ($q['opts'] as $k => $o) $L[] = ($k + 1).'. '.$o;
            $L[] = '0. Exit';
            return ussd_result(implode("\n", $L), false, $path, 'quiz', $cur);
        }
        if ($quiz['phase'] === 'done') {
            $total = count($quiz['qs']); $won = $quiz['score'] >= (int)$info['win_score'];
            if ($quiz['fb']) $L[] = $quiz['fb'];
            $L[] = 'Game over! Score '.$quiz['score'].'/'.$total;
            if ($won && trim((string)$info['win_text']) !== '') $L[] = $info['win_text'];
            array_push($L, '1. Play again', '0. Exit');
            return ussd_result(implode("\n", $L), false, $path, 'quiz_done', $cur) + ['quiz' => ['key' => $quiz['key'], 'score' => $quiz['score'], 'total' => $total, 'round' => $quiz['round'], 'won' => $won]];
        }
        return ussd_result(implode("\n", array_merge($L, ['This game is not available right now.', '0. Back'])), false, $path, 'quiz', $cur);
    }
    [$options, $more] = $current();
    $lines = [$cur ? (($cur['node_type'] === 'recipient') ? 'Buy for '.ussd_local_number($recipient).':' : (trim((string)($cur['body_text'] ?? '')) !== '' ? $cur['body_text'] : $cur['prompt_text'])) : 'Welcome'];
    if ($note) array_unshift($lines, $note);
    foreach ($options as $i => $o) $lines[] = ($i + 1).'. '.$o['prompt_text'];
    if ($cur && $cur['node_type'] === 'catalog' && !$options) $lines[] = 'No offers are available right now.';
    if ($cur && $recipient !== null && !$options && $cur['node_type'] !== 'catalog') $lines[] = 'Nothing here can be bought for another number.';
    if ($more) $lines[] = (USSD_PAGE_SIZE + 1).'. More';
    if (count($trail) > 1) $lines[] = '0. Back';
    return ussd_result(implode("\n", $lines), false, $path, 'menu', $cur);
}
// ---- Quiz games: sets of questions managed on the USSD Quiz page, played from a "quiz" menu item ----
const USSD_QUIZ_BLOCK_MAX = 150; // longest question + options a screen can carry once the feedback and "0. Exit" lines are added
function ussd_quiz_tables(): void {
    static $done = false; if ($done) return;
    $db = portal_pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_quizzes (
        quiz_key VARCHAR(40) NOT NULL PRIMARY KEY, title VARCHAR(80) NOT NULL, per_game TINYINT NOT NULL DEFAULT 5, win_score TINYINT NOT NULL DEFAULT 4,
        win_text VARCHAR(120) NULL, status ENUM('active','inactive') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_quiz_questions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, quiz_key VARCHAR(40) NOT NULL, question VARCHAR(160) NOT NULL,
        opt1 VARCHAR(40) NOT NULL, opt2 VARCHAR(40) NOT NULL, opt3 VARCHAR(40) NULL, opt4 VARCHAR(40) NULL, correct TINYINT NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active', created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_quiz_status (quiz_key, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_quiz_plays (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, call_id VARCHAR(120) NOT NULL, round INT NOT NULL, quiz_key VARCHAR(40) NOT NULL, msisdn VARCHAR(30) NOT NULL,
        score TINYINT NOT NULL, total TINYINT NOT NULL, won TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_call_round (call_id, round), INDEX idx_quiz_time (quiz_key, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
function ussd_quiz_info(string $key): ?array {
    try { ussd_quiz_tables(); $st = portal_pdo()->prepare("SELECT * FROM ussd_quizzes WHERE quiz_key=? AND status='active'"); $st->execute([$key]); return $st->fetch() ?: null; }
    catch (Throwable $e) { error_log('ussd quiz info: '.$e->getMessage()); return null; }
}
// $n questions for one round, in an order that depends only on the call id and the round, so the same replies always rebuild the same game.
function ussd_quiz_questions(string $key, string $seed, int $round, int $n): array {
    try {
        ussd_quiz_tables(); $st = portal_pdo()->prepare("SELECT * FROM ussd_quiz_questions WHERE quiz_key=? AND status='active'"); $st->execute([$key]); $rows = $st->fetchAll();
    } catch (Throwable $e) { error_log('ussd quiz questions: '.$e->getMessage()); return []; }
    usort($rows, fn($a, $b) => strcmp(md5($seed.'|'.$round.'|'.$a['id']), md5($seed.'|'.$round.'|'.$b['id'])));
    $out = [];
    foreach (array_slice($rows, 0, max(1, $n)) as $r) {
        $opts = array_values(array_filter([$r['opt1'], $r['opt2'], $r['opt3'], $r['opt4']], fn($o) => $o !== null && trim((string)$o) !== ''));
        $out[] = ['id' => (int)$r['id'], 'q' => $r['question'], 'opts' => $opts, 'correct' => (int)$r['correct']];
    }
    return $out;
}
function ussd_quiz_mask(string $msisdn): string { $d = ussd_local_number($msisdn); return strlen($d) > 6 ? substr($d, 0, 3).'***'.substr($d, -3) : '***'; }
function ussd_quiz_top(string $key): string {
    try {
        ussd_quiz_tables();
        $st = portal_pdo()->prepare('SELECT msisdn, SUM(score) AS pts FROM ussd_quiz_plays WHERE quiz_key=? AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY msisdn ORDER BY pts DESC, MIN(created_at) LIMIT 5');
        $st->execute([$key]); $rows = $st->fetchAll();
    } catch (Throwable $e) { return 'Top players: not available right now.'; }
    if (!$rows) return "Top players this week:\nNo scores yet. Be the first!";
    $lines = ['Top players this week:']; foreach ($rows as $i => $r) $lines[] = ($i + 1).'. '.ussd_quiz_mask((string)$r['msisdn']).' - '.(int)$r['pts'];
    return implode("\n", $lines);
}
// Records a finished round (once per call and round, however often Mobius repeats the request).
function ussd_quiz_record(array $screen, string $msisdn, string $callId): void {
    if (($screen['kind'] ?? '') !== 'quiz_done' || empty($screen['quiz']) || $callId === '') return;
    $q = $screen['quiz'];
    try {
        ussd_quiz_tables();
        portal_pdo()->prepare('INSERT IGNORE INTO ussd_quiz_plays(call_id,round,quiz_key,msisdn,score,total,won) VALUES(?,?,?,?,?,?,?)')
            ->execute([substr($callId, 0, 120), (int)$q['round'], $q['key'], preg_replace('/\D+/', '', $msisdn), (int)$q['score'], (int)$q['total'], $q['won'] ? 1 : 0]);
    } catch (Throwable $e) { error_log('ussd quiz record: '.$e->getMessage()); }
}
// Checks one question's fields and returns them ready to store. Throws a message an editor can act on.
function quiz_question_fields(string $question, array $opts, int $correct): array {
    $question = trim($question); $opts = array_values(array_filter(array_map('trim', $opts), fn($o) => $o !== ''));
    if (mb_strlen($question) < 5 || mb_strlen($question) > 150) throw new RuntimeException('The question must be 5 to 150 characters.');
    if (count($opts) < 2 || count($opts) > 4) throw new RuntimeException('Give 2 to 4 answers.');
    foreach ($opts as $o) if (mb_strlen($o) > 30) throw new RuntimeException('An answer can be at most 30 characters ("'.mb_substr($o, 0, 20).'…").');
    if ($correct < 1 || $correct > count($opts)) throw new RuntimeException('The right answer must be a number from 1 to '.count($opts).'.');
    $len = 6 + mb_strlen($question) + 1; foreach ($opts as $o) $len += 4 + mb_strlen($o);
    if ($len + 7 > USSD_QUIZ_BLOCK_MAX) throw new RuntimeException('Too long for one phone screen ('.($len + 7).' characters, at most '.USSD_QUIZ_BLOCK_MAX.'): shorten the question or the answers.');
    return [$question, $opts, $correct];
}
function save_quiz(array $d): string {
    ussd_quiz_tables();
    $key = trim((string)($d['quiz_key'] ?? '')); if (!preg_match('/^[a-z0-9_-]{2,40}$/', $key)) throw new RuntimeException('The quiz key is 2 to 40 characters: lowercase letters, digits, - and _.');
    $title = trim((string)($d['title'] ?? '')); if ($title === '' || mb_strlen($title) > 80) throw new RuntimeException('Give the quiz a title (up to 80 characters).');
    $per = filter_var($d['per_game'] ?? null, FILTER_VALIDATE_INT); if ($per === false || $per < 3 || $per > 10) throw new RuntimeException('Questions per game: 3 to 10.');
    $win = filter_var($d['win_score'] ?? null, FILTER_VALIDATE_INT); if ($win === false || $win < 1 || $win > $per) throw new RuntimeException('The winning score must be between 1 and the number of questions.');
    $text = trim((string)($d['win_text'] ?? '')); if (mb_strlen($text) > 120) throw new RuntimeException('The winner message is at most 120 characters.');
    $status = ($d['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
    portal_pdo()->prepare('INSERT INTO ussd_quizzes(quiz_key,title,per_game,win_score,win_text,status) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), per_game=VALUES(per_game), win_score=VALUES(win_score), win_text=VALUES(win_text), status=VALUES(status)')
        ->execute([$key, $title, $per, $win, $text !== '' ? $text : null, $status]);
    audit('ussd_quiz_save', null, 'ussd_quizzes', $key, json_encode(['title' => $title, 'per_game' => $per, 'win' => $win, 'status' => $status]));
    return $key;
}
function quiz_must_exist(string $key): void {
    $st = portal_pdo()->prepare('SELECT 1 FROM ussd_quizzes WHERE quiz_key=?'); $st->execute([$key]);
    if (!$st->fetchColumn()) throw new RuntimeException('That quiz does not exist.');
}
function save_quiz_question(array $d): string {
    ussd_quiz_tables(); $key = trim((string)($d['quiz_key'] ?? '')); quiz_must_exist($key);
    [$q, $opts, $correct] = quiz_question_fields((string)($d['question'] ?? ''), [$d['opt1'] ?? '', $d['opt2'] ?? '', $d['opt3'] ?? '', $d['opt4'] ?? ''], (int)($d['correct'] ?? 0));
    $vals = [$q, $opts[0], $opts[1], $opts[2] ?? null, $opts[3] ?? null, $correct]; $id = (int)($d['id'] ?? 0); $db = portal_pdo();
    if ($id > 0) { $db->prepare('UPDATE ussd_quiz_questions SET question=?,opt1=?,opt2=?,opt3=?,opt4=?,correct=? WHERE id=? AND quiz_key=?')->execute([...$vals, $id, $key]); }
    else { $db->prepare('INSERT INTO ussd_quiz_questions(question,opt1,opt2,opt3,opt4,correct,quiz_key,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute([...$vals, $key, user()['username'] ?? null]); $id = (int)$db->lastInsertId(); }
    audit('ussd_quiz_question_save', null, 'ussd_quiz_questions', (string)$id, $key);
    return $key;
}
function toggle_quiz_question(int $id): string {
    ussd_quiz_tables(); $db = portal_pdo(); $st = $db->prepare('SELECT quiz_key, status FROM ussd_quiz_questions WHERE id=?'); $st->execute([$id]); $r = $st->fetch();
    if (!$r) throw new RuntimeException('That question is gone.');
    $db->prepare('UPDATE ussd_quiz_questions SET status=? WHERE id=?')->execute([$r['status'] === 'active' ? 'inactive' : 'active', $id]);
    audit('ussd_quiz_question_toggle', null, 'ussd_quiz_questions', (string)$id, $r['quiz_key']);
    return $r['quiz_key'];
}
// One question per line:  Question | answer 1 | answer 2 | answer 3 | answer 4 | number of the right answer
function import_quiz_questions(string $key, string $text): int {
    ussd_quiz_tables(); quiz_must_exist($key); $rows = [];
    foreach (preg_split('/\R/', $text) as $n => $line) {
        $line = trim($line); if ($line === '' || $line[0] === '#') continue;
        $p = array_map('trim', explode('|', $line));
        if (count($p) < 4 || !ctype_digit(end($p))) throw new RuntimeException('Line '.($n + 1).': write  Question | answer | answer | … | number of the right answer');
        $correct = (int)array_pop($p); $q = array_shift($p);
        try { $rows[] = quiz_question_fields($q, $p, $correct); } catch (RuntimeException $e) { throw new RuntimeException('Line '.($n + 1).': '.$e->getMessage()); }
    }
    if (!$rows) throw new RuntimeException('Nothing to import.');
    if (count($rows) > 200) throw new RuntimeException('At most 200 questions per import.');
    $db = portal_pdo(); $db->beginTransaction();
    try {
        $ins = $db->prepare('INSERT INTO ussd_quiz_questions(question,opt1,opt2,opt3,opt4,correct,quiz_key,created_by) VALUES(?,?,?,?,?,?,?,?)');
        foreach ($rows as [$q, $o, $c]) $ins->execute([$q, $o[0], $o[1], $o[2] ?? null, $o[3] ?? null, $c, $key, user()['username'] ?? null]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit('ussd_quiz_import', null, 'ussd_quiz_questions', null, $key.' +'.count($rows));
    return count($rows);
}
// The offers a catalogue-list node shows: active ones in its sub-categories, cheapest first, each code once.
function ussd_catalog_offers(array $node): array {
    $subs = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($node['catalog_filter'] ?? '')))));
    if (!$subs) return [];
    try {
        $st = pdo(USSD_OFFER_SCHEMA)->prepare('SELECT offer_code, offer_code_for_other, name, sub_category, one_time_price, validity_amount FROM vas_offers WHERE sub_category IN ('.implode(',', array_fill(0, count($subs), '?')).') AND '.OFFER_ACTIVE_SQL." AND (deleted_at IS NULL OR deleted_at='') AND offer_code IS NOT NULL AND offer_code<>'' ORDER BY id DESC LIMIT 200");
        $st->execute($subs);
        $rows = []; foreach ($st->fetchAll() as $o) if (!isset($rows[$o['offer_code']])) $rows[$o['offer_code']] = $o;
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => [(float)$a['one_time_price'], (string)$a['name']] <=> [(float)$b['one_time_price'], (string)$b['name']]);
        return array_slice($rows, 0, 60);
    } catch (Throwable $e) { error_log('ussd catalog: '.$e->getMessage()); return []; }
}
// The USSD menu sells from the TEST catalogue only for now (the live catalogue is deliberately not read).
const USSD_OFFER_SCHEMA = 'HeraTesting';
function ussd_offer_lookup(string $code): ?array {
    if ($code === '') return null;
    try {
        $st = pdo(USSD_OFFER_SCHEMA)->prepare("SELECT name, one_time_price, validity_amount, status, vendor, offer_code_for_other FROM vas_offers WHERE offer_code=? AND (deleted_at IS NULL OR deleted_at='') ORDER BY id DESC");
        $st->execute([$code]);
        foreach ($st->fetchAll() as $o) if (offer_is_active($o['status'])) return $o;
    } catch (Throwable $e) { error_log('ussd offer lookup: '.$e->getMessage()); }
    return null;
}

// ---- Purchases from the menu ----
// off  = nothing is bought; the customer is told it isn't switched on.
// test = the confirmation is recorded and the customer is told it was a test; no request leaves the portal.
// live = one HTTP request to the configured purchase address (the Hera test environment's API).
// Each (call, offer) is attempted at most once, however many times Mobius repeats a request.
function ussd_purchase_table(): void {
    static $done = false; if ($done) return;
    portal_pdo()->exec("CREATE TABLE IF NOT EXISTS ussd_purchases (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        call_id VARCHAR(120) NOT NULL, offer_code VARCHAR(45) NOT NULL, msisdn VARCHAR(30) NOT NULL, recipient VARCHAR(30) NULL,
        offer_name VARCHAR(80) NULL, price VARCHAR(20) NULL, mode VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL,
        http_code INT NULL, response TEXT NULL, reply_text VARCHAR(255) NULL, ms INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_call_offer (call_id, offer_code), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!portal_pdo()->query("SHOW COLUMNS FROM ussd_purchases LIKE 'request_body'")->fetch()) portal_pdo()->exec('ALTER TABLE ussd_purchases ADD COLUMN request_body TEXT NULL');
    if (!portal_pdo()->query("SHOW COLUMNS FROM ussd_purchases LIKE 'recipient'")->fetch()) portal_pdo()->exec('ALTER TABLE ussd_purchases ADD COLUMN recipient VARCHAR(30) NULL AFTER msisdn');
    if (!portal_pdo()->query("SHOW COLUMNS FROM ussd_purchases LIKE 'refund_status'")->fetch()) portal_pdo()->exec('ALTER TABLE ussd_purchases ADD COLUMN refund_status VARCHAR(10) NULL, ADD COLUMN refund_at DATETIME NULL, ADD COLUMN refund_by VARCHAR(80) NULL, ADD COLUMN refund_request TEXT NULL, ADD COLUMN refund_response TEXT NULL, ADD COLUMN refund_note VARCHAR(255) NULL');
    $done = true;
}
// The parts of Mobius's own request that Hera's purchase call repeats (the subscriber's IMSI, the SS7 addresses, dialog ids).
// The request as stored in the ledger: complete, but with the subscriber's IMSI shortened (nothing secret is ever in the body).
function ussd_request_for_log(string $body): string { return mb_substr((string)preg_replace('/("imsi":")(\d{3})\d+(\d{3})"/', '$1$2***$3"', $body), 0, 3000); }
function ussd_purchase_ctx(string $raw): array {
    $j = json_decode($raw, true); if (!is_array($j)) return [];
    $out = [];
    foreach (['imsi'] as $k) if (isset($j[$k]) && is_scalar($j[$k]) && preg_match('/^\d{5,20}$/', (string)$j[$k])) $out[$k] = (string)$j[$k];
    foreach (['localDialogID', 'remoteDialogID'] as $k) if (isset($j[$k]) && is_numeric($j[$k])) $out[$k] = (int)$j[$k];
    foreach (['localAddress', 'remoteAddress'] as $k) if (isset($j[$k]) && is_array($j[$k])) $out[$k] = $j[$k];
    return $out;
}
function ussd_purchase_http(array $cfg, string $msisdn, array $p, string $txn, array $ctx = []): array {
    $url = trim($cfg['purchase_url']);
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return ['code' => 0, 'raw' => '', 'error' => 'no valid purchase address saved'];
    $esc = fn($s) => substr((string)json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    $body = strtr(($p['recipient'] ?? '') !== '' ? $cfg['purchase_body_other'] : $cfg['purchase_body'], ['{other_msisdn}' => $esc($p['recipient_raw'] ?? $p['recipient'] ?? ''), '{msisdn}' => $esc($msisdn), '{offer_code}' => $esc($p['offer_code']), '{vendor}' => $esc($p['vendor'] ?? ''), '{other_offer_code}' => $esc($p['other_offer_code'] ?? ''),
        '{price}' => $esc($p['price']), '{price_d}' => $esc($p['price'] !== '' ? 'D'.$p['price'] : ''), '{txn}' => $esc($txn), '{shortcode}' => $esc($cfg['shortcode_proxy']),
        '{imsi}' => $esc($ctx['imsi'] ?? ''), '{local_dialog_id}' => isset($ctx['localDialogID']) ? (string)$ctx['localDialogID'] : 'null', '{remote_dialog_id}' => isset($ctx['remoteDialogID']) ? (string)$ctx['remoteDialogID'] : 'null',
        '{local_address_json}' => json_encode($ctx['localAddress'] ?? null, JSON_UNESCAPED_SLASHES), '{remote_address_json}' => json_encode($ctx['remoteAddress'] ?? null, JSON_UNESCAPED_SLASHES)]);
    $r = ussd_hera_post($cfg, $body); $r['sent'] = $body;
    return $r;
}
// POSTs a JSON body to the saved purchase address with the saved headers (one "Name: value" per line, kept encrypted).
function ussd_hera_post(array $cfg, string $body, ?string $url = null): array {
    $url = trim($url ?? $cfg['purchase_url']);
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return ['code' => 0, 'raw' => '', 'error' => 'no valid purchase address saved'];
    $hdr = ['Content-Type: application/json', 'Accept: application/json'];
    $auth = $cfg['purchase_auth'] !== '' ? decrypt_secret($cfg['purchase_auth']) : '';
    foreach (preg_split('/\r\n|\n/', $auth) as $line) if (preg_match('/^[A-Za-z0-9\-]{1,40}:\s*\S.*$/', trim($line))) $hdr[] = trim($line);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => max(2, min(15, (int)$cfg['purchase_timeout']))]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return ['code' => $code, 'raw' => is_string($raw) ? $raw : '', 'error' => $err];
}
// $screen is a result of kind 'purchase'. Returns the text to show the customer and a short note for the request log.
function ussd_execute_purchase(array $cfg, array $screen, string $msisdn, string $callId, array $ctx = []): array {
    $p = $screen['purchase']; $mode = in_array($cfg['purchase_mode'], ['test', 'test_low', 'live'], true) ? $cfg['purchase_mode'] : 'off';
    $lowText = 'Sorry, your balance is too low for '.$p['name'].'. Please top up and try again.';
    $msisdn = preg_replace('/\D+/', '', $msisdn); $recipient = preg_replace('/\D+/', '', (string)($p['recipient'] ?? ''));
    if ($mode === 'off') return ['text' => 'Purchases are not switched on yet. You were not charged.', 'note' => 'purchase: off'];
    if ($msisdn === '' || $p['offer_code'] === '') return ['text' => 'Sorry, we could not process this request. You were not charged.', 'note' => 'purchase: no number or offer code'];
    $t0 = microtime(true);
    try {
        ussd_purchase_table(); $db = portal_pdo();
        $callId = $callId !== '' ? substr($callId, 0, 120) : 'noid-'.bin2hex(random_bytes(6));
        $ins = $db->prepare("INSERT IGNORE INTO ussd_purchases(call_id,offer_code,msisdn,recipient,offer_name,price,mode,status) VALUES(?,?,?,?,?,?,?,'pending')");
        $ins->execute([$callId, $p['offer_code'], $msisdn, $recipient !== '' ? $recipient : null, mb_substr($p['name'], 0, 80), mb_substr($p['price'], 0, 20), $mode]);
        if ($ins->rowCount() === 0) {
            $st = $db->prepare('SELECT status, reply_text FROM ussd_purchases WHERE call_id=? AND offer_code=?'); $st->execute([$callId, $p['offer_code']]); $prev = $st->fetch();
            return ['text' => ($prev && $prev['reply_text']) ? $prev['reply_text'] : 'Your request is already being processed.', 'note' => 'purchase: repeated request, not sent again'];
        }
        $id = (int)$db->lastInsertId(); $httpCode = null; $resp = null; $sent = null;
        if ($mode === 'test') { $status = 'test'; $text = 'TEST: '.$p['name'].' would be bought for '.($recipient !== '' ? ussd_local_number($recipient).' (from '.ussd_local_number($msisdn).')' : ussd_local_number($msisdn)).'. You were not charged.'; }
        elseif ($mode === 'test_low') { $status = 'lowbal'; $text = $lowText; }
        elseif ($recipient !== '' && trim((string)$cfg['purchase_body_other']) === '') { $status = 'blocked'; $text = 'Buying for another number is not switched on yet. You were not charged.'; }
        else {
            $r = ussd_purchase_http($cfg, $msisdn, $p, $callId, $ctx); $sent = ussd_request_for_log((string)($r['sent'] ?? ''));
            $httpCode = $r['code']; $resp = $r['error'] !== '' ? 'error: '.$r['error'] : mb_substr($r['raw'], 0, 1000);
            $http2xx = $r['error'] === '' && $r['code'] >= 200 && $r['code'] < 300;
            $low = false;
            foreach (array_filter(array_map('trim', explode(',', (string)$cfg['purchase_lowbal']))) as $term) if (stripos((string)$r['raw'], $term) !== false) { $low = true; break; }
            $matched = $cfg['purchase_ok_match'] !== '' && stripos($r['raw'], $cfg['purchase_ok_match']) !== false;
            // Hera's own result code, when it gives one: result.resultCode "0" is success, anything else is a refusal ("-1" = deduction failed…).
            // A success word, if one is set, decides instead; with neither, a 2xx answer is only "sent".
            $jr = json_decode((string)$r['raw'], true); $hcode = null;
            if (is_array($jr)) { if (is_array($jr['result'] ?? null) && isset($jr['result']['resultCode'])) $hcode = (string)$jr['result']['resultCode']; elseif (isset($jr['resultCode']) && (string)$jr['resultCode'] !== '000') $hcode = (string)$jr['resultCode']; }
            $ok = (!$http2xx || $low) ? false : ($cfg['purchase_ok_match'] !== '' ? $matched : ($hcode !== null ? $hcode === '0' : null));
            $status = $low ? 'lowbal' : ($ok === true ? 'ok' : ($ok === null ? 'sent' : 'failed'));
            $own = '';
            if ($cfg['purchase_reply_field'] !== '' && ($j = json_decode($r['raw'], true)) && is_array($j)) { $v = $j[$cfg['purchase_reply_field']] ?? null; if (is_string($v)) $own = trim(mb_substr(preg_replace('/\s+/', ' ', $v), 0, 160)); }
            $text = $own !== '' ? $own : ($low ? $lowText : ($ok === true ? 'Thank you. '.$p['name'].' has been purchased.' : ($ok === null ? 'Your request for '.$p['name'].' has been sent.' : 'Sorry, the purchase could not be completed. Please try again later.')));
            // Hera answered but refused for its own reason (e.g. "Deduction for Subscription failed"): say what it said
            if ($status === 'failed' && $own === '' && $http2xx && ($hr = ussd_share_text(json_decode((string)$r['raw'], true), (string)$r['raw'])) !== '') $text = 'Sorry, the purchase could not be completed. '.rtrim($hr, '.').'.';
        }
        $db->prepare('UPDATE ussd_purchases SET status=?, http_code=?, response=?, reply_text=?, ms=?, request_body=? WHERE id=?')
            ->execute([$status, $httpCode, $resp, mb_substr($text, 0, 255), (int)round((microtime(true) - $t0) * 1000), $sent, $id]);
        return ['text' => $text, 'note' => 'purchase: '.$mode.' '.$status.($httpCode ? ' HTTP '.$httpCode : '')];
    } catch (Throwable $e) {
        error_log('ussd purchase: '.$e->getMessage());
        return ['text' => 'Sorry, we could not process this request. Please try again later.', 'note' => 'purchase error: '.substr($e->getMessage(), 0, 100)];
    }
}
// ---- Refunds: Hera BundleSubscription with channel REF, for a purchase made for oneself or for another number ----
// An admin presses Refund on a row of the purchase ledger. Only a live purchase that went through (or was refused after it tried) can be refunded;
// the number asked about is whoever the bundle was for (the other number, for a buy-for-other); Hera is asked once — a refund that went through is
// never sent again, one that failed or was only a test can be tried again. off = refused here, test = recorded and nothing sent, live = sent.
function ussd_refund_body(string $tpl, array $p, string $vendor): string {
    $esc = fn($s) => substr((string)json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    $target = preg_replace('/\D+/', '', (string)(($p['recipient'] ?? '') !== '' ? $p['recipient'] : $p['msisdn']));
    return strtr($tpl, ['{offer_code}' => $esc($p['offer_code']), '{date}' => $esc($p['created_at']), '{vendor}' => $esc($vendor), '{msisdn}' => $esc($target), '{msisdn_local}' => $esc(ussd_local_number($target)),
        '{buyer_local}' => $esc(ussd_local_number((string)$p['msisdn'])), '{txn}' => $esc($p['call_id'])]);
}
function ussd_refund_eligible(array $p): bool { return ($p['mode'] ?? '') === 'live' && in_array($p['status'] ?? '', ['ok', 'sent', 'failed'], true) && !in_array($p['refund_status'] ?? '', ['ok', 'pending'], true); }
function ussd_refund_purchase(array $cfg, int $id, string $by = ''): array {
    ussd_purchase_table(); $db = portal_pdo(); $mode = in_array($cfg['refund_mode'], ['test', 'live'], true) ? $cfg['refund_mode'] : 'off';
    if ($mode === 'off') throw new RuntimeException('Refunds are switched off (USSD Proxy → Buying offers → Refunds).');
    $st = $db->prepare('SELECT * FROM ussd_purchases WHERE id=?'); $st->execute([$id]); $p = $st->fetch(); if (!$p) throw new RuntimeException('That purchase is not in the ledger.');
    if (($p['refund_status'] ?? '') === 'ok') throw new RuntimeException('This purchase has already been refunded.');
    if (!ussd_refund_eligible($p)) throw new RuntimeException('Only a live purchase that went through (or was refused after it tried) can be refunded.');
    $claim = $db->prepare("UPDATE ussd_purchases SET refund_status='pending', refund_by=?, refund_at=NOW() WHERE id=? AND (refund_status IS NULL OR refund_status IN ('failed','test'))"); $claim->execute([mb_substr($by, 0, 80), $id]);
    if ($claim->rowCount() === 0) throw new RuntimeException('This purchase is already being refunded.');
    $v = $db->query('SELECT 1')->fetchColumn(); $vendor = '';
    try { $vs = pdo(USSD_OFFER_SCHEMA)->prepare('SELECT vendor FROM vas_offers WHERE offer_code=? ORDER BY id DESC LIMIT 1'); $vs->execute([$p['offer_code']]); $vendor = (string)$vs->fetchColumn(); } catch (Throwable $e) {}
    $body = ussd_refund_body((string)$cfg['refund_body'], $p, $vendor); $sent = ussd_request_for_log($body); $http = null; $resp = null;
    if ($mode === 'test') { $status = 'test'; $note = 'TEST: nothing was sent to Hera.'; }
    elseif ($vendor === '') { $status = 'failed'; $note = 'The offer '.$p['offer_code'].' is not in the catalogue, so its vendor is unknown — nothing was sent.'; }
    else {
        $r = ussd_hera_post($cfg, $body, (string)$cfg['refund_url']); $http = $r['code']; $resp = $r['error'] !== '' ? 'error: '.$r['error'] : mb_substr($r['raw'], 0, 1000);
        $cl = flow_classify(['ok_code' => '0', 'lowbal' => ''], $r); $status = $cl['outcome'] === 'success' ? 'ok' : 'failed';
        $note = $r['error'] !== '' ? 'No answer from Hera: '.$r['error'] : (($t = ussd_share_text(json_decode((string)$r['raw'], true), (string)$r['raw'])) !== '' ? mb_substr($t, 0, 200) : 'HTTP '.$r['code']);
    }
    $db->prepare('UPDATE ussd_purchases SET refund_status=?, refund_request=?, refund_response=?, refund_note=? WHERE id=?')->execute([$status, $sent, $resp, mb_substr($note, 0, 255), $id]);
    audit('ussd_refund', null, 'ussd_purchases', (string)$id, json_encode(['mode' => $mode, 'status' => $status, 'offer' => $p['offer_code'], 'for' => ussd_local_number((string)(($p['recipient'] ?? '') !== '' ? $p['recipient'] : $p['msisdn'])), 'http' => $http]));
    return ['status' => $status, 'note' => $note, 'mode' => $mode];
}
function save_refund_config(array $d): void {
    $mode = in_array($d['refund_mode'] ?? '', ['test', 'live'], true) ? $d['refund_mode'] : 'off'; $url = trim((string)($d['refund_url'] ?? ''));
    if ($url !== '' && (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 500)) throw new RuntimeException('The refund address must be a full http:// or https:// URL.');
    if ($mode === 'live' && $url === '') throw new RuntimeException('Live refunds need the refund address. Use Test until you have it.');
    $body = trim((string)($d['refund_body'] ?? '')); if ($body === '' || mb_strlen($body) > 2000) throw new RuntimeException('The refund request body must be filled in (at most 2000 characters).');
    $sample = ussd_refund_body($body, ['offer_code' => '40004', 'created_at' => '2025-08-08 15:30:18', 'msisdn' => '2206704843', 'recipient' => '', 'call_id' => 'x'], 'huawei');
    if (!is_array(json_decode($sample, true))) throw new RuntimeException('The refund request body is not valid JSON once filled in — check the quotes and the {placeholders}.');
    ussd_proxy_set(['refund_mode' => $mode, 'refund_url' => $url, 'refund_body' => $body]); audit('ussd_refund_save', null, 'ussd_proxy_config', null, 'mode='.$mode.' url='.parse_url($url, PHP_URL_HOST));
}
// ---- Shared Bundle (Seddo): the ShareBundle calls under .../hera/prepaid/ShareBundle/ ----
// share_mode: off = the service says it is not switched on; test = every answer is simulated and nothing is sent;
// live = real calls. Calls that change an account (subscribe, add number) need their request body saved first
// (blank = blocked); validating a number with no body saved is simply skipped.
const SHARE_PATHS = ['subscribe' => 'subscribe', 'validate' => 'addNumber', 'add' => 'addNumber', 'balance' => 'DataUsage', 'numbers' => 'listNumber'];
function ussd_share_sim(string $op, array $p): array {
    return match ($op) {
        'validate' => ['ok' => true, 'text' => ''],
        'balance' => ['ok' => true, 'text' => "Your Seddo bundle balance is:\n12GB data\n1200 mins, 1200 SMS (TEST)"],
        'numbers' => ['ok' => true, 'text' => "My Seddo numbers:\n1. 866***111\n2. 866***222 (TEST)"],
        default => ['ok' => true, 'text' => 'TEST'],
    };
}
// One call to a ShareBundle address. Returns the HTTP outcome plus Hera's text and whether it says success.
function ussd_share_http(array $cfg, string $op, array $p): array {
    $tpl = (string)$cfg['share_body_'.$op];
    $esc = fn($s) => substr((string)json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    $body = strtr($tpl, ['{msisdn}' => $esc($p['msisdn'] ?? ''), '{other_msisdn}' => $esc($p['other_raw'] ?? $p['other'] ?? ''), '{offer_code}' => $esc($p['offer_code'] ?? ''), '{vendor}' => $esc($p['vendor'] ?? ''),
        '{other_offer_code}' => $esc($p['other_offer_code'] ?? ''), '{price}' => $esc($p['price'] ?? ''), '{price_d}' => $esc(($p['price'] ?? '') !== '' ? 'D'.$p['price'] : ''), '{txn}' => $esc($p['txn'] ?? ''),
        '{shortcode}' => $esc($cfg['shortcode_proxy']), '{imsi}' => $esc($p['ctx']['imsi'] ?? ''), '{local_dialog_id}' => isset($p['ctx']['localDialogID']) ? (string)$p['ctx']['localDialogID'] : 'null', '{remote_dialog_id}' => isset($p['ctx']['remoteDialogID']) ? (string)$p['ctx']['remoteDialogID'] : 'null',
        '{local_address_json}' => json_encode($p['ctx']['localAddress'] ?? null, JSON_UNESCAPED_SLASHES), '{remote_address_json}' => json_encode($p['ctx']['remoteAddress'] ?? null, JSON_UNESCAPED_SLASHES)]);
    $r = ussd_hera_post($cfg, $body, rtrim((string)$cfg['share_base'], '/').'/'.SHARE_PATHS[$op]); $r['sent'] = $body;
    $j = json_decode((string)$r['raw'], true); $res = is_array($j) && is_array($j['result'] ?? null) ? $j['result'] : [];
    $code = isset($res['resultCode']) ? (string)$res['resultCode'] : (isset($j['resultCode']) ? ($j['resultCode'] === '000' ? $cfg['share_ok_code'] : (string)$j['resultCode']) : '');
    $r['ok'] = $r['error'] === '' && $r['code'] >= 200 && $r['code'] < 300 && $code !== '' && $code === (string)$cfg['share_ok_code'];
    // a server error page ("status 500 …") is not something to show a customer: only a successful HTTP answer has text for them
    $r['text'] = ($r['error'] === '' && $r['code'] >= 200 && $r['code'] < 300) ? ussd_share_text($j, (string)$r['raw']) : '';
    // Balance: Hera lists the bundles as result.package1, package2 … (empty ones blank): "Your Seddo bundle balance is:" and one line each
    if ($op === 'balance' && $r['ok'] && is_array($j['result'] ?? null)) {
        $text = 'Your Seddo bundle balance is:'; $any = false;
        for ($i = 1; $i <= 9; $i++) {
            $v = $j['result']['package'.$i] ?? ''; if (!is_string($v) || trim($v) === '') continue;
            if (mb_strlen($text."\n".trim($v)) > 150) break; // one screen: drop what does not fit
            $text .= "\n".trim($v); $any = true;
        }
        $r['text'] = $any ? $text : 'You have no active Seddo bundle balance.';
    }
    // My numbers: result.msisdn1 … msisdn9, each {order, msisdn, separator1, limit, usage}; unused rows are blank.
    // One line per used row, "{order} {msisdn} {separator1} {limit}" as in the diagram; Hera puts "You don't have Seddo number" in the first row's order when there are none.
    if ($op === 'numbers' && $r['ok'] && is_array($j['result'] ?? null)) {
        $lines = [];
        for ($i = 1; $i <= 9; $i++) {
            $row = $j['result']['msisdn'.$i] ?? null; if (!is_array($row)) continue;
            $line = trim(implode(' ', array_filter(array_map(fn($k) => $k === 'msisdn' ? ussd_local_number((string)($row[$k] ?? '')) : trim((string)($row[$k] ?? '')), ['order', 'msisdn', 'separator1', 'limit']), fn($v) => $v !== '')));
            if ($line === '') continue;
            if (mb_strlen(implode("\n", array_merge($lines, [$line]))) > 150) break;
            $lines[] = $line;
        }
        $r['text'] = $lines ? implode("\n", $lines) : "You don't have a Seddo number.";
    }
    return $r;
}
// What to show a customer from Hera's reply: its own description when it has one, otherwise the reply's values laid out as lines.
function ussd_share_text($j, string $raw): string {
    $clip = fn(string $s) => mb_substr(trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\R+/', "\n", trim($s)))), 0, 150);
    if (!is_array($j)) return $clip(strip_tags($raw));
    $desc = $j['result']['resultDescription'] ?? null;
    // Hera also answers with the reason at the top level, e.g. {"resultCode":"9990","resultDescription":"You don't have active Seddo Subscription"}
    if ($desc === null && isset($j['resultCode']) && (string)$j['resultCode'] !== '000' && is_string($j['resultDescription'] ?? null)) $desc = $j['resultDescription'];
    $payload = $j['result'] ?? $j; $lines = [];
    $walk = function ($v, $key = '') use (&$walk, &$lines) {
        // a list of records (e.g. the sharing numbers) becomes one line per record: "1 220111 2GB"
        if (is_array($v) && array_is_list($v) && $v && is_array($v[0])) { foreach ($v as $rec) { $vals = []; array_walk_recursive($rec, function ($x) use (&$vals) { if ($x !== null && $x !== '') $vals[] = $x; }); if ($vals) $lines[] = implode(' ', $vals); } return; }
        if (is_array($v)) { foreach ($v as $k => $x) $walk($x, is_string($k) ? $k : $key); return; }
        if ($v === null || $v === '' || $key === 'resultCode' || $key === 'resultDescription') return;
        $lines[] = (is_string($key) && $key !== '' ? $key.': ' : '').$v;
    };
    $walk($payload);
    $parts = []; if (is_string($desc) && trim($desc) !== '') $parts[] = trim($desc);
    if ($lines) $parts[] = implode("\n", array_slice($lines, 0, 8));
    return $clip(implode("\n", $parts));
}
// Read-only calls used while the customer browses (validating a number, balance, numbers).
function ussd_share_read(string $op, array $p): array {
    static $cfg = null; $cfg ??= ussd_proxy_config();
    if ($cfg['share_mode'] === 'off') return ['ok' => false, 'text' => 'Shared Bundle is not switched on yet.'];
    if ($cfg['share_mode'] === 'test') return ussd_share_sim($op, $p);
    if ($op === 'validate' && trim((string)$cfg['share_body_validate']) === '') return ['ok' => true, 'text' => ''];
    if (trim((string)$cfg['share_body_'.$op]) === '') return ['ok' => false, 'text' => 'This is not switched on yet.'];
    $p['txn'] = 'sb-'.bin2hex(random_bytes(4));
    $key = 'share:'.$op.':'.($p['msisdn'] ?? '').':'.($p['other'] ?? '');
    $r = cached($key, 30, fn() => ussd_share_http($cfg, $op, $p));
    if ($op === 'validate') return ['ok' => (bool)$r['ok'], 'text' => $r['ok'] ? '' : ($r['text'] !== '' ? $r['text'] : 'This number cannot be added.')];
    return ['ok' => (bool)$r['ok'], 'text' => $r['text'] !== '' ? $r['text'] : ($r['error'] !== '' ? 'Not available right now.' : '')];
}
// subscribe / add number: carried out once per call (same ledger as purchases, so it shows in the same table).
function ussd_share_execute(array $cfg, array $screen, string $msisdn, string $callId, array $ctx = []): array {
    $p = $screen['share']; $op = $p['op']; $msisdn = preg_replace('/\D+/', '', $msisdn); $mode = $cfg['share_mode'];
    $label = $op === 'subscribe' ? 'Seddo '.$p['name'] : 'the number '.ussd_local_number((string)($p['other'] ?? ''));
    if ($mode === 'off') return ['text' => 'Shared Bundle is not switched on yet. You were not charged.', 'note' => 'share: off'];
    if ($msisdn === '') return ['text' => 'Sorry, we could not process this request. You were not charged.', 'note' => 'share: no number'];
    $key = $op === 'subscribe' ? 'share:sub:'.$p['offer_code'] : 'share:add:'.$p['other']; $t0 = microtime(true);
    try {
        ussd_purchase_table(); $db = portal_pdo(); $callId = $callId !== '' ? substr($callId, 0, 120) : 'noid-'.bin2hex(random_bytes(6));
        $ins = $db->prepare("INSERT IGNORE INTO ussd_purchases(call_id,offer_code,msisdn,recipient,offer_name,price,mode,status) VALUES(?,?,?,?,?,?,?,'pending')");
        $ins->execute([$callId, substr($key, 0, 45), $msisdn, $op === 'add' ? $p['other'] : null, mb_substr($op === 'subscribe' ? $p['name'] : 'Add sharing number', 0, 80), mb_substr((string)($p['price'] ?? ''), 0, 20), 'sb-'.$mode]);
        if ($ins->rowCount() === 0) { $st = $db->prepare('SELECT reply_text FROM ussd_purchases WHERE call_id=? AND offer_code=?'); $st->execute([$callId, substr($key, 0, 45)]); return ['text' => (string)($st->fetchColumn() ?: 'Your request is already being processed.'), 'note' => 'share: repeated request, not sent again']; }
        $id = (int)$db->lastInsertId(); $http = null; $resp = null; $sent = null;
        if ($mode === 'test') { $status = 'test'; $text = 'TEST: '.($op === 'subscribe' ? 'you would be subscribed to '.$label : $label.' would be added').'. You were not charged.'; }
        elseif (trim((string)$cfg['share_body_'.$op]) === '') { $status = 'blocked'; $text = 'This part of Shared Bundle is not switched on yet. You were not charged.'; }
        else {
            $r = ussd_share_http($cfg, $op, ['ctx' => $ctx, 'msisdn' => $msisdn, 'other' => $p['other'] ?? '', 'other_raw' => $p['other_raw'] ?? '', 'offer_code' => $p['offer_code'] ?? '', 'vendor' => $p['vendor'] ?? '', 'other_offer_code' => $p['other_offer_code'] ?? '', 'price' => $p['price'] ?? '', 'txn' => $callId]);
            $http = $r['code']; $resp = $r['error'] !== '' ? 'error: '.$r['error'] : mb_substr((string)$r['raw'], 0, 1000); $sent = ussd_request_for_log((string)($r['sent'] ?? ''));
            $low = false; foreach (array_filter(array_map('trim', explode(',', (string)$cfg['purchase_lowbal']))) as $term) if (stripos((string)$r['raw'], $term) !== false) { $low = true; break; }
            $status = $r['ok'] ? 'ok' : ($low ? 'lowbal' : 'failed');
            // low balance gets a plain message; any other refusal shows Hera's own reason when it gave one
            $text = $low ? 'Sorry, your balance is too low for this bundle. Please top up and try again.' : ($r['text'] !== '' ? $r['text'] : ($r['ok'] ? 'Done.' : 'Sorry, this could not be completed. Please try again later.'));
        }
        $db->prepare('UPDATE ussd_purchases SET status=?, http_code=?, response=?, reply_text=?, ms=?, request_body=? WHERE id=?')->execute([$status, $http, $resp, mb_substr($text, 0, 255), (int)round((microtime(true) - $t0) * 1000), $sent, $id]);
        return ['text' => $text, 'note' => 'share: '.$op.' '.$mode.' '.$status.($http ? ' HTTP '.$http : '')];
    } catch (Throwable $e) {
        error_log('ussd share: '.$e->getMessage());
        return ['text' => 'Sorry, we could not process this request. Please try again later.', 'note' => 'share error: '.substr($e->getMessage(), 0, 100)];
    }
}
function save_share_config(array $d): void {
    $mode = in_array($d['share_mode'] ?? '', ['test', 'live'], true) ? $d['share_mode'] : 'off';
    $base = trim((string)($d['share_base'] ?? ''));
    if (!preg_match('#^https?://#i', $base) || !filter_var($base, FILTER_VALIDATE_URL) || mb_strlen($base) > 300) throw new RuntimeException('The ShareBundle address must be a full http:// or https:// URL, e.g. https://vas-testing.comium.gm/hera/prepaid/ShareBundle/');
    $ok = trim((string)($d['share_ok_code'] ?? '')); if ($ok === '' || mb_strlen($ok) > 12) throw new RuntimeException('Enter the result code that means success (e.g. 0).');
    $vals = ['share_mode' => $mode, 'share_base' => rtrim($base, '/').'/', 'share_ok_code' => $ok];
    foreach (['subscribe', 'validate', 'add', 'balance', 'numbers'] as $op) {
        $t = trim((string)($d['share_body_'.$op] ?? '')); if (mb_strlen($t) > 2000) throw new RuntimeException('A request body is at most 2000 characters.');
        $vals['share_body_'.$op] = $t;
    }
    ussd_proxy_set($vals);
    audit('ussd_share_save', null, 'ussd_proxy_config', null, 'mode='.$mode.' base='.parse_url($base, PHP_URL_HOST));
}
// A read-only question about the account (balance / numbers), to see what Hera's reply really looks like.
function share_ask(array $cfg, string $op, string $msisdn): array {
    $msisdn = preg_replace('/\D+/', '', $msisdn);
    if (!in_array($op, ['balance', 'numbers'], true)) throw new RuntimeException('Only balance and numbers can be asked from here.');
    if ($msisdn === '') throw new RuntimeException('Enter a phone number.');
    if (trim((string)$cfg['share_body_'.$op]) === '') throw new RuntimeException('Save a request body for "'.$op.'" first.');
    $r = ussd_share_http($cfg, $op, ['msisdn' => $msisdn, 'txn' => 'check-'.bin2hex(random_bytes(4))]);
    audit('share_ask', null, 'ussd_proxy_config', null, $op.' HTTP '.$r['code']);
    return ['code' => $r['code'], 'error' => $r['error'], 'raw' => mb_substr((string)$r['raw'], 0, 3000), 'text' => $r['text'], 'ok' => $r['ok']];
}
// A read-only question to Hera, like the ones the phone menu asks while you browse: what it says about one offer
// (chooseOffer) or one sub-category (listOffer). It can never buy: the operation is fixed to these two.
function hera_ask(array $cfg, string $op, string $msisdn, string $offerCode, string $subCategory): array {
    $msisdn = preg_replace('/\D+/', '', $msisdn);
    if (!in_array($op, ['chooseOffer', 'listOffer'], true)) throw new RuntimeException('Only chooseOffer and listOffer can be asked from here.');
    if ($msisdn === '') throw new RuntimeException('Enter a phone number.');
    $body = ['callID' => 'check-'.bin2hex(random_bytes(5)), 'originalRequest' => $cfg['shortcode_proxy'], 'msisdn' => $msisdn, 'isMobileOriginated' => true, 'mobileRequestIdentifier' => 1, 'isInitial' => false, 'isProxy' => false];
    if ($op === 'chooseOffer') { if (!preg_match('/^[A-Za-z0-9_.\-]{1,45}$/', $offerCode)) throw new RuntimeException('Enter the offer code.'); $body['offerCode'] = $offerCode; }
    else { if ($subCategory === '' || mb_strlen($subCategory) > 80) throw new RuntimeException('Enter the sub-category.'); $body += ['subCategory' => $subCategory, 'category' => 'Data-commercial-Launch', 'page' => '0', 'limitDisplay' => '9']; }
    $body['operation'] = $op;
    $r = ussd_hera_post($cfg, (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    audit('hera_ask', null, 'ussd_proxy_config', null, $op.' '.($op === 'chooseOffer' ? $offerCode : $subCategory).' HTTP '.$r['code']);
    return ['sent' => $body, 'code' => $r['code'], 'error' => $r['error'], 'raw' => mb_substr((string)$r['raw'], 0, 3000)];
}
function save_purchase_config(array $d): void {
    $mode = in_array($d['purchase_mode'] ?? '', ['test', 'test_low', 'live'], true) ? $d['purchase_mode'] : 'off';
    $url = trim((string)($d['purchase_url'] ?? ''));
    if ($url !== '' && (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 500)) throw new RuntimeException('The purchase address must be a full http:// or https:// URL.');
    if ($mode === 'live' && $url === '') throw new RuntimeException('Live purchases need the purchase address. Use Test mode until you have it.');
    $body = (string)($d['purchase_body'] ?? ''); if (trim($body) === '' || mb_strlen($body) > 2000) throw new RuntimeException('The request body must be filled in (at most 2000 characters).');
    $timeout = filter_var($d['purchase_timeout'] ?? null, FILTER_VALIDATE_INT); if ($timeout === false || $timeout < 2 || $timeout > 15) throw new RuntimeException('The purchase timeout must be between 2 and 15 seconds.');
    $match = trim((string)($d['purchase_ok_match'] ?? '')); if (mb_strlen($match) > 100) throw new RuntimeException('The success text is at most 100 characters.');
    $low = trim((string)($d['purchase_lowbal'] ?? '')); if (mb_strlen($low) > 200) throw new RuntimeException('The low-balance words are at most 200 characters.');
    $bodyOther = trim((string)($d['purchase_body_other'] ?? '')); if (mb_strlen($bodyOther) > 2000) throw new RuntimeException('The request body for another number is at most 2000 characters.');
    $field = trim((string)($d['purchase_reply_field'] ?? '')); if (!preg_match('/^[A-Za-z0-9_.\-]{0,40}$/', $field)) throw new RuntimeException('The reply field is just a name, e.g. message.');
    $vals = ['purchase_mode' => $mode, 'purchase_url' => $url, 'purchase_body' => $body, 'purchase_timeout' => (string)$timeout, 'purchase_ok_match' => $match, 'purchase_lowbal' => $low, 'purchase_reply_field' => $field, 'purchase_body_other' => $bodyOther, 'purchase_other_seeded' => '1'];
    $auth = trim((string)($d['purchase_auth'] ?? ''));
    if (!empty($d['purchase_auth_clear'])) $vals['purchase_auth'] = '';
    elseif ($auth !== '') {
        // Forgiving about what a paste brings along: a comma or quotes on the end, odd spaces or dashes, and headers with
        // no value (Mobius sends X-HASHED-PASSWORD empty — such a line is simply skipped). A wrong line is reported by
        // number only, never echoed back, because it may hold the key itself.
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $auth) as $n => $l) {
            $l = trim(str_replace(["\xC2\xA0", "\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x93", "\xE2\x80\x94"], [' ', '-', '-', '-', '-'], $l));
            if ($l === '') continue;
            if (!preg_match('/^([A-Za-z0-9\-]{1,40})\s*:\s*(.*)$/', $l, $m)) throw new RuntimeException('Line '.($n + 1).' of the headers has no "Name: value" shape — write each one like  X-USERNAME: USSD');
            $v = trim(rtrim(trim($m[2]), ','), " \t\"'");
            if ($v === '') continue;
            if (mb_strlen($v) > 400) throw new RuntimeException('The value on line '.($n + 1).' of the headers is too long.');
            $lines[] = $m[1].': '.$v;
        }
        if (count($lines) > 6) throw new RuntimeException('At most 6 headers.');
        if (!$lines) throw new RuntimeException('None of the header lines had a value. Write them like  X-API-KEY: your-key');
        $vals['purchase_auth'] = encrypt_secret(implode("\n", $lines));
    }
    ussd_proxy_set($vals);
    audit('ussd_purchase_save', null, 'ussd_proxy_config', null, 'mode='.$mode.' url='.($url !== '' ? parse_url($url, PHP_URL_HOST) : '-').' header_changed='.(isset($vals['purchase_auth']) ? 'yes' : 'no'));
}
function ussd_result(string $text, bool $end, array $path, string $kind, ?array $node = null): array {
    return ['text' => $text, 'end' => $end, 'path' => $path, 'kind' => $kind, 'node_id' => $node['id'] ?? null, 'chars' => mb_strlen($text), 'too_long' => mb_strlen($text) > USSD_MAX_CHARS];
}

// ===================== USSD proxy endpoint =====================
// public/ussd.php answers Mobius PROXY / MS_INITIATED menus. It is OFF until an admin enables it on the USSD Proxy page
// and then starts in CAPTURE mode (records what Mobius sends, replies with a fixed text) so the real request format can
// be read from the log; in LIVE mode it maps the request's fields (configured there) onto the screen engine.
// What Hera's /hera/VasOffers expects, copied from the request Mobius's own menu sends it (operation purchaseOffer).
const USSD_PURCHASE_BODY = '{"callID":"{txn}","originalRequest":"{shortcode}","localAddress":{local_address_json},"remoteAddress":{remote_address_json},"msisdn":"{msisdn}","imsi":"{imsi}","localDialogID":{local_dialog_id},"remoteDialogID":{remote_dialog_id},"isMobileOriginated":true,"mobileRequestIdentifier":1,"isInitial":false,"isProxy":false,"chargesWithCurrency":"{price_d}","offerCode":"{offer_code}","vendor":"{vendor}","channel":"USSD","otherOfferCode":"{other_offer_code}","operation":"purchaseOffer"}';
const USSD_PURCHASE_BODY_V2 = '{"callID":"{txn}","originalRequest":"{shortcode}","msisdn":"{msisdn}","isMobileOriginated":true,"mobileRequestIdentifier":1,"isInitial":false,"isProxy":false,"chargesWithCurrency":"{price_d}","offerCode":"{offer_code}","vendor":"{vendor}","channel":"USSD","otherOfferCode":"{other_offer_code}","operation":"purchaseOffer"}';
const SHARE_CTX = '"callID":"{txn}","originalRequest":"{shortcode}","localAddress":{local_address_json},"remoteAddress":{remote_address_json},"msisdn":"{msisdn}","imsi":"{imsi}","localDialogID":{local_dialog_id},"remoteDialogID":{remote_dialog_id},"isMobileOriginated":true,"mobileRequestIdentifier":1,"isInitial":false,"isProxy":false';
const SHARE_BODY_READ = '{'.SHARE_CTX.',"channel":"USSD"}';
// listNumber (from Mobius's log) carries the vendor as well
const SHARE_BODY_NUMBERS = '{'.SHARE_CTX.',"vendor":"huawei","channel":"USSD"}';
// Both copied from Mobius's own Shared Bundle requests (its log): subscribe adds the price (D600), the offer code and operation purchaseOffer.
const SHARE_BODY_SUBSCRIBE_V2 = '{'.SHARE_CTX.',"vendor":"{vendor}","channel":"USSD","chargesWithCurrency":"{price_d}","offerCode":"{offer_code}"}';
const SHARE_BODY_SUBSCRIBE = '{'.SHARE_CTX.',"chargesWithCurrency":"{price_d}","offerCode":"{offer_code}","vendor":"huawei","channel":"USSD","operation":"purchaseOffer"}';
const SHARE_BODY_ADD = '{'.SHARE_CTX.',"vendor":"huawei","channel":"USSD","otherMsisdn":"{other_msisdn}"}';
// earlier defaults, replaced automatically when they were never edited
const SHARE_OLD_DEFAULTS = [
    SHARE_BODY_SUBSCRIBE_V2,
    '{"callID":"{txn}","msisdn":"{msisdn}","channel":"USSD"}',
    '{"callID":"{txn}","msisdn":"{msisdn}","offerCode":"{offer_code}","vendor":"{vendor}","chargesWithCurrency":"{price_d}","channel":"USSD"}',
    '{"callID":"{txn}","msisdn":"{msisdn}","otherMsisdn":"{other_msisdn}","channel":"USSD"}',
];
// Buying for another number (from Mobius's own log): the same purchaseOffer, the base offerCode and its otherOfferCode, plus otherMsisdn exactly as the customer typed it.
const USSD_PURCHASE_BODY_OTHER = '{"callID":"{txn}","originalRequest":"{shortcode}","localAddress":{local_address_json},"remoteAddress":{remote_address_json},"msisdn":"{msisdn}","imsi":"{imsi}","localDialogID":{local_dialog_id},"remoteDialogID":{remote_dialog_id},"isMobileOriginated":true,"mobileRequestIdentifier":1,"isInitial":false,"isProxy":false,"chargesWithCurrency":"{price_d}","offerCode":"{offer_code}","vendor":"{vendor}","channel":"USSD","otherMsisdn":"{other_msisdn}","otherOfferCode":"{other_offer_code}","operation":"purchaseOffer"}';
const USSD_PURCHASE_BODY_V1 = '{"msisdn":"{msisdn}","offer_code":"{offer_code}","transaction_id":"{txn}","channel":"USSD"}';
// Refund: Hera BundleSubscription with channel REF — {offer_code} {date} (when it was bought) {vendor} {msisdn_local} (the number that got the bundle) {buyer_local} {txn}
const USSD_REFUND_BODY = '{"offerCode":"{offer_code}","date":"{date}","vendor":"{vendor}","msisdn":"{msisdn_local}","channel":"REF"}';
const USSD_PROXY_DEFAULTS = [
    'refund_mode' => 'off', 'refund_url' => 'https://vas-preprod.comium.gm/hera/prepaid/BundleSubscription', 'refund_body' => USSD_REFUND_BODY,
    'enabled' => '0', 'token' => '', 'mode' => 'capture', 'allow_ips' => '',
    'shortcode_proxy' => '*9606*9090#', 'shortcode_ms_initiated' => '*9606*9090#',
    'f_msisdn' => '', 'f_session' => '', 'f_input' => '', 'f_shortcode' => '',
    'reply_mode' => 'step', 'session_ttl' => '180',
    'resp_type' => 'text/plain; charset=UTF-8', 'resp_body' => '{text}', 'end_true' => 'true', 'end_false' => 'false',
    'capture_body' => 'VAS Cloud test endpoint: request received.',
    // PROXY menus: Mobius sends each reply here and expects the screen to come back through ITS REST API
    'push_enabled' => '0', 'mobius_base' => 'http://192.168.162.20:28080/rest/', 'mobius_user' => '', 'mobius_pass' => '', 'mobius_session' => '', 'mobius_variant' => '0',
    // buying an offer from the menu (see ussd_execute_purchase): off | test | live
    'purchase_mode' => 'off', 'purchase_url' => '', 'purchase_body' => USSD_PURCHASE_BODY,
    'purchase_auth' => '', 'purchase_ok_match' => '', 'purchase_timeout' => '8', 'purchase_lowbal' => 'insufficient,low balance,not enough', 'purchase_reply_field' => '', 'purchase_body_other' => '', 'purchase_other_seeded' => '0',
    // Shared Bundle (Seddo)
    'share_mode' => 'test', 'share_base' => 'https://vas-testing.comium.gm/hera/prepaid/ShareBundle/', 'share_ok_code' => '0',
    'share_body_subscribe' => SHARE_BODY_SUBSCRIBE, 'share_body_validate' => '', 'share_body_add' => SHARE_BODY_ADD, 'share_body_balance' => SHARE_BODY_READ, 'share_body_numbers' => SHARE_BODY_NUMBERS,
];
function ussd_proxy_config(): array {
    $cfg = USSD_PROXY_DEFAULTS;
    try { foreach (portal_pdo()->query('SELECT name,value FROM ussd_proxy_config')->fetchAll() as $r) if (array_key_exists($r['name'], $cfg)) $cfg[$r['name']] = (string)$r['value']; }
    catch (Throwable $e) {}
    if (in_array($cfg['purchase_body'], [USSD_PURCHASE_BODY_V1, USSD_PURCHASE_BODY_V2], true)) $cfg['purchase_body'] = USSD_PURCHASE_BODY; // an earlier default, never edited
    // until the purchase card has been saved once, a blank "for another number" body means "never set": use the one copied from Mobius
    if ($cfg['purchase_other_seeded'] !== '1' && trim((string)$cfg['purchase_body_other']) === '') $cfg['purchase_body_other'] = USSD_PURCHASE_BODY_OTHER;
    foreach (['share_body_subscribe' => SHARE_BODY_SUBSCRIBE, 'share_body_add' => SHARE_BODY_ADD, 'share_body_balance' => SHARE_BODY_READ, 'share_body_numbers' => SHARE_BODY_NUMBERS] as $k => $new) if (in_array($cfg[$k], SHARE_OLD_DEFAULTS, true) || ($k === 'share_body_numbers' && $cfg[$k] === SHARE_BODY_READ)) $cfg[$k] = $new;
    return $cfg;
}
function ussd_proxy_set(array $vals): void {
    $st = portal_pdo()->prepare('INSERT INTO ussd_proxy_config(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
    foreach ($vals as $k => $v) $st->execute([$k, (string)$v]);
}
function ussd_proxy_valid_ips(string $list): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $x) {
        $ip = explode('/', $x)[0]; $bits = explode('/', $x)[1] ?? null;
        if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int)$bits > (str_contains($ip, ':') ? 128 : 32)))) throw new RuntimeException('"'.$x.'" is not a valid IP address or range (use e.g. 192.168.162.20 or 192.168.162.0/24).');
        $out[] = $x;
    }
    return $out;
}
function ussd_proxy_ip_allowed(string $list, string $ip): bool {
    $rules = ussd_proxy_valid_ips($list);
    if (!$rules) return true;
    foreach ($rules as $r) {
        [$net, $bits] = array_pad(explode('/', $r), 2, null);
        if ($bits === null) { if ($ip === $net) return true; continue; }
        $a = @inet_pton($ip); $n = @inet_pton($net); if ($a === false || $n === false || strlen($a) !== strlen($n)) continue;
        $full = intdiv((int)$bits, 8); $rem = (int)$bits % 8;
        if (substr($a, 0, $full) !== substr($n, 0, $full)) continue;
        if ($rem === 0 || ((ord($a[$full]) ^ ord($n[$full])) >> (8 - $rem)) === 0) return true;
    }
    return false;
}
function save_ussd_proxy_config(array $d): void {
    $field = function (string $k) use ($d): string {
        $v = trim((string)($d[$k] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.\-\[\]:]{0,80}$/', $v)) throw new RuntimeException('Field names may only contain letters, digits and _ . - [ ] :');
        return $v;
    };
    $code = function (string $k) use ($d): string {
        $v = trim((string)($d[$k] ?? ''));
        if ($v === '' || mb_strlen($v) > 80) throw new RuntimeException('Enter the short code to serve (up to 80 characters).');
        return $v;
    };
    $text = function (string $k, int $max, bool $required) use ($d): string {
        $v = (string)($d[$k] ?? '');
        if (mb_strlen($v) > $max || ($required && trim($v) === '')) throw new RuntimeException('"'.$k.'" must be '.($required ? 'filled in and ' : '').'at most '.$max.' characters.');
        return $v;
    };
    $ctype = trim((string)($d['resp_type'] ?? ''));
    if (!preg_match('#^[A-Za-z0-9.+\-]+/[A-Za-z0-9.+\-]+(;\s*charset=[A-Za-z0-9\-]+)?$#', $ctype)) throw new RuntimeException('Reply content type should look like text/plain; charset=UTF-8 or application/json.');
    $ips = trim((string)($d['allow_ips'] ?? '')); ussd_proxy_valid_ips($ips);
    $mode = ($d['mode'] ?? '') === 'live' ? 'live' : 'capture';
    $ttl = filter_var($d['session_ttl'] ?? null, FILTER_VALIDATE_INT); if ($ttl === false || $ttl < 30 || $ttl > 900) throw new RuntimeException('Session timeout must be between 30 and 900 seconds.');
    ussd_proxy_set([
        'enabled' => empty($d['enabled']) ? '0' : '1', 'mode' => $mode, 'allow_ips' => $ips,
        'shortcode_proxy' => $code('shortcode_proxy'), 'shortcode_ms_initiated' => $code('shortcode_ms_initiated'),
        'f_msisdn' => $field('f_msisdn'), 'f_session' => $field('f_session'), 'f_input' => $field('f_input'), 'f_shortcode' => $field('f_shortcode'),
        'reply_mode' => ($d['reply_mode'] ?? '') === 'cumulative' ? 'cumulative' : 'step', 'session_ttl' => (string)$ttl,
        'resp_type' => $ctype, 'resp_body' => $text('resp_body', 4000, true), 'end_true' => $text('end_true', 40, false), 'end_false' => $text('end_false', 40, false),
        'capture_body' => $text('capture_body', 2000, true),
    ]);
    audit('ussd_proxy_save', null, 'ussd_proxy_config', null, 'enabled='.(empty($d['enabled']) ? 0 : 1).' mode='.$mode);
}
function ussd_proxy_token(bool $create = true): string {
    $t = ussd_proxy_config()['token'];
    if ($t === '' && $create) { $t = bin2hex(random_bytes(20)); ussd_proxy_set(['token' => $t]); }
    return $t;
}
function ussd_proxy_rotate_token(): string {
    $t = bin2hex(random_bytes(20)); ussd_proxy_set(['token' => $t]);
    audit('ussd_proxy_token_rotated', null, 'ussd_proxy_config', null, 'rotated');
    return $t;
}
// Flattens whatever Mobius sent (query string, form fields, JSON or XML body) into name => value, nested names joined by '.'.
function ussd_proxy_flatten(string $contentType, string $raw, array $get, array $post): array {
    $flat = [];
    $put = function ($key, $v) use (&$flat, &$put) {
        if (is_array($v)) { foreach ($v as $k => $x) $put($key === '' ? (string)$k : $key.'.'.$k, $x); return; }
        if (is_bool($v)) $v = $v ? 'true' : 'false';
        $flat[(string)$key] = (string)$v;
    };
    foreach ($get as $k => $v) $put((string)$k, $v);
    foreach ($post as $k => $v) $put((string)$k, $v);
    $t = ltrim($raw);
    if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) { $j = json_decode($t, true); if (is_array($j)) $put('', $j); }
    elseif ($t !== '' && $t[0] === '<' && function_exists('simplexml_load_string')) {
        libxml_use_internal_errors(true);
        $x = simplexml_load_string($t, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if ($x !== false) foreach ($x->xpath('//*[not(*)]') ?: [] as $el) { $name = $el->getName(); $val = trim((string)$el); if ($val !== '' && !isset($flat[$name])) $flat[$name] = $val; }
    }
    elseif ($t !== '' && !$post && str_contains($t, '=') && !preg_match('/\s/', $t)) { parse_str($t, $q); $put('', $q); }
    return $flat;
}
function ussd_proxy_render(array $cfg, array $screen, string $session, string $msisdn): string {
    $json = fn($s) => substr((string)json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    return strtr($cfg['resp_body'], [
        '{text}' => $screen['text'], '{text_json}' => $json($screen['text']), '{text_xml}' => htmlspecialchars($screen['text'], ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        '{text_url}' => rawurlencode($screen['text']), '{end}' => $screen['end'] ? $cfg['end_true'] : $cfg['end_false'], '{end_int}' => $screen['end'] ? '1' : '0',
        '{con_end}' => $screen['end'] ? 'END' : 'CON', '{session}' => $session, '{msisdn}' => $msisdn,
    ]);
}
// $mode: 'proxy' | 'ms_initiated'. $dry: don't store session state. $forceLive: ignore capture mode (the admin page's tester).
function ussd_proxy_process(array $cfg, string $mode, array $flat, bool $dry = false, bool $forceLive = false): array {
    if ($cfg['mode'] !== 'live' && !$forceLive) return ['ctype' => 'text/plain; charset=UTF-8', 'body' => $cfg['capture_body'], 'note' => 'capture mode', 'screen' => null];
    $val = fn(string $f) => ($f !== '' && isset($flat[$f])) ? trim($flat[$f]) : '';
    $msisdn = $val($cfg['f_msisdn']); $sessionId = $val($cfg['f_session']); $input = $val($cfg['f_input']);
    $sc = $val($cfg['f_shortcode']) ?: $cfg['shortcode_'.$mode];
    $note = [];
    if ($cfg['f_input'] === '') $note[] = 'input field not set';
    if ($cfg['reply_mode'] === 'cumulative') {
        $s = trim($input, "#* "); $parts = $s === '' ? [] : explode('*', $s);
        $tok = array_values(array_filter(explode('*', trim($sc, '#*')), fn($x) => $x !== ''));
        if ($tok && array_slice($parts, 0, count($tok)) === $tok) $parts = array_slice($parts, count($tok));
        $replies = $parts; $key = null;
    } else {
        $key = substr($sessionId !== '' ? $sessionId : ($msisdn !== '' ? 'msisdn:'.$msisdn : ''), 0, 120);
        $replies = [];
        if ($key !== '') {
            $st = portal_pdo()->prepare('SELECT replies FROM ussd_proxy_sessions WHERE session_key=? AND shortcode=? AND updated_at >= NOW() - INTERVAL '.(int)$cfg['session_ttl'].' SECOND');
            $st->execute([$key, $sc]); $row = $st->fetchColumn();
            if ($row !== false) { $replies = json_decode((string)$row, true) ?: []; if ($input !== '') $replies[] = $input; }
        } else $note[] = 'no session or msisdn field set — every request starts again from the first screen';
    }
    $hk = ['seed' => $sessionId !== '' ? $sessionId : (string)$key, 'msisdn' => $msisdn]; $mkScreen = fn() => ussd_screen($sc, array_slice($replies, -30), ['active'], null, $hk);
    $screen = $mkScreen();
    for ($fi = 0; $fi < 5 && ($screen['kind'] ?? '') === 'flow_call' && !$dry; $fi++) { flow_execute_call(flow_get($screen['flow_key']), $screen['flow_pending'], $hk['seed'], $msisdn, [], $sc); $screen = $mkScreen(); }
    if (in_array($screen['kind'] ?? '', ['share_subscribe', 'share_add'], true)) {
        if ($dry) $note[] = 'not sent (test run)';
        else { $sr = ussd_share_execute($cfg, $screen, $msisdn, $sessionId !== '' ? $sessionId : (string)$key); $screen['text'] = $sr['text']; $note[] = $sr['note']; }
    }
    if (!$dry) ussd_quiz_record($screen, $msisdn, $sessionId !== '' ? $sessionId : (string)$key);
    if (($screen['kind'] ?? '') === 'purchase') {
        if ($dry) $note[] = 'purchase not sent (test run)';
        else { $pr = ussd_execute_purchase($cfg, $screen, $msisdn, $sessionId !== '' ? $sessionId : (string)$key); $screen['text'] = $pr['text']; $note[] = $pr['note']; }
    }
    if ($key !== null && $key !== '' && !$dry) {
        if ($screen['end']) portal_pdo()->prepare('DELETE FROM ussd_proxy_sessions WHERE session_key=?')->execute([$key]);
        else portal_pdo()->prepare('REPLACE INTO ussd_proxy_sessions(session_key,shortcode,replies,updated_at) VALUES(?,?,?,NOW())')->execute([$key, $sc, json_encode($replies)]);
    }
    return ['ctype' => $cfg['resp_type'], 'body' => ussd_proxy_render($cfg, $screen, $sessionId, $msisdn), 'note' => implode('; ', $note) ?: 'live', 'screen' => $screen, 'replies' => $replies, 'sc' => $sc];
}
function ussd_proxy_write_log(string $mode, string $ip, array $get, string $raw, string $response, int $ms, string $note): void {
    $hdr = [];
    foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_') && $k !== 'HTTP_COOKIE') $hdr[strtolower(str_replace('_', '-', substr($k, 5)))] = $k === 'HTTP_AUTHORIZATION' ? '(present)' : (string)$v;
    if (!empty($_SERVER['CONTENT_TYPE'])) $hdr['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
    try {
        $db = portal_pdo();
        $db->prepare('INSERT INTO ussd_proxy_log(mode,remote_ip,method,query_text,headers_text,body_text,response_text,ms,note) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$mode, $ip, $_SERVER['REQUEST_METHOD'] ?? '', json_encode($get, JSON_UNESCAPED_UNICODE), json_encode($hdr, JSON_UNESCAPED_UNICODE), $raw !== '' ? $raw : json_encode($_POST, JSON_UNESCAPED_UNICODE), mb_substr($response, 0, 1000), $ms, mb_substr($note, 0, 200)]);
        if (random_int(1, 30) === 1) { $db->exec('DELETE FROM ussd_proxy_log WHERE created_at < NOW() - INTERVAL 3 DAY'); $db->exec('DELETE FROM ussd_proxy_sessions WHERE updated_at < NOW() - INTERVAL 1 DAY'); }
    } catch (Throwable $e) {}
}

// ---- Mobius REST API (documented at images.mobius-software.com/apidocs: auth/login, ussdcalls/proxy) ----
function mobius_norm_base(string $u): string {
    $u = trim($u);
    if (!preg_match('#^https?://[A-Za-z0-9._-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $u)) throw new RuntimeException('"'.$u.'" is not a valid address (use e.g. http://192.168.162.20:28080/rest/).');
    $u = rtrim($u, '/'); if (!str_ends_with($u, '/rest')) $u .= '/rest';
    return $u.'/';
}
function mobius_bases(array $cfg): array {
    $out = []; foreach (preg_split('/[\s,;]+/', trim($cfg['mobius_base']), -1, PREG_SPLIT_NO_EMPTY) as $x) { try { $out[] = mobius_norm_base($x); } catch (Throwable $e) {} }
    return array_values(array_unique($out));
}
function mobius_handle(): CurlHandle {
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_COOKIEFILE => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    return $ch;
}
function mobius_http(string $url, array $body, int $timeout = 5, ?CurlHandle $ch = null, string $method = 'POST'): array {
    $own = $ch === null; if ($own) $ch = mobius_handle();
    curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    if ($method === 'GET') curl_setopt($ch, CURLOPT_HTTPGET, true); else { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($own) curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return ['code' => $code, 'raw' => (string)$raw, 'json' => is_array($j) ? $j : null, 'error' => $err];
}
function mobius_login_at(string $base, string $user, string $hash, ?CurlHandle $ch = null): array {
    $r = mobius_http($base.'auth/login', ['username' => $user, 'password' => $hash], 5, $ch);
    $sid = $r['json']['sessionID'] ?? null; $status = strtoupper((string)($r['json']['status'] ?? ''));
    if ($r['code'] === 200 && is_string($sid) && $sid !== '' && $status !== 'ERROR') return ['ok' => true, 'session' => $sid];
    return ['ok' => false, 'error' => $r['error'] !== '' ? $r['error'] : 'HTTP '.$r['code'].' '.mb_substr((string)($r['json']['errorMessage'] ?? $r['raw']), 0, 120)];
}
// The login session is cached (encrypted) per Mobius node for 25 minutes and re-created on any failure.
function mobius_session_for(array $cfg, string $base, bool $force = false): array {
    $map = json_decode(decrypt_secret($cfg['mobius_session']) ?: '{}', true) ?: [];
    if (!$force && !empty($map[$base]['sid']) && time() - (int)($map[$base]['at'] ?? 0) < 1500) return ['ok' => true, 'session' => $map[$base]['sid']];
    $hash = decrypt_secret($cfg['mobius_pass']);
    if ($cfg['mobius_user'] === '' || $hash === '') return ['ok' => false, 'error' => 'Mobius login not configured'];
    $r = mobius_login_at($base, $cfg['mobius_user'], $hash);
    if ($r['ok']) { $map[$base] = ['sid' => $r['session'], 'at' => time()]; try { ussd_proxy_set(['mobius_session' => encrypt_secret(json_encode($map))]); } catch (Throwable $e) {} }
    return $r;
}
// Mobius answers HTTP 200 with status ERROR for a refused call. These are the ways the session might have to be presented
// (the first that works is remembered in mobius_variant): 0 = sessionID + username in the body as documented,
// 1 = the same, but on the connection that logged in (so its cookie travels too). Mobius rejects a "password" field outright.
const MOBIUS_VARIANTS = ['sessionID + username', 'login cookie'];
function mobius_is_auth_error(array $r): bool {
    $m = strtolower((string)($r['json']['errorMessage'] ?? '')); $st = strtoupper((string)($r['json']['status'] ?? ''));
    return $r['code'] === 401 || $r['code'] === 403 || ($st === 'ERROR' && (str_contains($m, 'auth') || str_contains($m, 'session') || str_contains($m, 'login') || str_contains($m, 'credential')));
}
function mobius_call_variant(array $cfg, string $base, string $path, array $data, int $variant, bool $freshLogin): array {
    $hash = decrypt_secret($cfg['mobius_pass']); $cookie = $variant === 1;
    $ch = null; $sid = null;
    if ($cookie) {
        $ch = mobius_handle(); $l = mobius_login_at($base, $cfg['mobius_user'], $hash, $ch);
        if (!$l['ok']) return ['code' => 0, 'raw' => '', 'json' => null, 'error' => 'login: '.$l['error']];
        $sid = $l['session'];
    } else {
        $s = mobius_session_for($cfg, $base, $freshLogin);
        if (!$s['ok']) return ['code' => 0, 'raw' => '', 'json' => null, 'error' => 'login: '.$s['error']];
        $sid = $s['session'];
    }
    $r = mobius_http($base.$path, array_merge($data, ['sessionID' => $sid, 'username' => $cfg['mobius_user']]), 5, $ch);
    if ($ch) curl_close($ch);
    return $r;
}
function mobius_push(array $cfg, string $callId, string $text, bool $complete): array {
    $errors = []; $data = ['data' => ['callID' => $callId, 'isComplete' => $complete, 'request' => $text]];
    $pref = max(0, min(1, (int)$cfg['mobius_variant'])); $order = array_values(array_unique([$pref, 0, 1]));
    foreach (mobius_bases($cfg) as $base) {
        foreach ($order as $n => $variant) {
            $r = mobius_call_variant($cfg, $base, 'ussdcalls/proxy', $data, $variant, $n > 0);
            $bad = $r['json'] !== null && (strtoupper((string)($r['json']['status'] ?? '')) === 'ERROR' || trim((string)($r['json']['errorMessage'] ?? '')) !== '');
            if ($r['code'] === 200 && !$bad) {
                if ($variant !== $pref) { try { ussd_proxy_set(['mobius_variant' => (string)$variant]); } catch (Throwable $e) {} }
                return ['ok' => true, 'base' => $base, 'variant' => MOBIUS_VARIANTS[$variant]];
            }
            $msg = $r['error'] !== '' ? $r['error'] : mb_substr((string)($r['json']['errorMessage'] ?? $r['raw']), 0, 100);
            $errors[] = $base.' ['.MOBIUS_VARIANTS[$variant].'] HTTP '.$r['code'].' '.$msg;
            if (!mobius_is_auth_error($r)) break; // not an authentication problem: other variants won't help
        }
    }
    return ['ok' => false, 'error' => $errors ? implode(' | ', array_slice($errors, -4)) : 'no Mobius address configured'];
}
function mobius_brief(array $r): string {
    if ($r['error'] !== '') return $r['error'];
    if (!$r['json']) return 'HTTP '.$r['code'].' '.mb_substr(trim(preg_replace('/\s+/', ' ', $r['raw'])), 0, 160);
    $j = $r['json']; if (isset($j['sessionID'])) $j['sessionID'] = '…'.substr((string)$j['sessionID'], -4);
    return 'HTTP '.$r['code'].' '.mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 220);
}
function mobius_probe_ok(array $r): bool {
    return $r['code'] === 200 && !mobius_is_auth_error($r) && strtoupper((string)($r['json']['status'] ?? 'SUCCESS')) !== 'ERROR';
}
// ---- Menus inside Mobius (REST ussdmenues/*, documented in Mobius' own API docs) ----
// A menu there is {menuID, name, shortcode, shortcodeType: PROXY|MS_INITIATED|NETWORK_INITIATED, isExtendable, remoteURL, destinationID[]}.
// Mobius' list holds every live menu of the operator (airtime recharge, data usage, …), so the portal only ever CHANGES a menu whose address is
// this portal's own endpoint (it carries our token). Everything else is shown read-only. Nothing is ever deleted from here.
// One REST call, trying the nodes in order and the ways of presenting the session. A node that answered (even with a refusal) is not followed
// by the next one, so a refused write is never repeated.
function mobius_rest(array $cfg, string $path, array $data = []): array {
    $errors = []; $pref = max(0, min(1, (int)$cfg['mobius_variant'])); $order = array_values(array_unique([$pref, 0, 1]));
    foreach (mobius_bases($cfg) as $base) {
        $r = ['code' => 0, 'json' => null, 'raw' => '', 'error' => ''];
        foreach ($order as $n => $variant) {
            $r = mobius_call_variant($cfg, $base, $path, $data, $variant, $n > 0);
            $bad = $r['json'] !== null && (strtoupper((string)($r['json']['status'] ?? '')) === 'ERROR' || trim((string)($r['json']['errorMessage'] ?? '')) !== '');
            if ($r['code'] === 200 && !$bad) { if ($variant !== $pref) { try { ussd_proxy_set(['mobius_variant' => (string)$variant]); } catch (Throwable $e) {} } return ['ok' => true, 'json' => (array)$r['json'], 'base' => $base]; }
            $errors[] = $base.' HTTP '.$r['code'].' '.($r['error'] !== '' ? $r['error'] : mb_substr((string)($r['json']['errorMessage'] ?? $r['raw']), 0, 160));
            if (!mobius_is_auth_error($r)) break;
        }
        if ($r['code'] > 0) break; // this node answered: do not ask the other one to do the same
    }
    return ['ok' => false, 'error' => $errors ? implode(' | ', array_slice($errors, -3)) : 'no Mobius address configured'];
}
// Every menu in Mobius: [['ok' => true, 'menus' => […], 'count' => N|null] | ['ok' => false, 'error' => …]]. Pages by key; duplicates are ignored.
function mobius_list_menus(array $cfg): array {
    if ($cfg['mobius_user'] === '' || decrypt_secret($cfg['mobius_pass']) === '') return ['ok' => false, 'error' => 'The Mobius API user and password are not saved yet (Proxy → Connection).'];
    $all = []; $first = null; $size = 100;
    for ($page = 0; $page < 30; $page++) {
        $r = mobius_rest($cfg, 'ussdmenues/list', ['pageSize' => $size, 'includePreviousKey' => false, 'reverse' => false] + ($first !== null ? ['firstKey' => $first] : []));
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
        $rows = array_values((array)($r['json']['data'] ?? [])); $new = 0;
        foreach ($rows as $m) { $id = (string)($m['menuID'] ?? ''); if ($id !== '' && !isset($all[$id])) { $all[$id] = $m; $new++; } }
        if (count($rows) < $size || $new === 0) break;
        $first = (string)($rows[count($rows) - 1]['menuID'] ?? ''); if ($first === '') break;
    }
    $c = mobius_rest($cfg, 'ussdmenues/count', []); $count = $c['ok'] && isset($c['json']['data']) && is_numeric($c['json']['data']) ? (int)$c['json']['data'] : null;
    return ['ok' => true, 'menus' => array_values($all), 'count' => $count];
}
function mobius_menu_is_ours(array $m, array $cfg): bool {
    $u = (string)($m['remoteURL'] ?? ''); return $cfg['token'] !== '' && str_contains($u, 'ussd.php') && str_contains($u, 't='.$cfg['token']);
}
// How a portal short code stands in Mobius: ['state' => missing|ours|elsewhere, 'menu' => row|null]. "elsewhere" = a menu with that short code exists but
// does not call this portal (so the portal will not touch it).
function mobius_menu_state(string $code, array $menus, array $cfg): array {
    $bare = rtrim($code, '#'); // Mobius holds some codes without the # (*16, *9990*): *16 and *16# are the same dial
    foreach ($menus as $m) if (rtrim(trim((string)($m['shortcode'] ?? '')), '#') === $bare) return ['state' => mobius_menu_is_ours($m, $cfg) ? 'ours' : 'elsewhere', 'menu' => $m];
    return ['state' => 'missing', 'menu' => null];
}
// The menu to copy the address and destinations from: one of ours (the one for the main short code first).
function mobius_template_menu(array $menus, array $cfg): ?array {
    $ours = array_values(array_filter($menus, fn($m) => mobius_menu_is_ours($m, $cfg) && ($m['shortcodeType'] ?? '') === 'PROXY' && trim((string)($m['remoteURL'] ?? '')) !== ''));
    foreach ($ours as $m) if (trim((string)$m['shortcode']) === $cfg['shortcode_proxy']) return $m;
    return $ours[0] ?? null;
}
// Create a PROXY menu for a short code in Mobius, calling this portal. Refuses a short code Mobius already has.
function mobius_create_menu(array $cfg, string $name, string $code, bool $extendable = false): array {
    $code = menu_normalize_shortcode($code); $name = trim($name);
    if (!menu_valid_shortcode($code)) throw new RuntimeException('A short code looks like *9606*7070#.'); if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Give the menu a name.');
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    if (mobius_menu_state($code, $l['menus'], $cfg)['state'] !== 'missing') throw new RuntimeException($code.' already exists in Mobius — nothing was created.');
    $tpl = mobius_template_menu($l['menus'], $cfg); if (!$tpl) throw new RuntimeException('No menu in Mobius calls this portal yet, so there is no address to copy. Create the first one by hand (Proxy → Connection shows the address), then the portal can create the rest.');
    $data = ['name' => $name, 'shortcode' => $code, 'shortcodeType' => 'PROXY', 'isExtendable' => $extendable, 'remoteURL' => (string)$tpl['remoteURL'], 'destinationID' => array_values((array)($tpl['destinationID'] ?? []))];
    $r = mobius_rest($cfg, 'ussdmenues/set', ['data' => $data]); if (!$r['ok']) throw new RuntimeException('Mobius refused: '.$r['error']);
    audit('mobius_create_menu', null, 'mobius', $code, json_encode(['name' => $name, 'extendable' => $extendable]));
    $after = mobius_list_menus($cfg); $st = $after['ok'] ? mobius_menu_state($code, $after['menus'], $cfg) : ['state' => 'unknown', 'menu' => null];
    return ['state' => $st['state'], 'menu' => $st['menu']];
}
// Change the name, short code and "extendable" of a menu of ours in Mobius. Anything else about the menu is sent back as it was.
function mobius_update_menu(array $cfg, string $menuId, string $name, string $code, bool $extendable): array {
    $code = menu_normalize_shortcode($code); $name = trim($name);
    if (!menu_valid_shortcode($code)) throw new RuntimeException('A short code looks like *9606*7070#.'); if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Give the menu a name.');
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    $cur = null; foreach ($l['menus'] as $m) if ((string)($m['menuID'] ?? '') === $menuId) $cur = $m;
    if (!$cur) throw new RuntimeException('That menu is no longer in Mobius.');
    if (!mobius_menu_is_ours($cur, $cfg)) throw new RuntimeException('That Mobius menu does not call this portal, so it is not changed from here.');
    foreach ($l['menus'] as $m) if ((string)($m['menuID'] ?? '') !== $menuId && rtrim(trim((string)($m['shortcode'] ?? '')), '#') === rtrim($code, '#')) throw new RuntimeException($code.' is already used by another menu in Mobius.');
    $data = $cur; $data['name'] = $name; $data['shortcode'] = $code; $data['isExtendable'] = $extendable;
    $r = mobius_rest($cfg, 'ussdmenues/set', ['data' => $data]); if (!$r['ok']) throw new RuntimeException('Mobius refused: '.$r['error']);
    audit('mobius_update_menu', null, 'mobius', $menuId, json_encode(['from' => $cur['shortcode'] ?? '', 'to' => $code, 'name' => $name, 'extendable' => $extendable]));
    $after = mobius_list_menus($cfg); $st = $after['ok'] ? mobius_menu_state($code, $after['menus'], $cfg) : ['state' => 'unknown', 'menu' => null];
    return ['state' => $st['state'], 'menu' => $st['menu']];
}
// Rename a menu in the portal: its short code (every item, its history, its register entry and the proxy setting) and its name.
function menu_update_identity(string $old, string $newCode, string $newName): void {
    $newCode = menu_normalize_shortcode($newCode); $newName = trim($newName);
    if (!menu_valid_shortcode($newCode)) throw new RuntimeException('A short code looks like *9606*7070# — a *, then numbers (more *numbers if you like), ending with #.');
    if ($newName === '' || mb_strlen($newName) > 150) throw new RuntimeException('Give the menu a name (up to 150 letters).');
    menu_versions_table(); $db = portal_pdo();
    $taken = function (string $c) use ($db): bool { $r = $db->prepare("SELECT 1 FROM portal_short_codes WHERE channel_type='USSD' AND short_code=?"); $r->execute([$c]); return in_array($c, menu_shortcodes(), true) || (bool)$r->fetch(); };
    $oldKnown = $taken($old); if (!$oldKnown) throw new RuntimeException($old.' is not a menu of this portal.');
    $move = $newCode !== $old; if ($move && $taken($newCode)) throw new RuntimeException($newCode.' already exists — pick another short code.');
    $db->beginTransaction();
    try {
        if ($move) {
            foreach (['ussd_menu_nodes', 'ussd_menu_versions'] as $t) $db->prepare("UPDATE `$t` SET short_code=? WHERE short_code=?")->execute([$newCode, $old]);
            $db->prepare("UPDATE portal_short_codes SET short_code=? WHERE channel_type='USSD' AND short_code=?")->execute([$newCode, $old]);
            $cfg = ussd_proxy_config(); $set = []; foreach (['shortcode_proxy', 'shortcode_ms_initiated'] as $k) if ($cfg[$k] === $old) $set[$k] = $newCode; if ($set) ussd_proxy_set($set);
        }
        $has = $db->prepare("SELECT id FROM portal_short_codes WHERE channel_type='USSD' AND short_code=?"); $has->execute([$newCode]); $id = $has->fetchColumn();
        if ($id) $db->prepare('UPDATE portal_short_codes SET service_name=? WHERE id=?')->execute([$newName, $id]);
        else $db->prepare("INSERT INTO portal_short_codes(channel_type,short_code,service_name,provider,status,description) VALUES('USSD',?,?,'Comium','Pending','Named from the Menus page')")->execute([$newCode, $newName]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit('rename_menu', null, 'ussd_menu_nodes', $newCode, json_encode(['from' => $old, 'name' => $newName]));
    if ($move) { try { menu_snapshot($newCode, 'Short code changed from '.$old); } catch (Throwable $e) {} }
}
// Read-only: logs in, then makes harmless read calls in the ways Mobius might want the session presented, and shows
// what it says to each (session ids are cut to their last 4 characters, the password hash is never shown).
function mobius_probe(array $cfg): array {
    if ($cfg['mobius_user'] === '' || decrypt_secret($cfg['mobius_pass']) === '') return [['label' => 'Setup', 'ok' => false, 'msg' => 'Enter the Mobius API user name and password first.']];
    $bases = mobius_bases($cfg); if (!$bases) return [['label' => 'Setup', 'ok' => false, 'msg' => 'No valid Mobius address saved.']];
    $out = [];
    foreach ($bases as $base) {
        $rows = mobius_probe_base($cfg, $base);
        if (count($bases) > 1) foreach ($rows as &$r) $r['label'] = parse_url($base, PHP_URL_HOST).' · '.$r['label'];
        unset($r); $out = array_merge($out, $rows);
    }
    return $out;
}
function mobius_probe_base(array $cfg, string $base): array {
    $out = []; $hash = decrypt_secret($cfg['mobius_pass']); $u = $cfg['mobius_user'];
    $ch = mobius_handle(); $l = mobius_http($base.'auth/login', ['username' => $u, 'password' => $hash], 5, $ch);
    $out[] = ['label' => 'Login (POST)', 'ok' => mobius_probe_ok($l), 'msg' => mobius_brief($l)];
    $sid = (string)($l['json']['sessionID'] ?? '');
    if ($sid !== '') {
        foreach (['ussdmenues/count' => 'Read: menus count', 'ussdcalls/count' => 'Read: calls count', 'auditlog/count' => 'Read: audit log count (management)'] as $path => $label) {
            $r = mobius_http($base.$path, array_merge($path === 'ussdcalls/count' ? ['msisdn' => ''] : [], ['sessionID' => $sid, 'username' => $u]), 5, $ch);
            $out[] = ['label' => $label, 'ok' => mobius_probe_ok($r), 'msg' => mobius_brief($r)];
        }
        foreach (['Authorization: '.$sid => 'Header Authorization', 'sessionID: '.$sid => 'Header sessionID'] as $hdr => $label) {
            $c2 = mobius_handle(); curl_setopt($c2, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', $hdr]);
            $r = mobius_http($base.'ussdmenues/count', ['sessionID' => $sid, 'username' => $u], 5, $c2); curl_close($c2);
            $out[] = ['label' => 'Read: menus count + '.$label, 'ok' => mobius_probe_ok($r), 'msg' => mobius_brief($r)];
        }
    }
    curl_close($ch);
    // GET form of the login, then a read with that session
    $g = mobius_http($base.'auth/login/'.rawurlencode($u).'/'.rawurlencode($hash), [], 5, null, 'GET');
    $out[] = ['label' => 'Login (GET form)', 'ok' => mobius_probe_ok($g), 'msg' => mobius_brief($g)];
    if (!empty($g['json']['sessionID'])) { $r = mobius_http($base.'ussdmenues/count', ['sessionID' => $g['json']['sessionID'], 'username' => $u]); $out[] = ['label' => 'Read: menus count with the GET-login session', 'ok' => mobius_probe_ok($r), 'msg' => mobius_brief($r)]; }
    return $out;
}
function mobius_test(array $cfg): array {
    $out = []; $hash = decrypt_secret($cfg['mobius_pass']);
    if ($cfg['mobius_user'] === '' || $hash === '') return [['base' => '', 'ok' => false, 'msg' => 'Enter the Mobius API user name and password first.']];
    foreach (mobius_bases($cfg) as $base) { $t = microtime(true); $r = mobius_login_at($base, $cfg['mobius_user'], $hash); $out[] = ['base' => $base, 'ok' => $r['ok'], 'msg' => $r['ok'] ? 'Logged in ('.round((microtime(true) - $t) * 1000).' ms).' : $r['error']]; }
    return $out ?: [['base' => '', 'ok' => false, 'msg' => 'No valid Mobius address saved.']];
}
function save_mobius_config(array $d): void {
    $cfg = ussd_proxy_config();
    $bases = []; foreach (preg_split('/[\s,;]+/', trim((string)($d['mobius_base'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $x) $bases[] = mobius_norm_base($x);
    if (!$bases) throw new RuntimeException('Enter at least one Mobius address.');
    if (count($bases) > 4) throw new RuntimeException('At most 4 Mobius addresses.');
    $user = trim((string)($d['mobius_user'] ?? ''));
    if ($user !== '' && !preg_match('/^[A-Za-z0-9._@-]{1,80}$/', $user)) throw new RuntimeException('The API user name may only contain letters, digits and . _ @ -');
    $vals = ['push_enabled' => empty($d['push_enabled']) ? '0' : '1', 'mobius_base' => implode(',', array_unique($bases)), 'mobius_user' => $user];
    $pass = (string)($d['mobius_pass'] ?? '');
    // Mobius wants the MD5 of the password; only that hash is kept, encrypted, and the plain password is never stored.
    if ($pass !== '') $vals['mobius_pass'] = encrypt_secret(md5($pass));
    if ($pass !== '' || $user !== $cfg['mobius_user'] || $vals['mobius_base'] !== $cfg['mobius_base']) { $vals['mobius_session'] = ''; $vals['mobius_variant'] = '0'; }
    ussd_proxy_set($vals);
    audit('ussd_proxy_mobius_save', null, 'ussd_proxy_config', null, 'push='.$vals['push_enabled'].' user='.$user.' password_changed='.($pass !== '' ? 'yes' : 'no'));
}
// PROXY menus: Mobius posts each reply here; the screen goes back through Mobius's REST API, so we acknowledge first
// (keeping the HTTP exchange short) and push afterwards. Session state is the replies so far, keyed by callID.
function ussd_proxy_push_endpoint(array $cfg, array $flat, string $raw, array $get, string $ip, float $t0): never {
    ignore_user_abort(true); @set_time_limit(40);
    $callId = trim((string)($flat['callID'] ?? '')); $msisdn = (string)($flat['msisdn'] ?? '');
    $initial = ($flat['isInitial'] ?? '') === 'true'; $ended = ($flat['isComplete'] ?? '') === 'true'; $typed = trim((string)($flat['request'] ?? ''));
    $note = ''; $text = null; $complete = false; $sc = $cfg['shortcode_proxy'];
    // Serve whichever short code was dialled, as long as it has a menu in the Menu Builder; the code saved on the
    // USSD Proxy page is only the fallback. So several PROXY menus in Mobius can share this one address.
    // A direct code such as *9606*9090*2*1# is the short code followed by the choices to make: the extra digits are typed for the customer.
    $dialled = trim((string)($flat['originalRequest'] ?? '')); $extras = [];
    try { $res = ussd_resolve_dialled($dialled, menu_shortcodes()); if ($res) { [$dc, $extras] = $res; if ($dc !== $sc) { $sc = $dc; $cfg['shortcode_proxy'] = $sc; } } } catch (Throwable $e) {}
    try {
        $db = portal_pdo();
        if ($callId === '') $note = 'no callID in request';
        elseif ($ended && $typed === '') { $db->prepare('DELETE FROM ussd_proxy_sessions WHERE session_key=?')->execute([$callId]); $note = 'dialog finished (nothing to send)'; }
        else {
            $replies = []; $fresh = $initial;
            if (!$initial) {
                $st = $db->prepare('SELECT replies FROM ussd_proxy_sessions WHERE session_key=? AND shortcode=? AND updated_at >= NOW() - INTERVAL '.(int)$cfg['session_ttl'].' SECOND');
                $st->execute([$callId, $sc]); $row = $st->fetchColumn();
                if ($row !== false) { $replies = json_decode((string)$row, true) ?: []; if ($typed !== '') $replies[] = $typed; } else { $fresh = true; $note = 'session not found - restarted from the first screen; '; }
            }
            if ($fresh && $extras) { $replies = $extras; $note .= 'direct code: '.implode('*', $extras).'; '; }
            $hk = ['seed' => $callId, 'msisdn' => $msisdn, 'ctx' => ussd_purchase_ctx($raw)]; $mkScreen = fn() => ussd_screen($sc, array_slice($replies, -30), ['active'], null, $hk);
            $screen = $mkScreen();
            for ($fi = 0; $fi < 5 && ($screen['kind'] ?? '') === 'flow_call'; $fi++) { flow_execute_call(flow_get($screen['flow_key']), $screen['flow_pending'], $callId, $msisdn, $hk['ctx'], $sc); $screen = $mkScreen(); }
            ussd_quiz_record($screen, $msisdn, $callId);
            if (in_array($screen['kind'] ?? '', ['share_subscribe', 'share_add'], true)) { $sr = ussd_share_execute($cfg, $screen, $msisdn, $callId, ussd_purchase_ctx($raw)); $screen['text'] = $sr['text']; $note .= $sr['note'].'; '; }
            if ($screen['end']) $db->prepare('DELETE FROM ussd_proxy_sessions WHERE session_key=?')->execute([$callId]);
            else $db->prepare('REPLACE INTO ussd_proxy_sessions(session_key,shortcode,replies,updated_at) VALUES(?,?,?,NOW())')->execute([$callId, $sc, json_encode($replies)]);
            if (($screen['kind'] ?? '') === 'purchase') { $pr = ussd_execute_purchase($cfg, $screen, $msisdn, $callId, ussd_purchase_ctx($raw)); $screen['text'] = $pr['text']; $note .= $pr['note'].'; '; }
            $text = $screen['text']; $complete = $screen['end'];
        }
    } catch (Throwable $e) { error_log('ussd.php push: '.$e->getMessage()); $note = 'error: '.substr($e->getMessage(), 0, 150); $text = null; }
    $ack = (string)json_encode(['callID' => $callId, 'msisdn' => $msisdn], JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json'); header('Cache-Control: no-store'); header('Connection: close'); header('Content-Length: '.strlen($ack));
    echo $ack;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { while (ob_get_level() > 0) @ob_end_flush(); flush(); }
    if ($text !== null) {
        $res = mobius_push($cfg, $callId, $text, $complete);
        $note .= $res['ok'] ? 'pushed to Mobius'.($complete ? ' (final screen)' : '').' ['.$res['variant'].']' : 'PUSH FAILED: '.$res['error'];
    }
    ussd_proxy_write_log('proxy', $ip, $get, $raw, $text ?? $ack, (int)round((microtime(true) - $t0) * 1000), $note);
    exit;
}
function ussd_proxy_endpoint(): never {
    $t0 = microtime(true); $cfg = ussd_proxy_config();
    $mode = ['proxy' => 'proxy', 'ms' => 'ms_initiated', 'ms_initiated' => 'ms_initiated'][(string)($_GET['m'] ?? '')] ?? null;
    $ip = client_ip(false);
    $ok = $cfg['enabled'] === '1' && $cfg['token'] !== '' && hash_equals($cfg['token'], (string)($_GET['t'] ?? '')) && $mode !== null;
    try { $ok = $ok && ussd_proxy_ip_allowed($cfg['allow_ips'], $ip); } catch (Throwable $e) { $ok = false; }
    if (!$ok) { http_response_code(404); header('Content-Type: text/plain'); exit('Not found'); }
    $raw = (string)file_get_contents('php://input', false, null, 0, 65536);
    $get = $_GET; unset($get['t'], $get['m']);
    $flat = ussd_proxy_flatten((string)($_SERVER['CONTENT_TYPE'] ?? ''), $raw, $get, $_POST);
    if ($mode === 'proxy' && $cfg['mode'] === 'live' && $cfg['push_enabled'] === '1') ussd_proxy_push_endpoint($cfg, $flat, $raw, $get, $ip, $t0);
    try { $res = ussd_proxy_process($cfg, $mode, $flat); }
    catch (Throwable $e) { error_log('ussd.php: '.$e->getMessage()); $res = ['ctype' => 'text/plain; charset=UTF-8', 'body' => 'Service temporarily unavailable. Please try again.', 'note' => 'error: '.substr($e->getMessage(), 0, 150)]; }
    ussd_proxy_write_log($mode, $ip, $get, $raw, $res['body'], (int)round((microtime(true) - $t0) * 1000), $res['note'] ?? '');
    header('Content-Type: '.$res['ctype']); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    echo $res['body']; exit;
}

// ===================== Unified Monitoring =====================
// ===================== Monitoring page =====================
// An integration check is only as fresh as the last time someone (or "Check all now") ran it — nothing
// runs them in the background — so a green UP from three days ago must not read as current health.
const INTEGRATION_STALE_MINUTES = 60;

function time_ago(?string $dt): string {
    if (!$dt) return 'never';
    $s = max(0, time() - (int)strtotime($dt));
    if ($s < 90) return 'just now';
    if ($s < 3600) return floor($s / 60).' min ago';
    if ($s < 86400) return floor($s / 3600).' h ago';
    return floor($s / 86400).' d ago';
}
// state: up | down | stale | never | off
function integration_health(array $i): array {
    if (($i['status'] ?? '') !== 'active') return ['state' => 'off', 'label' => 'Inactive', 'class' => 'bg-secondary'];
    if (empty($i['last_check_at'])) return ['state' => 'never', 'label' => 'Not checked yet', 'class' => 'bg-warning text-dark'];
    if (time() - (int)strtotime($i['last_check_at']) > INTEGRATION_STALE_MINUTES * 60) {
        return ['state' => 'stale', 'label' => $i['last_check_ok'] ? 'Was UP' : 'Was DOWN', 'class' => 'bg-secondary'];
    }
    return $i['last_check_ok'] ? ['state' => 'up', 'label' => 'UP', 'class' => 'bg-success'] : ['state' => 'down', 'label' => 'DOWN', 'class' => 'bg-danger'];
}
// Runs every active integration's check now. Each check can wait up to 5s on an unreachable host, and the
// page sits behind a ~60s proxy timeout, so stop starting new checks after $budgetSeconds and say how many
// were skipped rather than time out with nothing to show.
function check_all_integrations(int $budgetSeconds = 35): array {
    $start = microtime(true); $up = 0; $down = 0; $skipped = 0;
    foreach (list_integrations() as $i) {
        if ($i['status'] !== 'active') continue;
        if (microtime(true) - $start > $budgetSeconds) { $skipped++; continue; }
        try { $r = test_integration((int)$i['id']); $r['ok'] ? $up++ : $down++; } catch (Throwable $e) { $down++; }
    }
    audit('integrations_check_all', null, 'integrations', null, "up=$up down=$down skipped=$skipped");
    return ['up' => $up, 'down' => $down, 'skipped' => $skipped];
}
// Today's traffic per channel (whatever channels actually appear, not a fixed list) with success rate and
// the same window yesterday, so a channel that has quietly dropped off stands out. Bounded to two days of
// audit_log like the other dashboard queries.
function channel_activity_by_channel(string $schema, int $limit = 8): array { return activity_by_column($schema, 'channel', $limit); }
function vendor_activity_today(string $schema, int $limit = 12): array { return activity_by_column($schema, 'vendor_entity_name', $limit); }
// Shared by the channel and vendor cards. Anything seen at this time yesterday but silent today is included
// with 0 transactions (-100%) — a vendor or channel that has quietly stopped is exactly what this view is for.
// audit_log also records Hera's own background calls to its vendors (e.g. OCS free-unit queries, PCRF policy
// calls) with no channel. They are not customer purchases, so they are reported apart from customer traffic.
const LOOKUPS_LABEL = 'System lookups (no channel)';
const LOOKUPS_SQL = "(channel IS NULL OR channel = '')";
function activity_label(string $name, string $kind): string { return $name === '(none)' ? ($kind === 'channel' ? LOOKUPS_LABEL : '(no vendor recorded)') : $name; }
function activity_by_column(...$a) { return cached('activity_by_column:'.md5(serialize($a)), 60, fn() => activity_by_column_uncached(...$a)); }
function activity_by_column_uncached(string $schema, string $col, int $limit): array {
    if (!in_array($col, ['channel', 'vendor_entity_name'], true) || !table_exists($schema, AUDIT_LOG_TABLE)) return [];
    $db = pdo($schema); $limit = max(1, min(30, $limit)); $t = ident(AUDIT_LOG_TABLE);
    $expr = $col === 'channel' ? "COALESCE(NULLIF(SUBSTRING_INDEX(channel,'-',1),''),'(none)')" : "COALESCE(NULLIF($col,''),'(none)')";
    $slowMs = (int)alert_config()['vendor_slow_ms'];
    $cur = $db->query("SELECT $expr name, COUNT(*) total, SUM(CASE WHEN ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) ok, AVG(response_time) avg_ms, MAX(response_time) max_ms, SUM(response_time > $slowMs) slow FROM $t WHERE create_date >= CURDATE() GROUP BY 1")->fetchAll();
    $prev = array_column($db->query("SELECT $expr name, COUNT(*) total FROM $t WHERE create_date >= CURDATE() - INTERVAL 1 DAY AND create_date < NOW() - INTERVAL 1 DAY GROUP BY 1")->fetchAll(), 'total', 'name');
    $rows = [];
    foreach ($cur as $r) $rows[$r['name']] = ['total' => (int)$r['total'], 'ok' => (int)$r['ok'], 'avg_ms' => $r['avg_ms'] === null ? null : (int)round((float)$r['avg_ms']), 'max_ms' => $r['max_ms'] === null ? null : (int)$r['max_ms'], 'slow' => (int)$r['slow']];
    foreach ($prev as $name => $p) if (!isset($rows[$name])) $rows[$name] = ['total' => 0, 'ok' => 0, 'avg_ms' => null, 'max_ms' => null, 'slow' => 0];
    $out = [];
    foreach ($rows as $name => $r) {
        $total = $r['total']; $ok = $r['ok']; $p = (int)($prev[$name] ?? 0);
        $out[] = ['name' => $name, 'channel' => $name, 'total' => $total, 'ok' => $ok, 'failed' => $total - $ok,
            'success_pct' => $total > 0 ? round(100 * $ok / $total, 1) : 0, 'prev_total' => $p,
            'delta_pct' => $p > 0 ? round(100 * ($total - $p) / $p) : null,
            'avg_ms' => $r['avg_ms'], 'max_ms' => $r['max_ms'], 'slow_pct' => $total > 0 ? round(100 * $r['slow'] / $total, 1) : 0];
    }
    usort($out, fn($a, $b) => [$b['total'], $b['prev_total']] <=> [$a['total'], $a['prev_total']]);
    return array_slice($out, 0, $limit);
}

function monitoring_snapshot(string $schema): array {
    $integrations = list_integrations();
    $health = array_count_values(array_map(fn($i) => integration_health($i)['state'], $integrations));
    $channels = channel_activity_by_channel($schema, 30);
    $lk = ['total' => 0, 'ok' => 0, 'failed' => 0];
    foreach ($channels as $c) if ($c['name'] === '(none)') $lk = ['total' => $c['total'], 'ok' => $c['ok'], 'failed' => $c['failed']];
    // headline numbers are customer traffic; the no-channel bucket is shown separately
    $total = array_sum(array_column($channels, 'total')) - $lk['total']; $ok = array_sum(array_column($channels, 'ok')) - $lk['ok'];
    return [
        'channels' => $channels, 'vendors' => vendor_activity_today($schema), 'lookups' => $lk,
        'tx_total' => $total, 'tx_failed' => $total - $ok, 'tx_success_pct' => $total > 0 ? round(100 * $ok / $total, 1) : null,
        'agent_queue_total' => table_exists($schema, 'agent_queue') ? (int)(pdo($schema)->query('SELECT COUNT(*) c FROM agent_queue')->fetch()['c'] ?? 0) : 0,
        'agent_queue_by_status' => table_exists($schema, 'agent_queue') ? pdo($schema)->query('SELECT status, COUNT(*) c FROM agent_queue GROUP BY status ORDER BY c DESC')->fetchAll() : [],
        'integrations' => $integrations,
        'int_up' => (int)($health['up'] ?? 0), 'int_down' => (int)($health['down'] ?? 0),
        'int_stale' => (int)($health['stale'] ?? 0) + (int)($health['never'] ?? 0),
        'alerts' => compute_alerts($schema),
    ];
}

require_once __DIR__.'/flows.php';
if (!defined('LEAN_BOOT')) ensure_portal_runtime_schema();
