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
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

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
send_security_headers($__isHttps);

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

        if (!$columnExists('portal_users', 'default_schema_name')) {
            $db->exec("ALTER TABLE portal_users ADD COLUMN default_schema_name ENUM('HeraTesting','HeraProduction') NOT NULL DEFAULT 'HeraTesting' AFTER status");
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

        $db->exec("CREATE TABLE IF NOT EXISTS saved_queries (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            schema_name ENUM('HeraTesting','HeraProduction') NOT NULL DEFAULT 'HeraTesting',
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

        $db->exec("UPDATE portal_users SET default_schema_name='HeraTesting' WHERE default_schema_name IS NULL OR default_schema_name='' ");
    } catch (Throwable $e) {
        // Do not block the whole portal if migration fails; the visible page will show the real DB error.
    }
}
function allowed_schemas(): array { return app_config('allowed_schemas'); }
function current_schema(): string { $s=$_SESSION['schema'] ?? app_config('default_schema'); return in_array($s, allowed_schemas(), true) ? $s : app_config('default_schema'); }
function set_current_schema(string $s): void { if (in_array($s, allowed_schemas(), true)) $_SESSION['schema']=$s; }
function opposite_schema(string $s): string { return $s==='HeraProduction' ? 'HeraTesting' : 'HeraProduction'; }
function ident(string $name): string { if (!preg_match('/^[A-Za-z0-9_]+$/',$name)) throw new InvalidArgumentException('Invalid identifier: '.$name); return '`'.$name.'`'; }
function full_ident(string $schema,string $table): string { return ident($schema).'.'.ident($table); }

function user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void { if (!user()) redirect('?page=login'); }

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
    if ($u && password_verify($password,$u['password_hash'])) { record_login_attempt($username, true); session_regenerate_id(true); $_SESSION['user']=$u; set_current_schema($u['default_schema_name'] ?? app_config('default_schema')); portal_pdo()->prepare('UPDATE portal_users SET last_login=NOW() WHERE id=?')->execute([$u['id']]); audit('login',null,null,null,'User logged in'); return true; }
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
function export_records(string $schema,string $table,array $filters,int $limit=10000): array {
    $params=[]; $where=table_filter_where($schema,$table,$filters,$params);
    $q='SELECT * FROM '.ident($table).$where.order_by_pk($schema,$table).' LIMIT '.(int)$limit;
    $st=pdo($schema)->prepare($q); $st->execute($params); return $st->fetchAll();
}
function normalize_value($v){ return $v === '' ? null : $v; }
function insert_record(string $schema,string $table,array $data): void { $cols=editable_columns($schema,$table,false); $names=[];$vals=[];$params=[]; foreach($cols as $c){ if(array_key_exists($c['name'],$data)){ $names[]=ident($c['name']); $vals[]='?'; $params[]=normalize_value($data[$c['name']]); }} if(!$names) throw new RuntimeException('Nothing to insert'); pdo($schema)->prepare('INSERT INTO '.ident($table).'('.implode(',',$names).') VALUES('.implode(',',$vals).')')->execute($params); audit('insert',$schema,$table,null,json_encode($data)); }
function update_record(string $schema,string $table,array $keys,array $data): void { $set=[];$params=[]; foreach(editable_columns($schema,$table,false) as $c){ if(in_array($c['name'],primary_columns($schema,$table),true)) continue; if(array_key_exists($c['name'],$data)){ $set[]=ident($c['name']).'=?'; $params[]=normalize_value($data[$c['name']]); }} if(!$set) throw new RuntimeException('Nothing to update'); $where=build_pk_where($schema,$table,$keys,$params); pdo($schema)->prepare('UPDATE '.ident($table).' SET '.implode(',',$set).' WHERE '.$where.' LIMIT 1')->execute($params); audit('update',$schema,$table,json_encode($keys),json_encode($data)); }
function copy_record(string $from,string $to,string $table,array $keys,array $overrides=[]): void { $row=fetch_record($from,$table,$keys); if(!$row) throw new RuntimeException('Source record not found'); foreach($overrides as $k=>$v) if(array_key_exists($k,$row)) $row[$k]=normalize_value($v); $cols=columns($to,$table); $names=[];$vals=[];$params=[]; foreach($cols as $c){ if(is_auto_col($c)) continue; if(array_key_exists($c['name'],$row)){ $names[]=ident($c['name']); $vals[]='?'; $params[]=$row[$c['name']]; }} pdo($to)->prepare('REPLACE INTO '.ident($table).'('.implode(',',$names).') VALUES('.implode(',',$vals).')')->execute($params); audit('copy_record',$to,$table,json_encode($keys),"from=$from to=$to"); }
function sync_table(string $from,string $to,string $table): int {
    // Destructive TRUNCATE+reload is only ever allowed when the destination is HeraTesting.
    // HeraProduction can only be updated via merge_table(), which never deletes existing rows.
    if ($to === 'HeraProduction') throw new RuntimeException('Full-table sync cannot target HeraProduction (would truncate live data). Use "Merge into Production" instead.');
    pdo($to)->exec('SET FOREIGN_KEY_CHECKS=0');
    pdo($to)->exec('TRUNCATE TABLE '.ident($table));
    $cols=array_column(columns($from,$table),'name'); $colsql=implode(',',array_map('ident',$cols));
    $affected=pdo($to)->exec('INSERT INTO '.ident($table).'('.$colsql.') SELECT '.$colsql.' FROM '.ident($from).'.'.ident($table));
    pdo($to)->exec('SET FOREIGN_KEY_CHECKS=1');
    audit('sync_table',$to,$table,null,"from=$from rows=$affected");
    return (int)$affected;
}

function merge_table(string $from,string $to,string $table): int {
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
function get_confirmation(string $token): ?array { $st=portal_pdo()->prepare('SELECT * FROM operation_confirmations WHERE token=? AND status="pending" LIMIT 1'); $st->execute([$token]); return $st->fetch() ?: null; }
function mark_confirmation(string $token,string $status): void { portal_pdo()->prepare('UPDATE operation_confirmations SET status=?, confirmed_at=NOW() WHERE token=?')->execute([$status,$token]); }

const SQL_READONLY_KINDS = ['SELECT','SHOW','DESCRIBE','EXPLAIN'];
function safe_sql_kind(string $sql): string {
    $trim=ltrim($sql);
    if (substr_count(rtrim(trim($sql), ';'), ';') > 0) throw new RuntimeException('Only a single statement is allowed per run.');
    if(!preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN|INSERT|UPDATE|REPLACE|CREATE|ALTER)\b/i',$trim,$m)) throw new RuntimeException('Only SELECT, SHOW, DESCRIBE, EXPLAIN, INSERT, UPDATE, REPLACE, CREATE and ALTER are allowed. DELETE, DROP and TRUNCATE are disabled.');
    if(preg_match('/\b(DELETE|DROP|TRUNCATE|GRANT|REVOKE|LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i',$sql)) throw new RuntimeException('Dangerous SQL command blocked. Delete/drop/truncate are disabled in this portal.');
    return strtoupper($m[1]);
}
function run_sql(string $schema,string $sql): array {
    $kind=safe_sql_kind($sql);
    // HeraProduction is read-only from the free-form SQL console; production writes must go through the
    // audited, confirmation-gated record forms (insert_record/update_record/copy_record), which are scoped
    // to a single primary key and cannot run an unbounded UPDATE/CREATE/ALTER against live data.
    if ($schema === 'HeraProduction' && !in_array($kind, SQL_READONLY_KINDS, true)) {
        throw new RuntimeException('HeraProduction is read-only in the SQL Console. Use the record forms (Add/Edit/Copy) for production writes.');
    }
    $db=pdo($schema);
    if(in_array($kind,SQL_READONLY_KINDS,true)){ $st=$db->query($sql); return ['kind'=>$kind,'rows'=>$st->fetchAll(),'affected'=>null]; }
    $affected=$db->exec($sql); audit('sql_'.$kind,$schema,null,null,$sql); return ['kind'=>$kind,'rows'=>[],'affected'=>$affected];
}

// ===================== Complaint / Transaction Investigation report (audit_log) =====================
// audit_log is a huge, month/day-partitioned table (partitioned by create_date). Every query here is
// required to carry a bounded create_date range so MySQL can prune to the relevant partitions instead
// of scanning the whole table, mirroring how this table is meant to be queried operationally.
const AUDIT_LOG_TABLE = 'audit_log';
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
    if ($f['channel'] !== '') { $where[] = 'channel = ?'; $params[] = $f['channel']; }
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

// ===================== Operational dashboard + alerts =====================
// All of these are bounded to short, recent create_date windows so they only ever touch one or two
// audit_log partitions, never a full-table scan of a 200M+ row table.

function dashboard_kpis(string $schema): array {
    $out = ['tx_today'=>0, 'tx_today_success'=>0, 'tx_today_failed'=>0, 'offers_active'=>0, 'offers_inactive'=>0, 'subscription_rows_est'=>0];
    if (table_exists($schema, AUDIT_LOG_TABLE)) {
        $db = pdo($schema);
        $st = $db->query("SELECT COUNT(*) total, SUM(CASE WHEN UPPER(result_status)='SUCCESS' THEN 1 ELSE 0 END) ok FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= CURDATE()");
        $r = $st->fetch();
        $out['tx_today'] = (int)($r['total'] ?? 0);
        $out['tx_today_success'] = (int)($r['ok'] ?? 0);
        $out['tx_today_failed'] = $out['tx_today'] - $out['tx_today_success'];
    }
    if (table_exists($schema, 'vas_offers')) {
        $st = pdo($schema)->query("SELECT SUM(status='active') active, SUM(status!='active' OR status IS NULL) inactive FROM ".ident('vas_offers'));
        $r = $st->fetch();
        $out['offers_active'] = (int)($r['active'] ?? 0);
        $out['offers_inactive'] = (int)($r['inactive'] ?? 0);
    }
    if (table_exists($schema, 'subscription')) $out['subscription_rows_est'] = approx_table_count($schema, 'subscription');
    return $out;
}

function top_vendors_today(string $schema, int $limit = 6): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return [];
    $st = pdo($schema)->prepare("SELECT vendor_entity_name, COUNT(*) total, SUM(CASE WHEN UPPER(result_status)!='SUCCESS' THEN 1 ELSE 0 END) failed FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= CURDATE() GROUP BY vendor_entity_name ORDER BY total DESC LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT); $st->execute();
    return $st->fetchAll();
}

const ALERT_FAILURE_RATE_THRESHOLD = 0.20;
const ALERT_FAILURE_MIN_SAMPLE = 20;
const ALERT_VENDOR_SILENCE_MIN_BASELINE = 5;

function compute_alerts(string $schema): array {
    $alerts = [];
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return $alerts;
    $db = pdo($schema);

    $st = $db->query("SELECT COUNT(*) total, SUM(CASE WHEN UPPER(result_status)!='SUCCESS' THEN 1 ELSE 0 END) failed FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL 1 HOUR");
    $r = $st->fetch(); $total = (int)($r['total'] ?? 0); $failed = (int)($r['failed'] ?? 0);
    if ($total >= ALERT_FAILURE_MIN_SAMPLE) {
        $rate = $failed / $total;
        if ($rate > ALERT_FAILURE_RATE_THRESHOLD) {
            $alerts[] = ['level'=>'danger', 'message'=>sprintf('High failure rate in the last hour: %d of %d transactions failed (%.0f%%).', $failed, $total, $rate*100)];
        }
    }

    $st = $db->query("SELECT vendor_entity_name, COUNT(*) c FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL 1 HOUR - INTERVAL 1 DAY AND create_date < NOW() - INTERVAL 1 DAY GROUP BY vendor_entity_name HAVING c >= ".ALERT_VENDOR_SILENCE_MIN_BASELINE);
    $baseline = array_column($st->fetchAll(), 'c', 'vendor_entity_name');
    if ($baseline) {
        $st = $db->query("SELECT DISTINCT vendor_entity_name FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= NOW() - INTERVAL 1 HOUR");
        $activeNow = array_column($st->fetchAll(), 'vendor_entity_name');
        foreach ($baseline as $vendor => $count) {
            if (!in_array($vendor, $activeNow, true)) {
                $alerts[] = ['level'=>'warning', 'message'=>sprintf('%s sent %d transactions in this hour yesterday but none in the last hour — may be down.', $vendor, $count)];
            }
        }
    }
    return $alerts;
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
function channel_activity_today(string $schema, array $channels): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return ['total' => 0, 'success' => 0, 'failed' => 0];
    $placeholders = implode(',', array_fill(0, count($channels), '?'));
    $st = pdo($schema)->prepare("SELECT COUNT(*) total, SUM(CASE WHEN UPPER(result_status)='SUCCESS' THEN 1 ELSE 0 END) ok FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date >= CURDATE() AND channel IN ($placeholders)");
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
function smsc_activity_today(string $schema): array { return channel_activity_today($schema, ['SMS', 'SMSC']); }

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

// ===================== Unified Monitoring =====================
function monitoring_snapshot(string $schema): array {
    $integrations = list_integrations();
    return [
        'db_tables' => count(table_names($schema)),
        'ussd' => channel_activity_today($schema, ['USSD']),
        'ivr' => channel_activity_today($schema, ['IVR']),
        'sms' => smsc_activity_today($schema),
        'agent_queue_total' => table_exists($schema, 'agent_queue') ? (int)(pdo($schema)->query('SELECT COUNT(*) c FROM agent_queue')->fetch()['c'] ?? 0) : 0,
        'agent_queue_by_status' => table_exists($schema, 'agent_queue') ? pdo($schema)->query('SELECT status, COUNT(*) c FROM agent_queue GROUP BY status ORDER BY c DESC')->fetchAll() : [],
        'integrations' => $integrations,
        'integrations_active' => count(array_filter($integrations, fn($i) => $i['status'] === 'active')),
        'integrations_down' => count(array_filter($integrations, fn($i) => $i['status'] === 'active' && $i['last_check_ok'] !== null && (int)$i['last_check_ok'] === 0)),
        'alerts' => compute_alerts($schema),
    ];
}

ensure_portal_runtime_schema();
