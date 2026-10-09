<?php
declare(strict_types=1);
// USSD proxy endpoint: settings, request handling, push through Mobius.

// ===================== USSD proxy endpoint =====================
// public/ussd.php answers Mobius PROXY / MS_INITIATED menus. It is OFF until an admin enables it on the USSD Proxy page
// and then starts in CAPTURE mode (records what Mobius sends, replies with a fixed text) so the real request format can
// be read from the log; in LIVE mode it maps the request's fields (configured there) onto the screen engine.
// What Hera's /hera/VasOffers expects, copied from the request Mobius's own menu sends it (operation purchaseOffer).
const USSD_PURCHASE_BODY = '{"callID":"{txn}","originalRequest":"{shortcode}","localAddress":{local_address_json},"remoteAddress":{remote_address_json},"msisdn":"{msisdn}","imsi":"{imsi}","localDialogID":{local_dialog_id},"remoteDialogID":{remote_dialog_id},"isMobileOriginated":true,"mobileRequestIdentifier":1,"isInitial":false,"isProxy":false,"chargesWithCurrency":"{price_d}","offerCode":"{offer_code}","vendor":"{vendor}","channel":"USSD","otherOfferCode":"{other_offer_code}","operation":"purchaseOffer"}';
const USSD_PURCHASE_BODY_V2 = '{"callID":"{txn}","originalRequest":"{shortcode}","msisdn":"{msisdn}","isMobileOriginated":true,"mobileRequestIdentifier":1,"isInitial":false,"isProxy":false,"chargesWithCurrency":"{price_d}","offerCode":"{offer_code}","vendor":"{vendor}","channel":"USSD","otherOfferCode":"{other_offer_code}","operation":"purchaseOffer"}';
const SHARE_CTX = '"callID":"{txn}","originalRequest":"{shortcode}","localAddress":{local_address_json},"remoteAddress":{remote_address_json},"msisdn":"{msisdn}","imsi":"{imsi}","localDialogID":{local_dialog_id},"remoteDialogID":{remote_dialog_id},"isMobileOriginated":true,"mobileRequestIdentifier":1,"isInitial":false,"isProxy":false';
const SHARE_BODY_READ = '{'.SHARE_CTX.',"channel":"USSD"}';
// listNumber (from Mobius's log) carries the vendor as well
const SHARE_BODY_NUMBERS = '{'.SHARE_CTX.',"vendor":"huawei","channel":"USSD"}';
// Both copied from Mobius's own Shared Bundle requests (its log): subscribe adds the price (D600), the offer code and operation purchaseOffer.
const SHARE_BODY_SUBSCRIBE_V2 = '{'.SHARE_CTX.',"vendor":"{vendor}","channel":"USSD","chargesWithCurrency":"{price_d}","offerCode":"{offer_code}"}';
const SHARE_BODY_SUBSCRIBE = '{'.SHARE_CTX.',"chargesWithCurrency":"{price_d}","offerCode":"{offer_code}","vendor":"huawei","channel":"USSD","operation":"purchaseOffer"}';
const SHARE_BODY_ADD = '{'.SHARE_CTX.',"vendor":"huawei","channel":"USSD","otherMsisdn":"{other_msisdn}"}';
// earlier defaults, replaced automatically when they were never edited
const SHARE_OLD_DEFAULTS = [
    SHARE_BODY_SUBSCRIBE_V2,
    '{"callID":"{txn}","msisdn":"{msisdn}","channel":"USSD"}',
    '{"callID":"{txn}","msisdn":"{msisdn}","offerCode":"{offer_code}","vendor":"{vendor}","chargesWithCurrency":"{price_d}","channel":"USSD"}',
    '{"callID":"{txn}","msisdn":"{msisdn}","otherMsisdn":"{other_msisdn}","channel":"USSD"}',
];
// Buying for another number (from Mobius's own log): the same purchaseOffer, the base offerCode and its otherOfferCode, plus otherMsisdn exactly as the customer typed it.
const USSD_PURCHASE_BODY_OTHER = '{"callID":"{txn}","originalRequest":"{shortcode}","localAddress":{local_address_json},"remoteAddress":{remote_address_json},"msisdn":"{msisdn}","imsi":"{imsi}","localDialogID":{local_dialog_id},"remoteDialogID":{remote_dialog_id},"isMobileOriginated":true,"mobileRequestIdentifier":1,"isInitial":false,"isProxy":false,"chargesWithCurrency":"{price_d}","offerCode":"{offer_code}","vendor":"{vendor}","channel":"USSD","otherMsisdn":"{other_msisdn}","otherOfferCode":"{other_offer_code}","operation":"purchaseOffer"}';
const USSD_PURCHASE_BODY_V1 = '{"msisdn":"{msisdn}","offer_code":"{offer_code}","transaction_id":"{txn}","channel":"USSD"}';
const USSD_PROXY_DEFAULTS = [
    'refund_mode' => 'off', 'refund_url' => 'https://vas-prod.comium.gm/hera/prepaid/BundleSubscription', 'refund_auth' => '',
    'enabled' => '0', 'token' => '', 'mode' => 'capture', 'allow_ips' => '',
    'shortcode_proxy' => '*9606*9090#', 'shortcode_ms_initiated' => '*9606*9090#',
    'f_msisdn' => '', 'f_session' => '', 'f_input' => '', 'f_shortcode' => '',
    'reply_mode' => 'step', 'session_ttl' => '180',
    'resp_type' => 'text/plain; charset=UTF-8', 'resp_body' => '{text}', 'end_true' => 'true', 'end_false' => 'false',
    'capture_body' => 'VAS Cloud test endpoint: request received.',
    // PROXY menus: Mobius sends each reply here and expects the screen to come back through ITS REST API
    'push_enabled' => '0', 'mobius_base' => 'http://192.168.162.20:28080/rest/', 'mobius_user' => '', 'mobius_pass' => '', 'mobius_session' => '', 'mobius_variant' => '0',
    // buying an offer from the menu (see ussd_execute_purchase): off | test | live
    'purchase_mode' => 'off', 'purchase_url' => '', 'purchase_body' => USSD_PURCHASE_BODY,
    'purchase_auth' => '', 'purchase_ok_match' => '', 'purchase_timeout' => '8', 'purchase_lowbal' => 'insufficient,low balance,not enough', 'purchase_reply_field' => '', 'purchase_body_other' => '', 'purchase_other_seeded' => '0',
    // Shared Bundle (Seddo)
    'share_mode' => 'test', 'share_base' => 'https://vas-testing.comium.gm/hera/prepaid/ShareBundle/', 'share_ok_code' => '0',
    'share_body_subscribe' => SHARE_BODY_SUBSCRIBE, 'share_body_validate' => '', 'share_body_add' => SHARE_BODY_ADD, 'share_body_balance' => SHARE_BODY_READ, 'share_body_numbers' => SHARE_BODY_NUMBERS,
];
function ussd_proxy_config(): array {
    $cfg = USSD_PROXY_DEFAULTS;
    try { foreach (portal_pdo()->query('SELECT name,value FROM ussd_proxy_config')->fetchAll() as $r) if (array_key_exists($r['name'], $cfg)) $cfg[$r['name']] = (string)$r['value']; }
    catch (Throwable $e) {}
    if (in_array($cfg['purchase_body'], [USSD_PURCHASE_BODY_V1, USSD_PURCHASE_BODY_V2], true)) $cfg['purchase_body'] = USSD_PURCHASE_BODY; // an earlier default, never edited
    // until the purchase card has been saved once, a blank "for another number" body means "never set": use the one copied from Mobius
    if ($cfg['purchase_other_seeded'] !== '1' && trim((string)$cfg['purchase_body_other']) === '') $cfg['purchase_body_other'] = USSD_PURCHASE_BODY_OTHER;
    foreach (['share_body_subscribe' => SHARE_BODY_SUBSCRIBE, 'share_body_add' => SHARE_BODY_ADD, 'share_body_balance' => SHARE_BODY_READ, 'share_body_numbers' => SHARE_BODY_NUMBERS] as $k => $new) if (in_array($cfg[$k], SHARE_OLD_DEFAULTS, true) || ($k === 'share_body_numbers' && $cfg[$k] === SHARE_BODY_READ)) $cfg[$k] = $new;
    return $cfg;
}
function ussd_proxy_set(array $vals): void {
    $st = portal_pdo()->prepare('INSERT INTO ussd_proxy_config(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
    foreach ($vals as $k => $v) $st->execute([$k, (string)$v]);
}
function ussd_proxy_valid_ips(string $list): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $x) {
        $ip = explode('/', $x)[0]; $bits = explode('/', $x)[1] ?? null;
        if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int)$bits > (str_contains($ip, ':') ? 128 : 32)))) throw new RuntimeException('"'.$x.'" is not a valid IP address or range (use e.g. 192.168.162.20 or 192.168.162.0/24).');
        $out[] = $x;
    }
    return $out;
}
function ussd_proxy_ip_allowed(string $list, string $ip): bool {
    $rules = ussd_proxy_valid_ips($list);
    if (!$rules) return true;
    foreach ($rules as $r) {
        [$net, $bits] = array_pad(explode('/', $r), 2, null);
        if ($bits === null) { if ($ip === $net) return true; continue; }
        $a = @inet_pton($ip); $n = @inet_pton($net); if ($a === false || $n === false || strlen($a) !== strlen($n)) continue;
        $full = intdiv((int)$bits, 8); $rem = (int)$bits % 8;
        if (substr($a, 0, $full) !== substr($n, 0, $full)) continue;
        if ($rem === 0 || ((ord($a[$full]) ^ ord($n[$full])) >> (8 - $rem)) === 0) return true;
    }
    return false;
}
function save_ussd_proxy_config(array $d): void {
    $field = function (string $k) use ($d): string {
        $v = trim((string)($d[$k] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.\-\[\]:]{0,80}$/', $v)) throw new RuntimeException('Field names may only contain letters, digits and _ . - [ ] :');
        return $v;
    };
    $code = function (string $k) use ($d): string {
        $v = trim((string)($d[$k] ?? ''));
        if ($v === '' || mb_strlen($v) > 80) throw new RuntimeException('Enter the short code to serve (up to 80 characters).');
        return $v;
    };
    $text = function (string $k, int $max, bool $required) use ($d): string {
        $v = (string)($d[$k] ?? '');
        if (mb_strlen($v) > $max || ($required && trim($v) === '')) throw new RuntimeException('"'.$k.'" must be '.($required ? 'filled in and ' : '').'at most '.$max.' characters.');
        return $v;
    };
    $ctype = trim((string)($d['resp_type'] ?? ''));
    if (!preg_match('#^[A-Za-z0-9.+\-]+/[A-Za-z0-9.+\-]+(;\s*charset=[A-Za-z0-9\-]+)?$#', $ctype)) throw new RuntimeException('Reply content type should look like text/plain; charset=UTF-8 or application/json.');
    $ips = trim((string)($d['allow_ips'] ?? '')); ussd_proxy_valid_ips($ips);
    $mode = ($d['mode'] ?? '') === 'live' ? 'live' : 'capture';
    $ttl = filter_var($d['session_ttl'] ?? null, FILTER_VALIDATE_INT); if ($ttl === false || $ttl < 30 || $ttl > 900) throw new RuntimeException('Session timeout must be between 30 and 900 seconds.');
    ussd_proxy_set([
        'enabled' => empty($d['enabled']) ? '0' : '1', 'mode' => $mode, 'allow_ips' => $ips,
        'shortcode_proxy' => $code('shortcode_proxy'), 'shortcode_ms_initiated' => $code('shortcode_ms_initiated'),
        'f_msisdn' => $field('f_msisdn'), 'f_session' => $field('f_session'), 'f_input' => $field('f_input'), 'f_shortcode' => $field('f_shortcode'),
        'reply_mode' => ($d['reply_mode'] ?? '') === 'cumulative' ? 'cumulative' : 'step', 'session_ttl' => (string)$ttl,
        'resp_type' => $ctype, 'resp_body' => $text('resp_body', 4000, true), 'end_true' => $text('end_true', 40, false), 'end_false' => $text('end_false', 40, false),
        'capture_body' => $text('capture_body', 2000, true),
    ]);
    audit('ussd_proxy_save', null, 'ussd_proxy_config', null, 'enabled='.(empty($d['enabled']) ? 0 : 1).' mode='.$mode);
}
function ussd_proxy_token(bool $create = true): string {
    $t = ussd_proxy_config()['token'];
    if ($t === '' && $create) { $t = bin2hex(random_bytes(20)); ussd_proxy_set(['token' => $t]); }
    return $t;
}
function ussd_proxy_rotate_token(): string {
    $t = bin2hex(random_bytes(20)); ussd_proxy_set(['token' => $t]);
    audit('ussd_proxy_token_rotated', null, 'ussd_proxy_config', null, 'rotated');
    return $t;
}
// Flattens whatever Mobius sent (query string, form fields, JSON or XML body) into name => value, nested names joined by '.'.
function ussd_proxy_flatten(string $contentType, string $raw, array $get, array $post): array {
    $flat = [];
    $put = function ($key, $v) use (&$flat, &$put) {
        if (is_array($v)) { foreach ($v as $k => $x) $put($key === '' ? (string)$k : $key.'.'.$k, $x); return; }
        if (is_bool($v)) $v = $v ? 'true' : 'false';
        $flat[(string)$key] = (string)$v;
    };
    foreach ($get as $k => $v) $put((string)$k, $v);
    foreach ($post as $k => $v) $put((string)$k, $v);
    $t = ltrim($raw);
    if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) { $j = json_decode($t, true); if (is_array($j)) $put('', $j); }
    elseif ($t !== '' && $t[0] === '<' && function_exists('simplexml_load_string')) {
        libxml_use_internal_errors(true);
        $x = simplexml_load_string($t, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if ($x !== false) foreach ($x->xpath('//*[not(*)]') ?: [] as $el) { $name = $el->getName(); $val = trim((string)$el); if ($val !== '' && !isset($flat[$name])) $flat[$name] = $val; }
    }
    elseif ($t !== '' && !$post && str_contains($t, '=') && !preg_match('/\s/', $t)) { parse_str($t, $q); $put('', $q); }
    return $flat;
}
function ussd_proxy_render(array $cfg, array $screen, string $session, string $msisdn): string {
    $json = fn($s) => substr((string)json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    return strtr($cfg['resp_body'], [
        '{text}' => $screen['text'], '{text_json}' => $json($screen['text']), '{text_xml}' => htmlspecialchars($screen['text'], ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        '{text_url}' => rawurlencode($screen['text']), '{end}' => $screen['end'] ? $cfg['end_true'] : $cfg['end_false'], '{end_int}' => $screen['end'] ? '1' : '0',
        '{con_end}' => $screen['end'] ? 'END' : 'CON', '{session}' => $session, '{msisdn}' => $msisdn,
    ]);
}
// $mode: 'proxy' | 'ms_initiated'. $dry: don't store session state. $forceLive: ignore capture mode (the admin page's tester).
function ussd_proxy_process(array $cfg, string $mode, array $flat, bool $dry = false, bool $forceLive = false): array {
    if ($cfg['mode'] !== 'live' && !$forceLive) return ['ctype' => 'text/plain; charset=UTF-8', 'body' => $cfg['capture_body'], 'note' => 'capture mode', 'screen' => null];
    $val = fn(string $f) => ($f !== '' && isset($flat[$f])) ? trim($flat[$f]) : '';
    $msisdn = $val($cfg['f_msisdn']); $sessionId = $val($cfg['f_session']); $input = $val($cfg['f_input']);
    $sc = $val($cfg['f_shortcode']) ?: $cfg['shortcode_'.$mode];
    $note = [];
    if ($cfg['f_input'] === '') $note[] = 'input field not set';
    if ($cfg['reply_mode'] === 'cumulative') {
        $s = trim($input, "#* "); $parts = $s === '' ? [] : explode('*', $s);
        $tok = array_values(array_filter(explode('*', trim($sc, '#*')), fn($x) => $x !== ''));
        if ($tok && array_slice($parts, 0, count($tok)) === $tok) $parts = array_slice($parts, count($tok));
        $replies = $parts; $key = null;
    } else {
        $key = substr($sessionId !== '' ? $sessionId : ($msisdn !== '' ? 'msisdn:'.$msisdn : ''), 0, 120);
        $replies = [];
        if ($key !== '') {
            $st = portal_pdo()->prepare('SELECT replies FROM ussd_proxy_sessions WHERE session_key=? AND shortcode=? AND updated_at >= NOW() - INTERVAL '.(int)$cfg['session_ttl'].' SECOND');
            $st->execute([$key, $sc]); $row = $st->fetchColumn();
            if ($row !== false) { $replies = json_decode((string)$row, true) ?: []; if ($input !== '') $replies[] = $input; }
        } else $note[] = 'no session or msisdn field set — every request starts again from the first screen';
    }
    $hk = ['seed' => $sessionId !== '' ? $sessionId : (string)$key, 'msisdn' => $msisdn]; $mkScreen = fn() => ussd_screen($sc, array_slice($replies, -30), ['active'], null, $hk);
    $screen = $mkScreen();
    for ($fi = 0; $fi < 5 && ($screen['kind'] ?? '') === 'flow_call' && !$dry; $fi++) { flow_execute_call(flow_get($screen['flow_key']), $screen['flow_pending'], $hk['seed'], $msisdn, [], $sc); $screen = $mkScreen(); }
    if (in_array($screen['kind'] ?? '', ['share_subscribe', 'share_add'], true)) {
        if ($dry) $note[] = 'not sent (test run)';
        else { $sr = ussd_share_execute($cfg, $screen, $msisdn, $sessionId !== '' ? $sessionId : (string)$key); $screen['text'] = $sr['text']; $note[] = $sr['note']; }
    }
    if (!$dry) ussd_quiz_record($screen, $msisdn, $sessionId !== '' ? $sessionId : (string)$key);
    if (($screen['kind'] ?? '') === 'purchase') {
        if ($dry) $note[] = 'purchase not sent (test run)';
        else { $pr = ussd_execute_purchase($cfg, $screen, $msisdn, $sessionId !== '' ? $sessionId : (string)$key); $screen['text'] = $pr['text']; $note[] = $pr['note']; }
    }
    if ($key !== null && $key !== '' && !$dry) {
        if ($screen['end']) portal_pdo()->prepare('DELETE FROM ussd_proxy_sessions WHERE session_key=?')->execute([$key]);
        else portal_pdo()->prepare('REPLACE INTO ussd_proxy_sessions(session_key,shortcode,replies,updated_at) VALUES(?,?,?,NOW())')->execute([$key, $sc, json_encode($replies)]);
    }
    return ['ctype' => $cfg['resp_type'], 'body' => ussd_proxy_render($cfg, $screen, $sessionId, $msisdn), 'note' => implode('; ', $note) ?: 'live', 'screen' => $screen, 'replies' => $replies, 'sc' => $sc];
}
function ussd_proxy_write_log(string $mode, string $ip, array $get, string $raw, string $response, int $ms, string $note): void {
    $hdr = [];
    foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_') && $k !== 'HTTP_COOKIE') $hdr[strtolower(str_replace('_', '-', substr($k, 5)))] = $k === 'HTTP_AUTHORIZATION' ? '(present)' : (string)$v;
    if (!empty($_SERVER['CONTENT_TYPE'])) $hdr['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
    try {
        $db = portal_pdo();
        $db->prepare('INSERT INTO ussd_proxy_log(mode,remote_ip,method,query_text,headers_text,body_text,response_text,ms,note) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$mode, $ip, $_SERVER['REQUEST_METHOD'] ?? '', json_encode($get, JSON_UNESCAPED_UNICODE), json_encode($hdr, JSON_UNESCAPED_UNICODE), $raw !== '' ? $raw : json_encode($_POST, JSON_UNESCAPED_UNICODE), mb_substr($response, 0, 1000), $ms, mb_substr($note, 0, 200)]);
        if (random_int(1, 30) === 1) { $db->exec('DELETE FROM ussd_proxy_log WHERE created_at < NOW() - INTERVAL 3 DAY'); $db->exec('DELETE FROM ussd_proxy_sessions WHERE updated_at < NOW() - INTERVAL 1 DAY'); }
    } catch (Throwable $e) {}
}
