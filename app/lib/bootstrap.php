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
        $dsn='mysql:host='.$cfg['db_host'].';port='.$cfg['db_port'].';dbname='.$db.';charset=utf8mb4';
        $pool[$db]=new PDO($dsn,$cfg['db_user'],$cfg['db_pass'],[
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

ensure_portal_runtime_schema();
