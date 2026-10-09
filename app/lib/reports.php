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
