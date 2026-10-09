<?php
declare(strict_types=1);
// Result cache, data retention, complaint notes, token rotation.

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
