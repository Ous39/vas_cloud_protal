<?php
declare(strict_types=1);
// Purchases from the menu (ledger, request to Hera).

// ---- Purchases from the menu ----
// off  = nothing is bought; the customer is told it isn't switched on.
// test = the confirmation is recorded and the customer is told it was a test; no request leaves the portal.
// live = one HTTP request to the configured purchase address (the Hera test environment's API).
// Each (call, offer) is attempted at most once, however many times Mobius repeats a request.
function ussd_purchase_table(): void {
    static $done = false; if ($done) return;
    portal_pdo()->exec("CREATE TABLE IF NOT EXISTS ussd_purchases (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        call_id VARCHAR(120) NOT NULL, offer_code VARCHAR(45) NOT NULL, msisdn VARCHAR(30) NOT NULL, recipient VARCHAR(30) NULL,
        offer_name VARCHAR(80) NULL, price VARCHAR(20) NULL, mode VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL,
        http_code INT NULL, response TEXT NULL, reply_text VARCHAR(255) NULL, ms INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_call_offer (call_id, offer_code), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!portal_pdo()->query("SHOW COLUMNS FROM ussd_purchases LIKE 'request_body'")->fetch()) portal_pdo()->exec('ALTER TABLE ussd_purchases ADD COLUMN request_body TEXT NULL');
    if (!portal_pdo()->query("SHOW COLUMNS FROM ussd_purchases LIKE 'recipient'")->fetch()) portal_pdo()->exec('ALTER TABLE ussd_purchases ADD COLUMN recipient VARCHAR(30) NULL AFTER msisdn');
    if (!portal_pdo()->query("SHOW COLUMNS FROM ussd_purchases LIKE 'refund_status'")->fetch()) portal_pdo()->exec('ALTER TABLE ussd_purchases ADD COLUMN refund_status VARCHAR(10) NULL, ADD COLUMN refund_at DATETIME NULL, ADD COLUMN refund_by VARCHAR(80) NULL, ADD COLUMN refund_request TEXT NULL, ADD COLUMN refund_response TEXT NULL, ADD COLUMN refund_note VARCHAR(255) NULL');
    $done = true;
}
// The parts of Mobius's own request that Hera's purchase call repeats (the subscriber's IMSI, the SS7 addresses, dialog ids).
// The request as stored in the ledger: complete, but with the subscriber's IMSI shortened (nothing secret is ever in the body).
function ussd_request_for_log(string $body): string { return mb_substr((string)preg_replace('/("imsi":")(\d{3})\d+(\d{3})"/', '$1$2***$3"', $body), 0, 3000); }
function ussd_purchase_ctx(string $raw): array {
    $j = json_decode($raw, true); if (!is_array($j)) return [];
    $out = [];
    foreach (['imsi'] as $k) if (isset($j[$k]) && is_scalar($j[$k]) && preg_match('/^\d{5,20}$/', (string)$j[$k])) $out[$k] = (string)$j[$k];
    foreach (['localDialogID', 'remoteDialogID'] as $k) if (isset($j[$k]) && is_numeric($j[$k])) $out[$k] = (int)$j[$k];
    foreach (['localAddress', 'remoteAddress'] as $k) if (isset($j[$k]) && is_array($j[$k])) $out[$k] = $j[$k];
    return $out;
}
function ussd_purchase_http(array $cfg, string $msisdn, array $p, string $txn, array $ctx = []): array {
    $url = trim($cfg['purchase_url']);
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return ['code' => 0, 'raw' => '', 'error' => 'no valid purchase address saved'];
    $esc = fn($s) => substr((string)json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    $body = strtr(($p['recipient'] ?? '') !== '' ? $cfg['purchase_body_other'] : $cfg['purchase_body'], ['{other_msisdn}' => $esc($p['recipient_raw'] ?? $p['recipient'] ?? ''), '{msisdn}' => $esc($msisdn), '{offer_code}' => $esc($p['offer_code']), '{vendor}' => $esc($p['vendor'] ?? ''), '{other_offer_code}' => $esc($p['other_offer_code'] ?? ''),
        '{price}' => $esc($p['price']), '{price_d}' => $esc($p['price'] !== '' ? 'D'.$p['price'] : ''), '{txn}' => $esc($txn), '{shortcode}' => $esc($cfg['shortcode_proxy']),
        '{imsi}' => $esc($ctx['imsi'] ?? ''), '{local_dialog_id}' => isset($ctx['localDialogID']) ? (string)$ctx['localDialogID'] : 'null', '{remote_dialog_id}' => isset($ctx['remoteDialogID']) ? (string)$ctx['remoteDialogID'] : 'null',
        '{local_address_json}' => json_encode($ctx['localAddress'] ?? null, JSON_UNESCAPED_SLASHES), '{remote_address_json}' => json_encode($ctx['remoteAddress'] ?? null, JSON_UNESCAPED_SLASHES)]);
    $r = ussd_hera_post($cfg, $body); $r['sent'] = $body;
    return $r;
}
// POSTs a JSON body to the saved purchase address with the saved headers (one "Name: value" per line, kept encrypted).
function ussd_hera_post(array $cfg, string $body, ?string $url = null): array {
    $url = trim($url ?? $cfg['purchase_url']);
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return ['code' => 0, 'raw' => '', 'error' => 'no valid purchase address saved'];
    $hdr = ['Content-Type: application/json', 'Accept: application/json'];
    $auth = $cfg['purchase_auth'] !== '' ? decrypt_secret($cfg['purchase_auth']) : '';
    foreach (preg_split('/\r\n|\n/', $auth) as $line) if (preg_match('/^[A-Za-z0-9\-]{1,40}:\s*\S.*$/', trim($line))) $hdr[] = trim($line);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => max(2, min(15, (int)$cfg['purchase_timeout']))]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return ['code' => $code, 'raw' => is_string($raw) ? $raw : '', 'error' => $err];
}
// $screen is a result of kind 'purchase'. Returns the text to show the customer and a short note for the request log.
function ussd_execute_purchase(array $cfg, array $screen, string $msisdn, string $callId, array $ctx = []): array {
    $p = $screen['purchase']; $mode = in_array($cfg['purchase_mode'], ['test', 'test_low', 'live'], true) ? $cfg['purchase_mode'] : 'off';
    $lowText = 'Sorry, your balance is too low for '.$p['name'].'. Please top up and try again.';
    $msisdn = preg_replace('/\D+/', '', $msisdn); $recipient = preg_replace('/\D+/', '', (string)($p['recipient'] ?? ''));
    if ($mode === 'off') return ['text' => 'Purchases are not switched on yet. You were not charged.', 'note' => 'purchase: off'];
    if ($msisdn === '' || $p['offer_code'] === '') return ['text' => 'Sorry, we could not process this request. You were not charged.', 'note' => 'purchase: no number or offer code'];
    $t0 = microtime(true);
    try {
        ussd_purchase_table(); $db = portal_pdo();
        $callId = $callId !== '' ? substr($callId, 0, 120) : 'noid-'.bin2hex(random_bytes(6));
        $ins = $db->prepare("INSERT IGNORE INTO ussd_purchases(call_id,offer_code,msisdn,recipient,offer_name,price,mode,status) VALUES(?,?,?,?,?,?,?,'pending')");
        $ins->execute([$callId, $p['offer_code'], $msisdn, $recipient !== '' ? $recipient : null, mb_substr($p['name'], 0, 80), mb_substr($p['price'], 0, 20), $mode]);
        if ($ins->rowCount() === 0) {
            $st = $db->prepare('SELECT status, reply_text FROM ussd_purchases WHERE call_id=? AND offer_code=?'); $st->execute([$callId, $p['offer_code']]); $prev = $st->fetch();
            return ['text' => ($prev && $prev['reply_text']) ? $prev['reply_text'] : 'Your request is already being processed.', 'note' => 'purchase: repeated request, not sent again'];
        }
        $id = (int)$db->lastInsertId(); $httpCode = null; $resp = null; $sent = null;
        if ($mode === 'test') { $status = 'test'; $text = 'TEST: '.$p['name'].' would be bought for '.($recipient !== '' ? ussd_local_number($recipient).' (from '.ussd_local_number($msisdn).')' : ussd_local_number($msisdn)).'. You were not charged.'; }
        elseif ($mode === 'test_low') { $status = 'lowbal'; $text = $lowText; }
        elseif ($recipient !== '' && trim((string)$cfg['purchase_body_other']) === '') { $status = 'blocked'; $text = 'Buying for another number is not switched on yet. You were not charged.'; }
        else {
            $r = ussd_purchase_http($cfg, $msisdn, $p, $callId, $ctx); $sent = ussd_request_for_log((string)($r['sent'] ?? ''));
            $httpCode = $r['code']; $resp = $r['error'] !== '' ? 'error: '.$r['error'] : mb_substr($r['raw'], 0, 1000);
            $http2xx = $r['error'] === '' && $r['code'] >= 200 && $r['code'] < 300;
            $low = false;
            foreach (array_filter(array_map('trim', explode(',', (string)$cfg['purchase_lowbal']))) as $term) if (stripos((string)$r['raw'], $term) !== false) { $low = true; break; }
            $matched = $cfg['purchase_ok_match'] !== '' && stripos($r['raw'], $cfg['purchase_ok_match']) !== false;
            // Hera's own result code, when it gives one: result.resultCode "0" is success, anything else is a refusal ("-1" = deduction failed…).
            // A success word, if one is set, decides instead; with neither, a 2xx answer is only "sent".
            $jr = json_decode((string)$r['raw'], true); $hcode = null;
            if (is_array($jr)) { if (is_array($jr['result'] ?? null) && isset($jr['result']['resultCode'])) $hcode = (string)$jr['result']['resultCode']; elseif (isset($jr['resultCode']) && (string)$jr['resultCode'] !== '000') $hcode = (string)$jr['resultCode']; }
            $ok = (!$http2xx || $low) ? false : ($cfg['purchase_ok_match'] !== '' ? $matched : ($hcode !== null ? $hcode === '0' : null));
            $status = $low ? 'lowbal' : ($ok === true ? 'ok' : ($ok === null ? 'sent' : 'failed'));
            $own = '';
            if ($cfg['purchase_reply_field'] !== '' && ($j = json_decode($r['raw'], true)) && is_array($j)) { $v = $j[$cfg['purchase_reply_field']] ?? null; if (is_string($v)) $own = trim(mb_substr(preg_replace('/\s+/', ' ', $v), 0, 160)); }
            $text = $own !== '' ? $own : ($low ? $lowText : ($ok === true ? 'Thank you. '.$p['name'].' has been purchased.' : ($ok === null ? 'Your request for '.$p['name'].' has been sent.' : 'Sorry, the purchase could not be completed. Please try again later.')));
            // Hera answered but refused for its own reason (e.g. "Deduction for Subscription failed"): say what it said
            if ($status === 'failed' && $own === '' && $http2xx && ($hr = ussd_share_text(json_decode((string)$r['raw'], true), (string)$r['raw'])) !== '') $text = 'Sorry, the purchase could not be completed. '.rtrim($hr, '.').'.';
        }
        $db->prepare('UPDATE ussd_purchases SET status=?, http_code=?, response=?, reply_text=?, ms=?, request_body=? WHERE id=?')
            ->execute([$status, $httpCode, $resp, mb_substr($text, 0, 255), (int)round((microtime(true) - $t0) * 1000), $sent, $id]);
        return ['text' => $text, 'note' => 'purchase: '.$mode.' '.$status.($httpCode ? ' HTTP '.$httpCode : '')];
    } catch (Throwable $e) {
        error_log('ussd purchase: '.$e->getMessage());
        return ['text' => 'Sorry, we could not process this request. Please try again later.', 'note' => 'purchase error: '.substr($e->getMessage(), 0, 100)];
    }
}
