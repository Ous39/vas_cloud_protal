<?php
declare(strict_types=1);
// Unified monitoring: integration checks and channel / vendor activity.

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
// The 5-minute job (alert_cron) checks every active integration, so Monitoring shows current health and a DOWN one raises an
// alert (compute_alerts). Skips one checked in the last 4 minutes (by a person, or a slow previous run); no audit entry each time.
function background_integration_checks(int $budgetSeconds = 25): array {
    $start = microtime(true); $n = 0;
    foreach (list_integrations() as $i) {
        if ($i['status'] !== 'active') continue;
        if (!empty($i['last_check_at']) && time() - (int)strtotime($i['last_check_at']) < 240) continue;
        if (microtime(true) - $start > $budgetSeconds) break;
        try { test_integration((int)$i['id'], false); $n++; } catch (Throwable $e) { error_log('integration check: '.$e->getMessage()); }
    }
    if (random_int(1, 50) === 1) { try { portal_pdo()->exec('DELETE FROM integration_checks WHERE checked_at < NOW() - INTERVAL 30 DAY'); } catch (Throwable $e) {} }
    return ['checked' => $n];
}
// Integrations that failed their last two checks, both recent (one failed check can be a blip).
function integrations_down(): array {
    $out = [];
    foreach (list_integrations() as $i) {
        if ($i['status'] !== 'active' || empty($i['last_check_at']) || $i['last_check_ok'] || time() - (int)strtotime($i['last_check_at']) > 15 * 60) continue;
        $h = integration_check_history((int)$i['id'], 2); if (count($h) < 2 || $h[0]['ok'] || $h[1]['ok']) continue;
        $out[] = $i;
    }
    return $out;
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
function activity_by_column(...$a) { return cached('activity_by_column:'.md5(serialize($a)), LIVE_TTL, fn() => activity_by_column_uncached(...$a)); }
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

// ===================== Built-in health checks =====================
// What the USSD service depends on, checked every 5 minutes by the alert job with nothing to set up: each Mobius node (a
// login, nothing is sent), Hera (a connection), the main database, and the portal's USSD endpoint (answers pushed back
// to Mobius that failed). Two failed checks in a row raise a DOWN alert (Slack / email), and a "Resolved" when it is back.
function health_checks_ready(): void {
    static $done = false; if ($done) return;
    portal_pdo()->exec("CREATE TABLE IF NOT EXISTS health_checks (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        check_key VARCHAR(80) NOT NULL,
        label VARCHAR(120) NOT NULL,
        checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ok TINYINT(1) NOT NULL,
        latency_ms INT NULL,
        message VARCHAR(500) NULL,
        KEY k (check_key, checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
// Runs the checks and records them. Returns [key => ['label','ok','ms','msg']].
function builtin_health_run(): array {
    health_checks_ready(); $out = [];
    $cfg = ussd_proxy_config_raw(); // production Mobius, whatever a user's Production / Testbed switch says
    $timed = function (string $key, string $label, callable $fn) use (&$out): void {
        $t0 = microtime(true); try { [$ok, $msg] = $fn(); } catch (Throwable $e) { $ok = false; $msg = $e->getMessage(); }
        $out[$key] = ['label' => $label, 'ok' => (bool)$ok, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'msg' => mb_substr((string)$msg, 0, 500)];
    };
    if (($cfg['mobius_user'] ?? '') !== '' && ($cfg['mobius_pass'] ?? '') !== '') {
        $hash = decrypt_secret($cfg['mobius_pass']);
        foreach (mobius_bases($cfg) as $base) {
            $host = (string)(parse_url($base, PHP_URL_HOST) ?: $base);
            $timed('mobius:'.$host, 'Mobius '.$host, function () use ($base, $cfg, $hash) { $r = mobius_login_at($base, $cfg['mobius_user'], $hash); return [$r['ok'], $r['ok'] ? 'Logged in' : (string)$r['error']]; });
        }
    }
    $tcp = function (string $url): array {
        $p = parse_url($url); $host = $p['host'] ?? ''; $port = (int)($p['port'] ?? (($p['scheme'] ?? '') === 'https' ? 443 : 80));
        if ($host === '') return [false, 'no address saved'];
        $c = @fsockopen($host, $port, $en, $es, 4); if (!$c) return [false, (trim((string)$es) ?: 'cannot connect').' ('.$host.':'.$port.')']; fclose($c);
        return [true, $host.':'.$port.' answers'];
    };
    if (trim((string)($cfg['purchase_url'] ?? '')) !== '') $timed('hera', 'Hera (purchases)', fn() => $tcp((string)$cfg['purchase_url']));
    $main = (string)app_config('default_schema');
    if ($main !== '') $timed('db:'.$main, 'Database '.$main, function () use ($main) { pdo($main)->query('SELECT 1'); return [true, 'answers']; });
    if (($cfg['enabled'] ?? '') === '1' && ($cfg['mode'] ?? '') === 'live' && ($cfg['push_enabled'] ?? '') === '1') {
        $timed('ussd_endpoint', 'USSD endpoint (answers to Mobius)', function () {
            $r = portal_pdo()->query("SELECT COUNT(*) n, SUM(note LIKE 'PUSH FAILED%') f FROM ussd_proxy_log WHERE created_at >= NOW() - INTERVAL 15 MINUTE")->fetch();
            $n = (int)$r['n']; $f = (int)$r['f'];
            $bad = $f >= 5 && $f * 2 >= $n;
            return [!$bad, $n === 0 ? 'no requests in the last 15 minutes' : "$n requests in the last 15 minutes, $f could not be sent back to Mobius"];
        });
    }
    $ins = portal_pdo()->prepare('INSERT INTO health_checks(check_key,label,ok,latency_ms,message) VALUES(?,?,?,?,?)');
    foreach ($out as $k => $r) $ins->execute([$k, $r['label'], $r['ok'] ? 1 : 0, $r['ms'], $r['msg']]);
    if (random_int(1, 50) === 1) { try { portal_pdo()->exec('DELETE FROM health_checks WHERE checked_at < NOW() - INTERVAL 30 DAY'); } catch (Throwable $e) {} }
    return $out;
}
// The latest state of every check seen in the last day, with its uptime over 24 h and whether it is DOWN (the last two failed).
function health_overview(): array {
    try { health_checks_ready(); } catch (Throwable $e) { return []; }
    $rows = portal_pdo()->query("SELECT check_key, label, checked_at, ok, latency_ms, message FROM health_checks WHERE checked_at >= NOW() - INTERVAL 1 DAY ORDER BY check_key, checked_at DESC, id DESC")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $k = $r['check_key'];
        if (!isset($out[$k])) $out[$k] = ['key' => $k, 'label' => $r['label'], 'last' => $r, 'prev' => null, 'n' => 0, 'up' => 0, 'recent' => []];
        elseif ($out[$k]['prev'] === null) $out[$k]['prev'] = $r;
        $out[$k]['n']++; $out[$k]['up'] += (int)$r['ok']; if (count($out[$k]['recent']) < 12) $out[$k]['recent'][] = (int)$r['ok'];
    }
    foreach ($out as &$o) {
        $fresh = time() - (int)strtotime($o['last']['checked_at']) <= 15 * 60;
        $o['down'] = $fresh && !(int)$o['last']['ok'] && $o['prev'] !== null && !(int)$o['prev']['ok'];
        $o['state'] = !$fresh ? 'stale' : ((int)$o['last']['ok'] ? 'up' : ($o['down'] ? 'down' : 'blip'));
        $o['uptime'] = $o['n'] ? round(100 * $o['up'] / $o['n'], 1) : null;
    }
    unset($o);
    uasort($out, fn($a, $b) => [$a['state'] !== 'down', $a['label']] <=> [$b['state'] !== 'down', $b['label']]);
    return $out;
}

// ===================== Today hour by hour =====================
// Customer traffic per hour today, per channel: succeeded, failed on the customer's side (the reasons ignored in Alert
// Settings, e.g. no balance) and real failures; plus the same weekday last week, to see at a glance what is unusual.
function hourly_today(...$a) { return cached('hourly_today:'.md5(serialize($a)), LIVE_TTL, fn() => hourly_today_uncached(...$a)); }
function hourly_today_uncached(string $schema): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return ['channels' => []];
    $db = pdo($schema); $t = ident(AUDIT_LOG_TABLE); $ch = "SUBSTRING_INDEX(channel,'-',1)";
    [$cf, $params] = alert_counted_failure_sql();
    $st = $db->prepare("SELECT HOUR(create_date) h, $ch c, COUNT(*) n, SUM(CASE WHEN ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) ok, SUM(CASE WHEN $cf THEN 1 ELSE 0 END) rf FROM $t WHERE create_date >= CURDATE() AND NOT ".LOOKUPS_SQL." GROUP BY 1, 2");
    $st->execute($params); $today = $st->fetchAll();
    $last = $db->query("SELECT HOUR(create_date) h, $ch c, COUNT(*) n FROM $t WHERE create_date >= CURDATE() - INTERVAL 7 DAY AND create_date < CURDATE() - INTERVAL 6 DAY AND NOT ".LOOKUPS_SQL." GROUP BY 1, 2")->fetchAll();
    $tot = []; foreach ($today as $r) $tot[$r['c']] = ($tot[$r['c']] ?? 0) + (int)$r['n']; foreach ($last as $r) $tot[$r['c']] = ($tot[$r['c']] ?? 0) + 0;
    arsort($tot); $keep = array_slice(array_keys($tot), 0, 4); $name = fn($c) => in_array($c, $keep, true) ? ((string)$c === '' ? '(none)' : (string)$c) : 'Other';
    $blank = fn() => ['ok' => array_fill(0, 24, 0), 'cust' => array_fill(0, 24, 0), 'real' => array_fill(0, 24, 0), 'lastweek' => array_fill(0, 24, 0)];
    $out = ['All' => $blank()];
    foreach ($today as $r) { $h = (int)$r['h']; $n = (int)$r['n']; $ok = (int)$r['ok']; $rf = min($n - $ok, (int)$r['rf']);
        foreach (['All', $name($r['c'])] as $k) { $out[$k] ??= $blank(); $out[$k]['ok'][$h] += $ok; $out[$k]['real'][$h] += $rf; $out[$k]['cust'][$h] += $n - $ok - $rf; } }
    foreach ($last as $r) foreach (['All', $name($r['c'])] as $k) { $out[$k] ??= $blank(); $out[$k]['lastweek'][(int)$r['h']] += (int)$r['n']; }
    return ['channels' => $out, 'hour' => (int)date('G'), 'lastweek_date' => date('D j M', strtotime('-7 days'))];
}
