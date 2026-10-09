<?php
declare(strict_types=1);
// Shared Bundle (Seddo) calls.

// ---- Shared Bundle (Seddo): the ShareBundle calls under .../hera/prepaid/ShareBundle/ ----
// share_mode: off = the service says it is not switched on; test = every answer is simulated and nothing is sent;
// live = real calls. Calls that change an account (subscribe, add number) need their request body saved first
// (blank = blocked); validating a number with no body saved is simply skipped.
const SHARE_PATHS = ['subscribe' => 'subscribe', 'validate' => 'addNumber', 'add' => 'addNumber', 'balance' => 'DataUsage', 'numbers' => 'listNumber'];
function ussd_share_sim(string $op, array $p): array {
    return match ($op) {
        'validate' => ['ok' => true, 'text' => ''],
        'balance' => ['ok' => true, 'text' => "Your Seddo bundle balance is:\n12GB data\n1200 mins, 1200 SMS (TEST)"],
        'numbers' => ['ok' => true, 'text' => "My Seddo numbers:\n1. 866***111\n2. 866***222 (TEST)"],
        default => ['ok' => true, 'text' => 'TEST'],
    };
}
// One call to a ShareBundle address. Returns the HTTP outcome plus Hera's text and whether it says success.
function ussd_share_http(array $cfg, string $op, array $p): array {
    $tpl = (string)$cfg['share_body_'.$op];
    $esc = fn($s) => substr((string)json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    $body = strtr($tpl, ['{msisdn}' => $esc($p['msisdn'] ?? ''), '{other_msisdn}' => $esc($p['other_raw'] ?? $p['other'] ?? ''), '{offer_code}' => $esc($p['offer_code'] ?? ''), '{vendor}' => $esc($p['vendor'] ?? ''),
        '{other_offer_code}' => $esc($p['other_offer_code'] ?? ''), '{price}' => $esc($p['price'] ?? ''), '{price_d}' => $esc(($p['price'] ?? '') !== '' ? 'D'.$p['price'] : ''), '{txn}' => $esc($p['txn'] ?? ''),
        '{shortcode}' => $esc($cfg['shortcode_proxy']), '{imsi}' => $esc($p['ctx']['imsi'] ?? ''), '{local_dialog_id}' => isset($p['ctx']['localDialogID']) ? (string)$p['ctx']['localDialogID'] : 'null', '{remote_dialog_id}' => isset($p['ctx']['remoteDialogID']) ? (string)$p['ctx']['remoteDialogID'] : 'null',
        '{local_address_json}' => json_encode($p['ctx']['localAddress'] ?? null, JSON_UNESCAPED_SLASHES), '{remote_address_json}' => json_encode($p['ctx']['remoteAddress'] ?? null, JSON_UNESCAPED_SLASHES)]);
    $r = ussd_hera_post($cfg, $body, rtrim((string)$cfg['share_base'], '/').'/'.SHARE_PATHS[$op]); $r['sent'] = $body;
    $j = json_decode((string)$r['raw'], true); $res = is_array($j) && is_array($j['result'] ?? null) ? $j['result'] : [];
    $code = isset($res['resultCode']) ? (string)$res['resultCode'] : (isset($j['resultCode']) ? ($j['resultCode'] === '000' ? $cfg['share_ok_code'] : (string)$j['resultCode']) : '');
    $r['ok'] = $r['error'] === '' && $r['code'] >= 200 && $r['code'] < 300 && $code !== '' && $code === (string)$cfg['share_ok_code'];
    // a server error page ("status 500 …") is not something to show a customer: only a successful HTTP answer has text for them
    $r['text'] = ($r['error'] === '' && $r['code'] >= 200 && $r['code'] < 300) ? ussd_share_text($j, (string)$r['raw']) : '';
    // Balance: Hera lists the bundles as result.package1, package2 … (empty ones blank): "Your Seddo bundle balance is:" and one line each
    if ($op === 'balance' && $r['ok'] && is_array($j['result'] ?? null)) {
        $text = 'Your Seddo bundle balance is:'; $any = false;
        for ($i = 1; $i <= 9; $i++) {
            $v = $j['result']['package'.$i] ?? ''; if (!is_string($v) || trim($v) === '') continue;
            if (mb_strlen($text."\n".trim($v)) > 150) break; // one screen: drop what does not fit
            $text .= "\n".trim($v); $any = true;
        }
        $r['text'] = $any ? $text : 'You have no active Seddo bundle balance.';
    }
    // My numbers: result.msisdn1 … msisdn9, each {order, msisdn, separator1, limit, usage}; unused rows are blank.
    // One line per used row, "{order} {msisdn} {separator1} {limit}" as in the diagram; Hera puts "You don't have Seddo number" in the first row's order when there are none.
    if ($op === 'numbers' && $r['ok'] && is_array($j['result'] ?? null)) {
        $lines = [];
        for ($i = 1; $i <= 9; $i++) {
            $row = $j['result']['msisdn'.$i] ?? null; if (!is_array($row)) continue;
            $line = trim(implode(' ', array_filter(array_map(fn($k) => $k === 'msisdn' ? ussd_local_number((string)($row[$k] ?? '')) : trim((string)($row[$k] ?? '')), ['order', 'msisdn', 'separator1', 'limit']), fn($v) => $v !== '')));
            if ($line === '') continue;
            if (mb_strlen(implode("\n", array_merge($lines, [$line]))) > 150) break;
            $lines[] = $line;
        }
        $r['text'] = $lines ? implode("\n", $lines) : "You don't have a Seddo number.";
    }
    return $r;
}
// What to show a customer from Hera's reply: its own description when it has one, otherwise the reply's values laid out as lines.
function ussd_share_text($j, string $raw): string {
    $clip = fn(string $s) => mb_substr(trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\R+/', "\n", trim($s)))), 0, 150);
    if (!is_array($j)) return $clip(strip_tags($raw));
    $desc = $j['result']['resultDescription'] ?? null;
    // Hera also answers with the reason at the top level, e.g. {"resultCode":"9990","resultDescription":"You don't have active Seddo Subscription"}
    if ($desc === null && isset($j['resultCode']) && (string)$j['resultCode'] !== '000' && is_string($j['resultDescription'] ?? null)) $desc = $j['resultDescription'];
    $payload = $j['result'] ?? $j; $lines = [];
    $walk = function ($v, $key = '') use (&$walk, &$lines) {
        // a list of records (e.g. the sharing numbers) becomes one line per record: "1 220111 2GB"
        if (is_array($v) && array_is_list($v) && $v && is_array($v[0])) { foreach ($v as $rec) { $vals = []; array_walk_recursive($rec, function ($x) use (&$vals) { if ($x !== null && $x !== '') $vals[] = $x; }); if ($vals) $lines[] = implode(' ', $vals); } return; }
        if (is_array($v)) { foreach ($v as $k => $x) $walk($x, is_string($k) ? $k : $key); return; }
        if ($v === null || $v === '' || $key === 'resultCode' || $key === 'resultDescription') return;
        $lines[] = (is_string($key) && $key !== '' ? $key.': ' : '').$v;
    };
    $walk($payload);
    $parts = []; if (is_string($desc) && trim($desc) !== '') $parts[] = trim($desc);
    if ($lines) $parts[] = implode("\n", array_slice($lines, 0, 8));
    return $clip(implode("\n", $parts));
}
// Read-only calls used while the customer browses (validating a number, balance, numbers).
function ussd_share_read(string $op, array $p): array {
    static $cfg = null; $cfg ??= ussd_proxy_config();
    if ($cfg['share_mode'] === 'off') return ['ok' => false, 'text' => 'Shared Bundle is not switched on yet.'];
    if ($cfg['share_mode'] === 'test') return ussd_share_sim($op, $p);
    if ($op === 'validate' && trim((string)$cfg['share_body_validate']) === '') return ['ok' => true, 'text' => ''];
    if (trim((string)$cfg['share_body_'.$op]) === '') return ['ok' => false, 'text' => 'This is not switched on yet.'];
    $p['txn'] = 'sb-'.bin2hex(random_bytes(4));
    $key = 'share:'.$op.':'.($p['msisdn'] ?? '').':'.($p['other'] ?? '');
    $r = cached($key, 30, fn() => ussd_share_http($cfg, $op, $p));
    if ($op === 'validate') return ['ok' => (bool)$r['ok'], 'text' => $r['ok'] ? '' : ($r['text'] !== '' ? $r['text'] : 'This number cannot be added.')];
    return ['ok' => (bool)$r['ok'], 'text' => $r['text'] !== '' ? $r['text'] : ($r['error'] !== '' ? 'Not available right now.' : '')];
}
// subscribe / add number: carried out once per call (same ledger as purchases, so it shows in the same table).
function ussd_share_execute(array $cfg, array $screen, string $msisdn, string $callId, array $ctx = []): array {
    $p = $screen['share']; $op = $p['op']; $msisdn = preg_replace('/\D+/', '', $msisdn); $mode = $cfg['share_mode'];
    $label = $op === 'subscribe' ? 'Seddo '.$p['name'] : 'the number '.ussd_local_number((string)($p['other'] ?? ''));
    if ($mode === 'off') return ['text' => 'Shared Bundle is not switched on yet. You were not charged.', 'note' => 'share: off'];
    if ($msisdn === '') return ['text' => 'Sorry, we could not process this request. You were not charged.', 'note' => 'share: no number'];
    $key = $op === 'subscribe' ? 'share:sub:'.$p['offer_code'] : 'share:add:'.$p['other']; $t0 = microtime(true);
    try {
        ussd_purchase_table(); $db = portal_pdo(); $callId = $callId !== '' ? substr($callId, 0, 120) : 'noid-'.bin2hex(random_bytes(6));
        $ins = $db->prepare("INSERT IGNORE INTO ussd_purchases(call_id,offer_code,msisdn,recipient,offer_name,price,mode,status) VALUES(?,?,?,?,?,?,?,'pending')");
        $ins->execute([$callId, substr($key, 0, 45), $msisdn, $op === 'add' ? $p['other'] : null, mb_substr($op === 'subscribe' ? $p['name'] : 'Add sharing number', 0, 80), mb_substr((string)($p['price'] ?? ''), 0, 20), 'sb-'.$mode]);
        if ($ins->rowCount() === 0) { $st = $db->prepare('SELECT reply_text FROM ussd_purchases WHERE call_id=? AND offer_code=?'); $st->execute([$callId, substr($key, 0, 45)]); return ['text' => (string)($st->fetchColumn() ?: 'Your request is already being processed.'), 'note' => 'share: repeated request, not sent again']; }
        $id = (int)$db->lastInsertId(); $http = null; $resp = null; $sent = null;
        if ($mode === 'test') { $status = 'test'; $text = 'TEST: '.($op === 'subscribe' ? 'you would be subscribed to '.$label : $label.' would be added').'. You were not charged.'; }
        elseif (trim((string)$cfg['share_body_'.$op]) === '') { $status = 'blocked'; $text = 'This part of Shared Bundle is not switched on yet. You were not charged.'; }
        else {
            $r = ussd_share_http($cfg, $op, ['ctx' => $ctx, 'msisdn' => $msisdn, 'other' => $p['other'] ?? '', 'other_raw' => $p['other_raw'] ?? '', 'offer_code' => $p['offer_code'] ?? '', 'vendor' => $p['vendor'] ?? '', 'other_offer_code' => $p['other_offer_code'] ?? '', 'price' => $p['price'] ?? '', 'txn' => $callId]);
            $http = $r['code']; $resp = $r['error'] !== '' ? 'error: '.$r['error'] : mb_substr((string)$r['raw'], 0, 1000); $sent = ussd_request_for_log((string)($r['sent'] ?? ''));
            $low = false; foreach (array_filter(array_map('trim', explode(',', (string)$cfg['purchase_lowbal']))) as $term) if (stripos((string)$r['raw'], $term) !== false) { $low = true; break; }
            $status = $r['ok'] ? 'ok' : ($low ? 'lowbal' : 'failed');
            // low balance gets a plain message; any other refusal shows Hera's own reason when it gave one
            $text = $low ? 'Sorry, your balance is too low for this bundle. Please top up and try again.' : ($r['text'] !== '' ? $r['text'] : ($r['ok'] ? 'Done.' : 'Sorry, this could not be completed. Please try again later.'));
        }
        $db->prepare('UPDATE ussd_purchases SET status=?, http_code=?, response=?, reply_text=?, ms=?, request_body=? WHERE id=?')->execute([$status, $http, $resp, mb_substr($text, 0, 255), (int)round((microtime(true) - $t0) * 1000), $sent, $id]);
        return ['text' => $text, 'note' => 'share: '.$op.' '.$mode.' '.$status.($http ? ' HTTP '.$http : '')];
    } catch (Throwable $e) {
        error_log('ussd share: '.$e->getMessage());
        return ['text' => 'Sorry, we could not process this request. Please try again later.', 'note' => 'share error: '.substr($e->getMessage(), 0, 100)];
    }
}
function save_share_config(array $d): void {
    $mode = in_array($d['share_mode'] ?? '', ['test', 'live'], true) ? $d['share_mode'] : 'off';
    $base = trim((string)($d['share_base'] ?? ''));
    if (!preg_match('#^https?://#i', $base) || !filter_var($base, FILTER_VALIDATE_URL) || mb_strlen($base) > 300) throw new RuntimeException('The ShareBundle address must be a full http:// or https:// URL, e.g. https://vas-testing.comium.gm/hera/prepaid/ShareBundle/');
    $ok = trim((string)($d['share_ok_code'] ?? '')); if ($ok === '' || mb_strlen($ok) > 12) throw new RuntimeException('Enter the result code that means success (e.g. 0).');
    $vals = ['share_mode' => $mode, 'share_base' => rtrim($base, '/').'/', 'share_ok_code' => $ok];
    foreach (['subscribe', 'validate', 'add', 'balance', 'numbers'] as $op) {
        $t = trim((string)($d['share_body_'.$op] ?? '')); if (mb_strlen($t) > 2000) throw new RuntimeException('A request body is at most 2000 characters.');
        $vals['share_body_'.$op] = $t;
    }
    ussd_proxy_set($vals);
    audit('ussd_share_save', null, 'ussd_proxy_config', null, 'mode='.$mode.' base='.parse_url($base, PHP_URL_HOST));
}
// A read-only question about the account (balance / numbers), to see what Hera's reply really looks like.
function share_ask(array $cfg, string $op, string $msisdn): array {
    $msisdn = preg_replace('/\D+/', '', $msisdn);
    if (!in_array($op, ['balance', 'numbers'], true)) throw new RuntimeException('Only balance and numbers can be asked from here.');
    if ($msisdn === '') throw new RuntimeException('Enter a phone number.');
    if (trim((string)$cfg['share_body_'.$op]) === '') throw new RuntimeException('Save a request body for "'.$op.'" first.');
    $r = ussd_share_http($cfg, $op, ['msisdn' => $msisdn, 'txn' => 'check-'.bin2hex(random_bytes(4))]);
    audit('share_ask', null, 'ussd_proxy_config', null, $op.' HTTP '.$r['code']);
    return ['code' => $r['code'], 'error' => $r['error'], 'raw' => mb_substr((string)$r['raw'], 0, 3000), 'text' => $r['text'], 'ok' => $r['ok']];
}
// A read-only question to Hera, like the ones the phone menu asks while you browse: what it says about one offer
// (chooseOffer) or one sub-category (listOffer). It can never buy: the operation is fixed to these two.
function hera_ask(array $cfg, string $op, string $msisdn, string $offerCode, string $subCategory): array {
    $msisdn = preg_replace('/\D+/', '', $msisdn);
    if (!in_array($op, ['chooseOffer', 'listOffer'], true)) throw new RuntimeException('Only chooseOffer and listOffer can be asked from here.');
    if ($msisdn === '') throw new RuntimeException('Enter a phone number.');
    $body = ['callID' => 'check-'.bin2hex(random_bytes(5)), 'originalRequest' => $cfg['shortcode_proxy'], 'msisdn' => $msisdn, 'isMobileOriginated' => true, 'mobileRequestIdentifier' => 1, 'isInitial' => false, 'isProxy' => false];
    if ($op === 'chooseOffer') { if (!preg_match('/^[A-Za-z0-9_.\-]{1,45}$/', $offerCode)) throw new RuntimeException('Enter the offer code.'); $body['offerCode'] = $offerCode; }
    else { if ($subCategory === '' || mb_strlen($subCategory) > 80) throw new RuntimeException('Enter the sub-category.'); $body += ['subCategory' => $subCategory, 'category' => 'Data-commercial-Launch', 'page' => '0', 'limitDisplay' => '9']; }
    $body['operation'] = $op;
    $r = ussd_hera_post($cfg, (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    audit('hera_ask', null, 'ussd_proxy_config', null, $op.' '.($op === 'chooseOffer' ? $offerCode : $subCategory).' HTTP '.$r['code']);
    return ['sent' => $body, 'code' => $r['code'], 'error' => $r['error'], 'raw' => mb_substr((string)$r['raw'], 0, 3000)];
}
// Headers as pasted ("Name: value" per line) -> the clean lines to store. Forgiving about what a paste brings along: a comma or quotes on the end, odd spaces
// or dashes, and headers with no value (Mobius sends X-HASHED-PASSWORD empty — such a line is simply skipped). A wrong line is reported by number only,
// never echoed back, because it may hold the key itself.
function ussd_clean_header_text(string $auth): string {
    $lines = [];
    foreach (preg_split('/\r\n|\n|\r/', $auth) as $n => $l) {
        $l = trim(str_replace(["\xC2\xA0", "\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x93", "\xE2\x80\x94"], [' ', '-', '-', '-', '-'], $l));
        if ($l === '') continue;
        if (!preg_match('/^([A-Za-z0-9\-]{1,40})\s*:\s*(.*)$/', $l, $m)) throw new RuntimeException('Line '.($n + 1).' of the headers has no "Name: value" shape — write each one like  X-USERNAME: USSD');
        $v = trim(rtrim(trim($m[2]), ','), " \t\"'");
        if ($v === '') continue;
        if (mb_strlen($v) > 400) throw new RuntimeException('The value on line '.($n + 1).' of the headers is too long.');
        $lines[] = $m[1].': '.$v;
    }
    if (count($lines) > 6) throw new RuntimeException('At most 6 headers.');
    if (!$lines) throw new RuntimeException('None of the header lines had a value. Write them like  X-API-KEY: your-key');
    return implode("\n", $lines);
}
function save_purchase_config(array $d): void {
    $mode = in_array($d['purchase_mode'] ?? '', ['test', 'test_low', 'live'], true) ? $d['purchase_mode'] : 'off';
    $url = trim((string)($d['purchase_url'] ?? ''));
    if ($url !== '' && (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 500)) throw new RuntimeException('The purchase address must be a full http:// or https:// URL.');
    if ($mode === 'live' && $url === '') throw new RuntimeException('Live purchases need the purchase address. Use Test mode until you have it.');
    $body = (string)($d['purchase_body'] ?? ''); if (trim($body) === '' || mb_strlen($body) > 2000) throw new RuntimeException('The request body must be filled in (at most 2000 characters).');
    $timeout = filter_var($d['purchase_timeout'] ?? null, FILTER_VALIDATE_INT); if ($timeout === false || $timeout < 2 || $timeout > 15) throw new RuntimeException('The purchase timeout must be between 2 and 15 seconds.');
    $match = trim((string)($d['purchase_ok_match'] ?? '')); if (mb_strlen($match) > 100) throw new RuntimeException('The success text is at most 100 characters.');
    $low = trim((string)($d['purchase_lowbal'] ?? '')); if (mb_strlen($low) > 200) throw new RuntimeException('The low-balance words are at most 200 characters.');
    $bodyOther = trim((string)($d['purchase_body_other'] ?? '')); if (mb_strlen($bodyOther) > 2000) throw new RuntimeException('The request body for another number is at most 2000 characters.');
    $field = trim((string)($d['purchase_reply_field'] ?? '')); if (!preg_match('/^[A-Za-z0-9_.\-]{0,40}$/', $field)) throw new RuntimeException('The reply field is just a name, e.g. message.');
    $vals = ['purchase_mode' => $mode, 'purchase_url' => $url, 'purchase_body' => $body, 'purchase_timeout' => (string)$timeout, 'purchase_ok_match' => $match, 'purchase_lowbal' => $low, 'purchase_reply_field' => $field, 'purchase_body_other' => $bodyOther, 'purchase_other_seeded' => '1'];
    $auth = trim((string)($d['purchase_auth'] ?? ''));
    if (!empty($d['purchase_auth_clear'])) $vals['purchase_auth'] = '';
    elseif ($auth !== '') {
        $vals['purchase_auth'] = encrypt_secret(ussd_clean_header_text($auth));
    }
    ussd_proxy_set($vals);
    audit('ussd_purchase_save', null, 'ussd_proxy_config', null, 'mode='.$mode.' url='.($url !== '' ? parse_url($url, PHP_URL_HOST) : '-').' header_changed='.(isset($vals['purchase_auth']) ? 'yes' : 'no'));
}
function ussd_result(string $text, bool $end, array $path, string $kind, ?array $node = null): array {
    return ['text' => $text, 'end' => $end, 'path' => $path, 'kind' => $kind, 'node_id' => $node['id'] ?? null, 'chars' => mb_strlen($text), 'too_long' => mb_strlen($text) > USSD_MAX_CHARS];
}
