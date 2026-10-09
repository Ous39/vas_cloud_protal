<?php
declare(strict_types=1);
// Operational dashboard, alerts and notifications.

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
