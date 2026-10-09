<?php
declare(strict_types=1);
// Refunds: investigate, deducted / received, refund through Hera BundleSubscription.

// ---- Refunds: look into a subscription first, then ask Hera (BundleSubscription, channel REF) to refund it ----
// The Refunds page lets an admin investigate a number (its subscriptions, its transactions, the portal's own purchases), then fill in the five fields of
// the request — offer code, vendor, number, channel (REF) and the time it was bought — preview exactly what will be sent, and send it. Hera is asked once
// per number + offer + time: a refund that went through is never sent again; a failed or test one can be tried again. off = refused here, test = recorded and
// nothing sent, live = sent. Every request, who sent it and Hera's reply are kept.
function refund_tables(): void {
    static $done = false; if ($done) return;
    portal_pdo()->exec("CREATE TABLE IF NOT EXISTS refund_requests (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, msisdn VARCHAR(30) NOT NULL, offer_code VARCHAR(45) NOT NULL, vendor VARCHAR(45) NOT NULL, channel VARCHAR(20) NOT NULL, sub_date DATETIME NOT NULL,
        mode VARCHAR(8) NOT NULL, status VARCHAR(10) NOT NULL, http_code INT NULL, request_body TEXT NULL, response TEXT NULL, note VARCHAR(255) NULL, purchase_id INT NULL,
        created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_msisdn (msisdn, created_at), INDEX idx_key (msisdn, offer_code, sub_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
// A number as the tables hold it (220 + the local digits): 6704843, 2206704843 and 866600770 all work.
function refund_full_msisdn(string $raw): string { $d = preg_replace('/\D+/', '', $raw); if ($d === '') return ''; return str_starts_with($d, '220') && strlen($d) >= 10 ? $d : '220'.$d; }
// Checks the five fields and puts them in the shape Hera wants (the number without 220, the time as 2025-08-08 15:30:18).
function refund_normalize(array $in): array {
    $offer = trim((string)($in['offer_code'] ?? '')); if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/', $offer)) throw new RuntimeException('Enter the offer code (letters, digits, - or _).');
    $vendor = trim((string)($in['vendor'] ?? '')); if (!preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $vendor)) throw new RuntimeException('Enter the vendor, for example huawei.');
    $full = refund_full_msisdn((string)($in['msisdn'] ?? '')); if (!preg_match('/^\d{9,15}$/', $full)) throw new RuntimeException("Enter the customer's number (7 digits, or with 220 in front).");
    $channel = strtoupper(trim((string)($in['channel'] ?? 'REF'))); if ($channel === '') $channel = 'REF'; if (!preg_match('/^[A-Z0-9_-]{1,20}$/', $channel)) throw new RuntimeException('The channel is a short word such as REF.');
    $date = trim((string)($in['date'] ?? '')); if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date) || strtotime($date) === false) throw new RuntimeException('The date must look like 2025-08-08 15:30:18 (when the bundle was bought).');
    if (strtotime($date) > time() + 86400) throw new RuntimeException('That date is in the future.');
    return ['offer_code' => $offer, 'vendor' => $vendor, 'msisdn_full' => $full, 'msisdn' => ussd_local_number($full), 'channel' => $channel, 'date' => $date];
}
// The request exactly as in Hera's example: {"offerCode":"40004","date":"2025-08-08 15:30:18","vendor":"huawei","msisdn":"6704843","channel":"REF"}
function refund_body(array $n): string {
    return (string)json_encode(['offerCode' => $n['offer_code'], 'date' => $n['date'], 'vendor' => $n['vendor'], 'msisdn' => $n['msisdn'], 'channel' => $n['channel']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
// What a logged request says about the offer and vendor ("offerCode" and "vendor" anywhere in the JSON).
function refund_hints(?string $payload): array {
    $out = ['offer_code' => '', 'vendor' => '']; $j = json_decode((string)$payload, true);
    $walk = function ($v) use (&$walk, &$out): void { if (!is_array($v)) return;
        foreach ($v as $k => $x) { $lk = strtolower((string)$k);
            if (is_scalar($x)) { if ($out['offer_code'] === '' && in_array($lk, ['offercode', 'offer_code'], true) && (string)$x !== '') $out['offer_code'] = (string)$x; if ($out['vendor'] === '' && $lk === 'vendor' && (string)$x !== '') $out['vendor'] = (string)$x; }
            else $walk($x); } };
    if (is_array($j)) $walk($j);
    elseif (preg_match('/"offerCode"\s*:\s*"([^"]+)"/i', (string)$payload, $m)) $out['offer_code'] = $m[1];
    return $out;
}
// The catalogue row of an offer: ['vendor' => …, 'price' => number|null] (the schema in use first, then the test catalogue). Asked once per request.
function refund_offer_row(string $schema, string $offer): array {
    static $seen = []; $k = $schema.'|'.$offer; if (isset($seen[$k])) return $seen[$k]; $seen[$k] = ['vendor' => '', 'price' => null];
    foreach (array_unique([$schema, USSD_OFFER_SCHEMA]) as $s) {
        try { if (!table_exists($s, 'vas_offers')) continue; $st = pdo($s)->prepare('SELECT vendor, one_time_price FROM vas_offers WHERE offer_code=? ORDER BY id DESC LIMIT 1'); $st->execute([$offer]); $r = $st->fetch();
            if ($r && (string)$r['vendor'] !== '') return $seen[$k] = ['vendor' => (string)$r['vendor'], 'price' => is_numeric($r['one_time_price']) ? (float)$r['one_time_price'] : null]; } catch (Throwable $e) {}
    }
    return $seen[$k];
}
function refund_vendor_for(string $schema, string $offer): string { return refund_offer_row($schema, $offer)['vendor']; }
// ---- Was it deducted? Was the bundle received? ----
// A purchase leaves two traces in the platform log. OcsProduction is the charge; its transaction id says what it was:
//   20261008155509-HERA-0353-USSD-866060456-40101  = time, sequence, channel, number, offer. Status 0 = taken, 20000005 = balance too low.
// PcrfProduction gives the bundle (data); its id has no number or offer, but it is logged in the same second for the same number.
//   status 0 = given; 90398 "Exceeds the limit of repeatedly provisioned service…" or "Parameter missing <SRVNAME>" = refused.
// Deducted and not received = OCS took the money, PCRF refused the bundle. A purchase with no PCRF record at all (voice, SMS…) is shown as deducted, "not recorded".
function refund_parse_ocs_txn(string $txn): ?array {
    if (!preg_match('/^(\d{14})-HERA-(\d+)-([A-Za-z]+)-(\d{7,15})-([A-Za-z0-9_]+)$/', trim($txn), $m)) return null;
    return ['seq' => $m[2], 'channel' => strtoupper($m[3]), 'msisdn' => $m[4], 'offer_code' => $m[5]];
}
// The charging system's answer is a SOAP message (FeeDeductionResultMsg): result code/text, a deduction serial number and the balance before and after.
// Amounts are in units of 1/10,000,000 of a dalasi (a D190 purchase takes 190 → 0 shown as 1900000000 → 0).
const OCS_UNITS_PER_D = 10000000;
function refund_parse_ocs_output(?string $xml): ?array {
    $xml = (string)$xml; if ($xml === '' || !str_contains($xml, 'FeeDeduction')) return null;
    $tag = function (string $name) use ($xml): ?string { return preg_match('~<(?:\w+:)?'.$name.'>\s*([^<]*?)\s*</~', $xml, $m) ? $m[1] : null; };
    preg_match_all('~<(?:\w+:)?OldBalanceAmt>\s*(-?\d+)\s*</~', $xml, $o); preg_match_all('~<(?:\w+:)?NewBalanceAmt>\s*(-?\d+)\s*</~', $xml, $n);
    $old = 0.0; $new = 0.0; foreach ($o[1] as $k => $v) { $old += (float)$v; $new += (float)($n[1][$k] ?? $v); }
    return ['code' => $tag('ResultCode'), 'desc' => $tag('ResultDesc'), 'serial' => $tag('DeductSerialNo'), 'old_d' => $o[1] ? $old / OCS_UNITS_PER_D : null, 'new_d' => $o[1] ? $new / OCS_UNITS_PER_D : null, 'taken_d' => $o[1] ? ($old - $new) / OCS_UNITS_PER_D : null];
}
// $rows: platform-log rows (transaction_id, create_date, msisdn, vendor_entity_name, result_status, result_description, output_text). Returns one entry per OCS purchase (or refund).
function refund_assess(array $rows): array {
    $isVendor = fn($r, $k) => stripos((string)($r['vendor_entity_name'] ?? ''), $k) !== false; $pcrf = []; $refs = [];
    foreach ($rows as $r) {
        if ($isVendor($r, 'pcrf')) $pcrf[trim((string)$r['msisdn'])][] = $r;
        $p = refund_parse_ocs_txn((string)$r['transaction_id']); if ($p && $p['channel'] === 'REF' && $isVendor($r, 'ocs') && is_success_status($r['result_status'])) $refs[$p['msisdn'].'|'.$p['offer_code']][] = (string)$r['create_date'];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!$isVendor($r, 'ocs') || !($p = refund_parse_ocs_txn((string)$r['transaction_id']))) continue;
        $e = ['date' => (string)$r['create_date'], 'msisdn' => $p['msisdn'], 'offer_code' => $p['offer_code'], 'channel' => $p['channel'], 'transaction_id' => (string)$r['transaction_id'], 'ocs_result' => trim((string)($r['result_description'] ?? '')),
            'deducted' => null, 'received' => null, 'verdict' => '', 'reason' => '', 'refunded' => false, 'taken_d' => null, 'old_d' => null, 'new_d' => null, 'serial' => ''];
        if ($ocs = refund_parse_ocs_output($r['output_text'] ?? null)) { $e['taken_d'] = $ocs['taken_d']; $e['old_d'] = $ocs['old_d']; $e['new_d'] = $ocs['new_d']; $e['serial'] = (string)$ocs['serial']; }
        if ($p['channel'] === 'REF') { $e['verdict'] = 'refund'; $e['deducted'] = is_success_status($r['result_status']); $out[] = $e; continue; }
        if (!is_success_status($r['result_status'])) { $e['deducted'] = false; $e['verdict'] = 'not_deducted'; $e['reason'] = $e['ocs_result']; $out[] = $e; continue; }
        $e['deducted'] = true; $t = strtotime($e['date']); $good = false; $bad = null;
        foreach ($pcrf[$p['msisdn']] ?? [] as $q) if (abs(strtotime((string)$q['create_date']) - $t) <= 2) { if (is_success_status($q['result_status'])) $good = true; else $bad = $bad ?? $q; }
        if ($bad && !$good) { $e['received'] = false; $e['verdict'] = 'deducted_not_received'; $e['reason'] = trim((string)$bad['result_description']); }
        elseif ($good) { $e['received'] = true; $e['verdict'] = 'received'; }
        else $e['verdict'] = 'deducted';
        foreach ($refs[$p['msisdn'].'|'.$p['offer_code']] ?? [] as $rt) if (strtotime($rt) >= $t) $e['refunded'] = true;
        $out[] = $e;
    }
    return $out;
}
// Marks the entries the portal itself has already refunded (a request that went through, or whose answer was unclear).
function refund_mark_done(array $entries): array {
    if (!$entries) return $entries;
    try { refund_tables(); $st = portal_pdo()->prepare("SELECT 1 FROM refund_requests WHERE msisdn=? AND offer_code=? AND sub_date=? AND status IN ('ok','sent','pending') LIMIT 1");
        foreach ($entries as &$e) { if ($e['refunded']) continue; $st->execute([ussd_local_number((string)$e['msisdn']), $e['offer_code'], $e['date']]); if ($st->fetchColumn()) $e['refunded'] = true; } unset($e); }
    catch (Throwable $e) {}
    return $entries;
}
// Everyone's purchases that were deducted and not received, in a date range (the platform log, newest first; PCRF refusals joined to the OCS charge of the same second).
const REFUND_MISSING_MAX_DAYS = 7;
function refund_missing(string $schema, string $from, string $to, int $limit = 100): array {
    if (!table_exists($schema, AUDIT_LOG_TABLE)) throw new RuntimeException(AUDIT_LOG_TABLE.' does not exist in '.$schema);
    $f = audit_log_filters_from_request(['date_from' => $from, 'date_to' => $to]);
    if ((strtotime($to) - strtotime($from)) / 86400 >= REFUND_MISSING_MAX_DAYS) throw new RuntimeException('This list looks at up to '.REFUND_MISSING_MAX_DAYS.' days at a time.');
    $db = pdo($schema);
    $st = $db->prepare("SELECT create_date, msisdn, result_status, result_description FROM ".ident(AUDIT_LOG_TABLE)." WHERE create_date BETWEEN ? AND ? AND vendor_entity_name LIKE 'Pcrf%' AND NOT (".AUDIT_SUCCESS_SQL.") ORDER BY create_date DESC LIMIT ".(int)($limit * 2));
    $st->execute([$f['date_from'].' 00:00:00', $f['date_to'].' 23:59:59']); $fails = $st->fetchAll();
    $oc = $db->prepare("SELECT create_date, transaction_id, result_status, result_description, vendor_entity_name, msisdn, CONVERT(output USING utf8mb4) AS output_text FROM ".ident(AUDIT_LOG_TABLE)." WHERE msisdn = ? AND create_date BETWEEN ? AND ? AND vendor_entity_name LIKE 'Ocs%' ORDER BY create_date");
    $out = []; $seen = [];
    foreach ($fails as $x) {
        $m = trim((string)$x['msisdn']); $t = strtotime((string)$x['create_date']); $key = $m.'|'.$x['create_date']; if (isset($seen[$key])) continue; $seen[$key] = 1;
        $oc->execute([$m, date('Y-m-d H:i:s', $t - 3), date('Y-m-d H:i:s', $t + 3)]);
        foreach (refund_assess(array_merge($oc->fetchAll(), [['transaction_id' => '', 'create_date' => $x['create_date'], 'msisdn' => $m, 'vendor_entity_name' => 'PcrfProduction', 'result_status' => $x['result_status'], 'result_description' => $x['result_description']]])) as $e)
            if ($e['verdict'] === 'deducted_not_received' && !isset($seen['t:'.$e['transaction_id']])) { $seen['t:'.$e['transaction_id']] = 1; $out[] = $e; }
        if (count($out) >= $limit) break;
    }
    return refund_mark_done($out);
}
// Everything the portal knows about a number in a date range: its subscriptions, its transactions (with the offer and vendor they carried) and the
// purchases made through the USSD menu (as buyer or as the other number). Hera's own tables hold the number in the local form (6704843) while the USSD
// ledger holds it with 220, so each table is asked in the local form first and, only if that finds nothing, with 220. A part that cannot be read comes
// back as an error message, not an exception. Subscriptions may be searched over a long range; the transaction log only over its newest 31 days.
const REFUND_SUBSCRIPTION_MAX_DAYS = 400;
function refund_lookup(string $schema, string $msisdnFull, string $from, string $to): array {
    $out = ['subscriptions' => [], 'transactions' => [], 'assessed' => [], 'purchases' => [], 'errors' => [], 'notes' => [], 'form' => []];
    $local = ussd_local_number($msisdnFull); $forms = array_values(array_unique([$local, $msisdnFull])); $tsTo = strtotime($to);
    $subFrom = max(strtotime($from), $tsTo - (REFUND_SUBSCRIPTION_MAX_DAYS - 1) * 86400); $logFrom = max(strtotime($from), $tsTo - (AUDIT_LOG_MAX_RANGE_DAYS - 1) * 86400);
    if ($subFrom > strtotime($from)) $out['notes'][] = 'Subscriptions are searched for at most '.REFUND_SUBSCRIPTION_MAX_DAYS.' days (from '.date('Y-m-d', $subFrom).').';
    if ($logFrom > strtotime($from)) $out['notes'][] = 'The transaction log is only searched for the newest '.AUDIT_LOG_MAX_RANGE_DAYS.' days of the range (from '.date('Y-m-d', $logFrom).') — narrow the dates to look at an older day.';
    try { if (!table_exists($schema, 'subscription')) throw new RuntimeException('subscription does not exist in '.$schema);
        foreach ($forms as $m) { $rows = search_subscriptions($schema, ['msisdn' => $m, 'transaction_id' => '', 'subscription_type' => '', 'channel' => '', 'date_from' => date('Y-m-d', $subFrom), 'date_to' => $to], 1, 30)['rows']; if ($rows) { $out['subscriptions'] = $rows; $out['form']['subscriptions'] = $m; break; } } }
    catch (Throwable $e) { $out['errors']['subscriptions'] = $e->getMessage(); }
    try { foreach ($forms as $m) { $rows = search_audit_log($schema, ['date_from' => date('Y-m-d', $logFrom), 'date_to' => $to, 'msisdn' => $m, 'transaction_id' => '', 'result_status' => '', 'vendor' => '', 'channel' => '', 'result_desc' => ''], 1, 100)['rows'];
            if ($rows) { foreach ($rows as $r) { $h = refund_hints($r['input_text'] ?? ''); $out['transactions'][] = ['transaction_id' => $r['transaction_id'], 'create_date' => $r['create_date'], 'msisdn' => $r['msisdn'], 'channel' => $r['channel'], 'vendor_entity_name' => $r['vendor_entity_name'], 'result_status' => $r['result_status'], 'result_description' => $r['result_description'], 'output_text' => mb_substr((string)($r['output_text'] ?? ''), 0, 4000)] + $h; }
                $out['assessed'] = refund_mark_done(refund_assess($out['transactions'])); $out['form']['transactions'] = $m; break; } } }
    catch (Throwable $e) { $out['errors']['transactions'] = $e->getMessage(); }
    try { ussd_purchase_table(); $st = portal_pdo()->prepare('SELECT * FROM ussd_purchases WHERE msisdn IN (?,?) OR recipient IN (?,?) ORDER BY id DESC LIMIT 15'); $st->execute([$msisdnFull, $local, $msisdnFull, $local]); $out['purchases'] = $st->fetchAll(); }
    catch (Throwable $e) { $out['errors']['purchases'] = $e->getMessage(); }
    return $out;
}
function refund_history(int $limit = 25): array { refund_tables(); return portal_pdo()->query('SELECT * FROM refund_requests ORDER BY id DESC LIMIT '.(int)$limit)->fetchAll(); }
// Sends one refund. $purchaseId (optional) ties it to a row of the USSD purchase ledger, which then shows it too.
function refund_send(array $cfg, array $in, string $by = '', ?int $purchaseId = null): array {
    $mode = in_array($cfg['refund_mode'], ['test', 'live'], true) ? $cfg['refund_mode'] : 'off';
    if ($mode === 'off') throw new RuntimeException('Refunds are switched off (USSD Proxy → Buying offers → Refunds).');
    $n = refund_normalize($in); refund_tables(); $db = portal_pdo(); $body = refund_body($n);
    $lock = 'refund:'.md5($n['msisdn'].'|'.$n['offer_code'].'|'.$n['date']); $got = (int)$db->query('SELECT GET_LOCK('.$db->quote($lock).', 5)')->fetchColumn();
    try {
        $done = $db->prepare("SELECT created_at FROM refund_requests WHERE msisdn=? AND offer_code=? AND sub_date=? AND status IN ('ok','pending','sent') LIMIT 1"); $done->execute([$n['msisdn'], $n['offer_code'], $n['date']]);
        if ($d = $done->fetch()) throw new RuntimeException('A refund for this number, offer and time was already sent ('.$d['created_at'].'). It is not sent twice.');
        $ins = $db->prepare("INSERT INTO refund_requests(msisdn,offer_code,vendor,channel,sub_date,mode,status,request_body,purchase_id,created_by) VALUES(?,?,?,?,?,?,'pending',?,?,?)");
        $ins->execute([$n['msisdn'], $n['offer_code'], $n['vendor'], $n['channel'], $n['date'], $mode, $body, $purchaseId, mb_substr($by, 0, 80)]); $id = (int)$db->lastInsertId();
    } finally { if ($got) $db->query('SELECT RELEASE_LOCK('.$db->quote($lock).')'); }
    $http = null; $resp = null;
    if ($mode === 'test') { $status = 'test'; $note = 'TEST: nothing was sent to Hera.'; }
    else {
        $hc = $cfg; if (($cfg['refund_auth'] ?? '') !== '') $hc['purchase_auth'] = $cfg['refund_auth']; // the refund's own headers, when saved
        $r = ussd_hera_post($hc, $body, (string)$cfg['refund_url']); $http = $r['code']; $resp = $r['error'] !== '' ? 'error: '.$r['error'] : mb_substr($r['raw'], 0, 1000);
        [$status, $note] = refund_read_reply($r);
    }
    $db->prepare('UPDATE refund_requests SET status=?, http_code=?, response=?, note=? WHERE id=?')->execute([$status, $http, $resp, mb_substr($note, 0, 255), $id]);
    if ($purchaseId) { ussd_purchase_table(); $db->prepare("UPDATE ussd_purchases SET refund_status=?, refund_at=NOW(), refund_by=?, refund_request=?, refund_response=?, refund_note=? WHERE id=?")->execute([$status, mb_substr($by, 0, 80), $body, $resp, mb_substr($note, 0, 255), $purchaseId]); }
    audit('refund_send', null, 'refund_requests', (string)$id, json_encode(['mode' => $mode, 'status' => $status, 'offer' => $n['offer_code'], 'msisdn' => $n['msisdn'], 'date' => $n['date'], 'http' => $http]));
    return ['id' => $id, 'status' => $status, 'note' => $note, 'mode' => $mode, 'sent' => $body];
}
// What Hera's answer means: ok / failed, or sent when it answered in a way that cannot be read as either (then the refund is NOT sent again until
// somebody has looked — the history shows the raw reply). Understands {"success":true|false,"message":…} and the resultCode shapes.
function refund_read_reply(array $r): array {
    if ($r['error'] !== '') return ['failed', 'No answer from Hera: '.$r['error']];
    $j = json_decode((string)$r['raw'], true); $ok2xx = $r['code'] >= 200 && $r['code'] < 300;
    $text = is_array($j) ? trim((string)($j['message'] ?? $j['errorMessage'] ?? '')) : ''; if ($text === '') $text = ussd_share_text(is_array($j) ? $j : null, (string)$r['raw']);
    $text = mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 200);
    if (!$ok2xx) return ['failed', 'HTTP '.$r['code'].($text !== '' ? ': '.$text : '')];
    if (is_array($j) && array_key_exists('success', $j)) { $good = $j['success'] === true || strtolower((string)$j['success']) === 'true'; return [$good ? 'ok' : 'failed', $text !== '' ? $text : ($good ? 'Accepted.' : 'Refused.')]; }
    if (is_array($j) && (isset($j['resultCode']) || is_array($j['result'] ?? null))) return [flow_classify(['ok_code' => '0', 'lowbal' => ''], $r)['outcome'] === 'success' ? 'ok' : 'failed', $text !== '' ? $text : 'HTTP '.$r['code']];
    return ['sent', 'Hera answered, but the reply is not one the portal recognises — check it before trying again: '.mb_substr(trim(preg_replace('/\s+/', ' ', (string)$r['raw'])), 0, 120)];
}
// A purchase of the USSD menu where Hera said nothing was taken (deduction failed, balance too low) has nothing to refund: the ledger offers no link for it.
function ussd_refund_not_charged(array $p): bool { return (bool)preg_match('/deduction|insufficient|low balance|not enough/i', (string)($p['response'] ?? '').' '.(string)($p['reply_text'] ?? '')); }
function ussd_refund_eligible(array $p): bool {
    if (($p['mode'] ?? '') !== 'live' || in_array($p['refund_status'] ?? '', ['ok', 'pending'], true)) return false;
    return in_array($p['status'] ?? '', ['ok', 'sent'], true) || (($p['status'] ?? '') === 'failed' && !ussd_refund_not_charged($p));
}
function save_refund_config(array $d): void {
    $mode = in_array($d['refund_mode'] ?? '', ['test', 'live'], true) ? $d['refund_mode'] : 'off'; $url = trim((string)($d['refund_url'] ?? ''));
    if ($url !== '' && (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 500)) throw new RuntimeException('The refund address must be a full http:// or https:// URL.');
    if ($mode === 'live' && $url === '') throw new RuntimeException('Live refunds need the refund address. Use Test until you have it.');
    $vals = ['refund_mode' => $mode, 'refund_url' => $url]; $auth = trim((string)($d['refund_auth'] ?? ''));
    if (!empty($d['refund_auth_clear'])) $vals['refund_auth'] = ''; elseif ($auth !== '') $vals['refund_auth'] = encrypt_secret(ussd_clean_header_text($auth));
    ussd_proxy_set($vals); audit('ussd_refund_save', null, 'ussd_proxy_config', null, 'mode='.$mode.' url='.parse_url($url, PHP_URL_HOST).' headers='.(isset($vals['refund_auth']) ? ($vals['refund_auth'] === '' ? 'removed' : 'changed') : 'unchanged'));
}
