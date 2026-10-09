<?php
declare(strict_types=1);
// USSD & IVR routing views, and the integrations registry (with secret encryption).

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
