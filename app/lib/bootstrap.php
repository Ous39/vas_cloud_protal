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
            node_type ENUM('menu','offer','action','end') NOT NULL DEFAULT 'menu',
            offer_code VARCHAR(80) NULL,
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
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 80); }

function record_login_attempt(string $username, bool $success): void {
    try { portal_pdo()->prepare('INSERT INTO login_attempts(username,ip_address,success) VALUES(?,?,?)')->execute([$username, client_ip(), $success ? 1 : 0]); } catch (Throwable $e) {}
}

function is_login_locked_out(string $username): bool {
    try {
        $st = portal_pdo()->prepare('SELECT COUNT(*) c FROM login_attempts WHERE username=? AND success=0 AND created_at > (NOW() - INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)');
        $st->execute([$username]);
        if ((int)$st->fetch()['c'] >= LOGIN_MAX_ATTEMPTS) return true;
        $st = portal_pdo()->prepare('SELECT COUNT(*) c FROM login_attempts WHERE ip_address=? AND success=0 AND created_at > (NOW() - INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)');
        $st->execute([client_ip()]);
        return (int)$st->fetch()['c'] >= (LOGIN_MAX_ATTEMPTS * 4);
    } catch (Throwable $e) { return false; }
}

function login_attempt(string $username,string $password): bool {
    if ($username === '' || is_login_locked_out($username)) { record_login_attempt($username, false); return false; }
    $st=portal_pdo()->prepare('SELECT * FROM portal_users WHERE username=? AND status="active" LIMIT 1'); $st->execute([$username]); $u=$st->fetch();
    if ($u && password_verify($password,$u['password_hash'])) { record_login_attempt($username, true); session_regenerate_id(true); $_SESSION['user']=array_diff_key($u,['password_hash'=>1]); $_SESSION['last_seen']=time(); set_current_schema($u['default_schema_name'] ?? app_config('default_schema')); portal_pdo()->prepare('UPDATE portal_users SET last_login=NOW() WHERE id=?')->execute([$u['id']]); audit('login',null,null,null,'User logged in'); return true; }
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
        ->execute([request_id(),$u['id']??null,$u['username']??null,$action,$schema,$table,$key,$_SERVER['REMOTE_ADDR']??null,substr($_SERVER['HTTP_USER_AGENT']??'',0,255),$details]); } catch(Throwable $e) {}
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
    pdo($to)->exec('SET FOREIGN_KEY_CHECKS=0');
    pdo($to)->exec('TRUNCATE TABLE '.ident($table));
    $cols=array_column(columns($from,$table),'name'); $colsql=implode(',',array_map('ident',$cols));
    $affected=pdo($to)->exec('INSERT INTO '.ident($table).'('.$colsql.') SELECT '.$colsql.' FROM '.ident($from).'.'.ident($table));
    pdo($to)->exec('SET FOREIGN_KEY_CHECKS=1');
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
function safe_sql_kind(string $sql): string {
    $trim=ltrim($sql);
    if (substr_count(rtrim(trim($sql), ';'), ';') > 0) throw new RuntimeException('Only a single statement is allowed per run.');
    $effective = preg_match('/^WITH\s+/i', $trim) ? sql_after_cte_header($trim) : $trim;
    if(!preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN|INSERT|UPDATE|REPLACE|CREATE|ALTER)\b/i',$effective,$m)) throw new RuntimeException('Only SELECT, SHOW, DESCRIBE, EXPLAIN, INSERT, UPDATE, REPLACE, CREATE and ALTER (optionally preceded by a WITH common table expression) are allowed. DELETE, DROP and TRUNCATE are disabled.');
    if(preg_match('/\b(DELETE|DROP|TRUNCATE|GRANT|REVOKE|LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i',$sql)) throw new RuntimeException('Dangerous SQL command blocked. Delete/drop/truncate are disabled in this portal.');
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
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 5,
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
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers]);
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
    return array_column(portal_pdo()->query('SELECT DISTINCT short_code FROM ussd_menu_nodes ORDER BY short_code')->fetchAll(), 'short_code');
}
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
function save_menu_node(array $data, ?int $id = null): int {
    $shortCode = trim((string)($data['short_code'] ?? ''));
    $prompt = trim((string)($data['prompt_text'] ?? ''));
    if ($shortCode === '' || $prompt === '') throw new RuntimeException('Short code and prompt text are required.');
    $parentId = trim((string)($data['parent_id'] ?? '')) !== '' ? (int)$data['parent_id'] : null;
    $type = in_array($data['node_type'] ?? '', ['menu','offer','action','end'], true) ? $data['node_type'] : 'menu';
    $fields = [$parentId, $shortCode, (int)($data['display_order'] ?? 0), $prompt, $type,
        normalize_value($data['offer_code'] ?? ''), normalize_value($data['action_key'] ?? ''),
        in_array($data['status'] ?? '', ['active','inactive','draft'], true) ? $data['status'] : 'draft'];
    $db = portal_pdo();
    if ($id) {
        $db->prepare('UPDATE ussd_menu_nodes SET parent_id=?,short_code=?,display_order=?,prompt_text=?,node_type=?,offer_code=?,action_key=?,status=? WHERE id=?')->execute([...$fields, $id]);
    } else {
        $db->prepare('INSERT INTO ussd_menu_nodes(parent_id,short_code,display_order,prompt_text,node_type,offer_code,action_key,status,created_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([...$fields, user()['username'] ?? null]);
        $id = (int)$db->lastInsertId();
    }
    audit('save_menu_node', null, 'ussd_menu_nodes', (string)$id, json_encode(['short_code'=>$shortCode,'prompt'=>$prompt]));
    return $id;
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
            : ($node['node_type'] === 'end' ? ' → END' : ''));
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
function ussd_screen(string $shortCode, array $replies, array $statuses = ['active'], ?array $nodes = null): array {
    $nodes = $nodes ?? menu_nodes_flat($shortCode);
    $nodes = array_values(array_filter($nodes, fn($n) => in_array($n['status'], $statuses, true)));
    $kids = [];
    foreach ($nodes as $n) $kids[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    if (!$nodes) return ussd_result('No menu is set up for '.$shortCode.' yet.', true, [], 'empty');
    // A single root "menu" node acts as the welcome screen (its text is the header, its children the options);
    // otherwise the root nodes themselves are the options under a plain header.
    $roots = $kids[0] ?? [];
    $cur = (count($roots) === 1 && $roots[0]['node_type'] === 'menu' && !empty($kids[(int)$roots[0]['id']])) ? $roots[0] : null;
    $trail = [$cur]; $path = []; $note = null;
    foreach ($replies as $r) {
        $r = trim((string)$r);
        $options = $cur ? ($kids[(int)$cur['id']] ?? []) : $roots;
        if ($r === '0' && count($trail) > 1) { array_pop($trail); $cur = end($trail) ?: null; array_pop($path); $note = null; continue; }
        if (!ctype_digit($r) || (int)$r < 1 || (int)$r > count($options)) { $note = 'Invalid choice.'; continue; }
        $pick = $options[(int)$r - 1]; $path[] = (int)$r; $note = null;
        if ($pick['node_type'] === 'menu' && !empty($kids[(int)$pick['id']])) { $cur = $pick; $trail[] = $cur; continue; }
        // a leaf: offer / action / end / an empty submenu — the session ends here
        if ($pick['node_type'] === 'offer') {
            $o = null; $code = trim((string)$pick['offer_code']);
            if ($code !== '') foreach (['HeraProduction', 'HeraTesting'] as $s) { try { $st = pdo($s)->prepare('SELECT name, one_time_price, validity_amount FROM vas_offers WHERE offer_code=? LIMIT 1'); $st->execute([$code]); $o = $st->fetch() ?: null; } catch (Throwable $e) {} if ($o) break; }
            $text = $pick['prompt_text'].($o ? "\n".$o['name'].($o['one_time_price'] !== null && $o['one_time_price'] !== '' ? ' - '.$o['one_time_price'] : '').($o['validity_amount'] ? ' / '.$o['validity_amount'].' days' : '') : '')."\nPurchase is not connected yet.";
            return ussd_result($text, true, $path, 'offer', $pick);
        }
        if ($pick['node_type'] === 'action') return ussd_result($pick['prompt_text']."\n(action ".$pick['action_key']." is not connected yet)", true, $path, 'action', $pick);
        return ussd_result($pick['prompt_text'], true, $path, 'end', $pick);
    }
    $options = $cur ? ($kids[(int)$cur['id']] ?? []) : $roots;
    $lines = [$cur ? $cur['prompt_text'] : 'Welcome'];
    if ($note) array_unshift($lines, $note);
    foreach ($options as $i => $o) $lines[] = ($i + 1).'. '.$o['prompt_text'];
    if (count($trail) > 1) $lines[] = '0. Back';
    return ussd_result(implode("\n", $lines), false, $path, 'menu', $cur);
}
function ussd_result(string $text, bool $end, array $path, string $kind, ?array $node = null): array {
    return ['text' => $text, 'end' => $end, 'path' => $path, 'kind' => $kind, 'node_id' => $node['id'] ?? null, 'chars' => mb_strlen($text), 'too_long' => mb_strlen($text) > USSD_MAX_CHARS];
}

// ===================== USSD proxy endpoint =====================
// public/ussd.php answers Mobius PROXY / MS_INITIATED menus. It is OFF until an admin enables it on the USSD Proxy page
// and then starts in CAPTURE mode (records what Mobius sends, replies with a fixed text) so the real request format can
// be read from the log; in LIVE mode it maps the request's fields (configured there) onto the screen engine.
const USSD_PROXY_DEFAULTS = [
    'enabled' => '0', 'token' => '', 'mode' => 'capture', 'allow_ips' => '',
    'shortcode_proxy' => '*9606*9090#', 'shortcode_ms_initiated' => '*9606*9090#',
    'f_msisdn' => '', 'f_session' => '', 'f_input' => '', 'f_shortcode' => '',
    'reply_mode' => 'step', 'session_ttl' => '180',
    'resp_type' => 'text/plain; charset=UTF-8', 'resp_body' => '{text}', 'end_true' => 'true', 'end_false' => 'false',
    'capture_body' => 'VAS Cloud test endpoint: request received.',
    // PROXY menus: Mobius sends each reply here and expects the screen to come back through ITS REST API
    'push_enabled' => '0', 'mobius_base' => 'http://192.168.162.20:28080/rest/', 'mobius_user' => '', 'mobius_pass' => '', 'mobius_session' => '', 'mobius_variant' => '0',
];
function ussd_proxy_config(): array {
    $cfg = USSD_PROXY_DEFAULTS;
    try { foreach (portal_pdo()->query('SELECT name,value FROM ussd_proxy_config')->fetchAll() as $r) if (array_key_exists($r['name'], $cfg)) $cfg[$r['name']] = (string)$r['value']; }
    catch (Throwable $e) {}
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
    $screen = ussd_screen($sc, array_slice($replies, -30), ['active']);
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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_COOKIEFILE => '']);
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
    try {
        $db = portal_pdo();
        if ($callId === '') $note = 'no callID in request';
        elseif ($ended && $typed === '') { $db->prepare('DELETE FROM ussd_proxy_sessions WHERE session_key=?')->execute([$callId]); $note = 'dialog finished (nothing to send)'; }
        else {
            $replies = [];
            if (!$initial) {
                $st = $db->prepare('SELECT replies FROM ussd_proxy_sessions WHERE session_key=? AND shortcode=? AND updated_at >= NOW() - INTERVAL '.(int)$cfg['session_ttl'].' SECOND');
                $st->execute([$callId, $sc]); $row = $st->fetchColumn();
                if ($row !== false) { $replies = json_decode((string)$row, true) ?: []; if ($typed !== '') $replies[] = $typed; } else $note = 'session not found - restarted from the first screen; ';
            }
            $screen = ussd_screen($sc, array_slice($replies, -30), ['active']);
            if ($screen['end']) $db->prepare('DELETE FROM ussd_proxy_sessions WHERE session_key=?')->execute([$callId]);
            else $db->prepare('REPLACE INTO ussd_proxy_sessions(session_key,shortcode,replies,updated_at) VALUES(?,?,?,NOW())')->execute([$callId, $sc, json_encode($replies)]);
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
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')); $ip = $xff !== '' ? trim(substr($xff, strrpos(',' . $xff, ',') )) : (string)($_SERVER['REMOTE_ADDR'] ?? '');
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

if (!defined('LEAN_BOOT')) ensure_portal_runtime_schema();
