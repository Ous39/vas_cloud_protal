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
