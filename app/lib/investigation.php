<?php
declare(strict_types=1);
// Complaint / transaction investigation over audit_log and the subscription log.

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
