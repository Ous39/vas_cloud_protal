<?php
declare(strict_types=1);
// Vendor detail, customer timeline, offer history, daily summary.

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

// ===================== Real failures =====================
// Failed customer transactions that are NOT on the customer's side (the reasons ignored in Alert Settings, such as no
// balance, are left out): by reason, by offer, by vendor, by channel and over time. Counts only — no numbers are shown.
// The offer is read from the transaction id of a purchase (…-HERA-<seq>-<CHANNEL>-<number>-<offer>); other rows have none.
const REAL_FAIL_OFFER_SQL = "CASE WHEN LENGTH(transaction_id) - LENGTH(REPLACE(transaction_id, '-', '')) = 5 AND transaction_id LIKE '%-HERA-%' THEN SUBSTRING_INDEX(transaction_id, '-', -1) ELSE '' END";
function real_failures(...$a) { return cached('real_failures:'.md5(serialize($a)), 300, fn() => real_failures_uncached(...$a)); }
function real_failures_uncached(string $schema, string $from, string $to, string $channel = ''): array {
    $out = ['total' => 0, 'failed' => 0, 'real' => 0, 'reasons' => [], 'offers' => [], 'vendors' => [], 'channels' => [], 'time' => [], 'by' => 'hour'];
    if (!table_exists($schema, AUDIT_LOG_TABLE)) return $out;
    $db = pdo($schema); $t = ident(AUDIT_LOG_TABLE); [$cf, $cfp] = alert_counted_failure_sql();
    $w = 'create_date >= ? AND create_date < ? AND NOT '.LOOKUPS_SQL; $p = [$from, $to];
    if ($channel !== '') { $w .= " AND SUBSTRING_INDEX(channel,'-',1) = ?"; $p[] = $channel; }
    $q = function (string $sql, array $extra = []) use ($db, $cfp, $p) { $st = $db->prepare($sql); $st->execute([...$cfp, ...$p, ...$extra]); return $st->fetchAll(); };
    $r = $q("SELECT COUNT(*) n, SUM(CASE WHEN NOT ".AUDIT_SUCCESS_SQL." THEN 1 ELSE 0 END) f, SUM(CASE WHEN $cf THEN 1 ELSE 0 END) rf FROM $t WHERE $w")[0] ?? [];
    $out['total'] = (int)($r['n'] ?? 0); $out['failed'] = (int)($r['f'] ?? 0); $out['real'] = min($out['failed'], (int)($r['rf'] ?? 0));
    if ($out['real'] === 0) return $out;
    $grp = function (string $expr, int $limit) use ($q, $cf, $t, $w) {
        return $q("SELECT $expr k, COUNT(*) n, SUM(CASE WHEN $cf THEN 1 ELSE 0 END) rf FROM $t WHERE $w GROUP BY 1 HAVING rf > 0 ORDER BY rf DESC LIMIT $limit");
    };
    $out['reasons'] = $q("SELECT COALESCE(NULLIF(TRIM(result_description),''),'(no reason given)') k, COUNT(*) rf FROM $t WHERE $cf AND $w GROUP BY 1 ORDER BY rf DESC LIMIT 15"); // $cf first: its parameters come first
    $out['offers'] = $grp(REAL_FAIL_OFFER_SQL, 15);
    $out['vendors'] = $grp("COALESCE(NULLIF(vendor_entity_name,''),'(none)')", 10);
    $out['channels'] = $grp("COALESCE(NULLIF(SUBSTRING_INDEX(channel,'-',1),''),'(none)')", 10);
    $days = (strtotime($to) - strtotime($from)) / 86400; $out['by'] = $days > 1.5 ? 'day' : 'hour';
    $out['time'] = $q("SELECT ".($out['by'] === 'day' ? 'DATE(create_date)' : 'HOUR(create_date)')." k, COUNT(*) n, SUM(CASE WHEN $cf THEN 1 ELSE 0 END) rf FROM $t WHERE $w GROUP BY 1 ORDER BY 1");
    // the top reason of each offer, so a bad offer says why
    foreach ($out['offers'] as &$o) {
        if ((string)$o['k'] === '') continue;
        $tr = $q("SELECT COALESCE(NULLIF(TRIM(result_description),''),'(no reason given)') k, COUNT(*) c FROM $t WHERE $cf AND $w AND transaction_id LIKE ? GROUP BY 1 ORDER BY c DESC LIMIT 1", ['%-'.$o['k']]);
        $o['top_reason'] = $tr[0]['k'] ?? '';
    }
    unset($o);
    $codes = array_values(array_filter(array_map(fn($o) => (string)$o['k'], $out['offers']), fn($c) => $c !== ''));
    if ($codes && table_exists($schema, 'vas_offers')) {
        $st = $db->prepare('SELECT offer_code, name FROM vas_offers WHERE offer_code IN ('.implode(',', array_fill(0, count($codes), '?')).') ORDER BY ('.OFFER_ACTIVE_SQL.') DESC, id DESC'); $st->execute($codes);
        $names = []; foreach ($st->fetchAll() as $n) $names[$n['offer_code']] ??= $n['name'];
        foreach ($out['offers'] as &$o) $o['name'] = $names[(string)$o['k']] ?? ''; unset($o);
    }
    return $out;
}
function report_setting(string $name, string $default = ''): string {
    try { $st = portal_pdo()->prepare('SELECT value FROM report_settings WHERE name=?'); $st->execute([$name]); $v = $st->fetchColumn(); return $v === false ? $default : (string)$v; } catch (Throwable $e) { return $default; }
}
function failure_report_recipients(): array {
    return array_values(array_unique(array_filter(array_map(fn($a) => strtolower(trim($a)), preg_split('/[\s,;]+/', report_setting('failure_report_to'))), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL))));
}
function save_failure_report_settings(array $d): void {
    $hour = filter_var($d['failure_report_hour'] ?? null, FILTER_VALIDATE_INT);
    if ($hour === false || $hour < 0 || $hour > 23) throw new RuntimeException('Send hour must be between 0 and 23.');
    $to = []; foreach (preg_split('/[\s,;]+/', trim((string)($d['failure_report_to'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $a) { if (!filter_var($a, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Not an email address: '.$a); $to[] = strtolower($a); }
    if (count($to) > 20) throw new RuntimeException('At most 20 addresses.');
    $on = empty($d['failure_report_enabled']) ? 0 : 1; if ($on && !$to) throw new RuntimeException('Add at least one address to send the report to.');
    $st = portal_pdo()->prepare('INSERT INTO alert_config(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
    $st->execute(['failure_report_enabled', (string)$on]); $st->execute(['failure_report_hour', (string)$hour]);
    portal_pdo()->prepare('INSERT INTO report_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute(['failure_report_to', implode(', ', array_unique($to))]);
    audit('failure_report_config_save', null, 'report_settings', null, json_encode(['enabled' => $on, 'hour' => $hour, 'recipients' => count($to)]));
}
function failure_report_text(string $schema, string $date): string {
    $r = real_failures($schema, $date.' 00:00:00', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00');
    $pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b, 2).'%' : '0%';
    $l = ["Real failures — $schema — ".date('l j F Y', strtotime($date)), '',
        'Customer transactions: '.number_format($r['total']), 'Failed: '.number_format($r['failed']).' ('.$pct($r['failed'], $r['total']).')',
        'Real failures (not the customer\'s side, e.g. not "no balance"): '.number_format($r['real']).' ('.$pct($r['real'], $r['total']).')', ''];
    if (!$r['real']) return implode("\n", array_merge($l, ['No real failures. Good day.']));
    $l[] = 'BY REASON'; foreach ($r['reasons'] as $x) $l[] = sprintf('  %7s  %s', number_format((int)$x['rf']), $x['k']); $l[] = '';
    $l[] = 'BY OFFER (failed / attempts, top reason)'; foreach ($r['offers'] as $x) $l[] = sprintf('  %7s / %-7s  %s%s', number_format((int)$x['rf']), number_format((int)$x['n']), (string)$x['k'] === '' ? '(no offer recorded)' : $x['k'].' '.($x['name'] ?? ''), !empty($x['top_reason']) ? ' — '.$x['top_reason'] : ''); $l[] = '';
    $l[] = 'BY VENDOR (failed / attempts)'; foreach ($r['vendors'] as $x) $l[] = sprintf('  %7s / %-7s  %s', number_format((int)$x['rf']), number_format((int)$x['n']), $x['k']); $l[] = '';
    $l[] = 'BY HOUR (failed / attempts)'; foreach ($r['time'] as $x) if ((int)$x['rf'] > 0) $l[] = sprintf('  %02d:00  %6s / %s', (int)$x['k'], number_format((int)$x['rf']), number_format((int)$x['n']));
    $l[] = ''; $l[] = 'Details: VAS Cloud portal → Monitoring → Real failures (choose "Yesterday").';
    return implode("\n", $l);
}
function send_failure_report(string $schema, ?string $date = null): ?string {
    $to = failure_report_recipients(); if (!$to) return 'no recipient for the real-failure report';
    $c = smtp_server_settings(); if (!$c) return 'email is not configured';
    $date = $date ?: date('Y-m-d', strtotime('yesterday')); $c['to'] = $to;
    $err = smtp_send($c, '[VAS Cloud] Real failures '.$schema.' '.$date, failure_report_text($schema, $date));
    audit('failure_report', $schema, null, $date, $err ?? 'sent to '.count($to));
    return $err;
}
// Once a day after the chosen hour, from the 5-minute job (claimed first so two runs cannot both send it).
function maybe_send_failure_report(string $schema): void {
    $cfg = alert_config();
    if (!$cfg['failure_report_enabled'] || (int)date('G') < $cfg['failure_report_hour'] || !table_exists($schema, AUDIT_LOG_TABLE)) return;
    $key = 'failure_report:'.$schema.':'.date('Y-m-d'); $db = portal_pdo();
    $claim = $db->prepare('INSERT IGNORE INTO alert_notification_log(alert_key,last_sent_at) VALUES(?,NOW())'); $claim->execute([$key]);
    if ($claim->rowCount() !== 1) return;
    if (send_failure_report($schema) !== null) $db->prepare('DELETE FROM alert_notification_log WHERE alert_key=?')->execute([$key]);
}
