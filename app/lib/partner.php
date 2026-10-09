<?php
declare(strict_types=1);
// Sales orders and invoices, Voting, Partner API (API keys).

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
