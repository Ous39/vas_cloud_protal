<?php
declare(strict_types=1);
// Mobius REST API: login, pushing screens, and managing menus inside Mobius.

// ---- Mobius REST API (documented at images.mobius-software.com/apidocs: auth/login, ussdcalls/proxy) ----
function mobius_norm_base(string $u): string {
    $u = trim($u);
    if (!preg_match('#^https?://[A-Za-z0-9._-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $u)) throw new RuntimeException('"'.$u.'" is not a valid address (use e.g. http://192.168.162.20:28080/rest/).');
    $u = rtrim($u, '/'); if (!str_ends_with($u, '/rest')) $u .= '/rest';
    return $u.'/';
}
function mobius_bases(array $cfg): array {
    $out = []; foreach (preg_split('/[\s,;]+/', trim($cfg['mobius_base']), -1, PREG_SPLIT_NO_EMPTY) as $x) { try { $out[] = mobius_norm_base($x); } catch (Throwable $e) {} }
    return array_values(array_unique($out));
}
function mobius_handle(): CurlHandle {
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_COOKIEFILE => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    return $ch;
}
function mobius_http(string $url, array $body, int $timeout = 5, ?CurlHandle $ch = null, string $method = 'POST'): array {
    $own = $ch === null; if ($own) $ch = mobius_handle();
    curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    if ($method === 'GET') curl_setopt($ch, CURLOPT_HTTPGET, true); else { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($own) curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return ['code' => $code, 'raw' => (string)$raw, 'json' => is_array($j) ? $j : null, 'error' => $err];
}
function mobius_login_at(string $base, string $user, string $hash, ?CurlHandle $ch = null): array {
    $r = mobius_http($base.'auth/login', ['username' => $user, 'password' => $hash], 5, $ch);
    $sid = $r['json']['sessionID'] ?? null; $status = strtoupper((string)($r['json']['status'] ?? ''));
    if ($r['code'] === 200 && is_string($sid) && $sid !== '' && $status !== 'ERROR') return ['ok' => true, 'session' => $sid];
    return ['ok' => false, 'error' => $r['error'] !== '' ? $r['error'] : 'HTTP '.$r['code'].' '.mb_substr((string)($r['json']['errorMessage'] ?? $r['raw']), 0, 120)];
}
// The login session is cached (encrypted) per Mobius node for 25 minutes and re-created on any failure.
function mobius_session_for(array $cfg, string $base, bool $force = false): array {
    $map = json_decode(decrypt_secret($cfg['mobius_session']) ?: '{}', true) ?: [];
    if (!$force && !empty($map[$base]['sid']) && time() - (int)($map[$base]['at'] ?? 0) < 1500) return ['ok' => true, 'session' => $map[$base]['sid']];
    $hash = decrypt_secret($cfg['mobius_pass']);
    if ($cfg['mobius_user'] === '' || $hash === '') return ['ok' => false, 'error' => 'Mobius login not configured'];
    $r = mobius_login_at($base, $cfg['mobius_user'], $hash);
    if ($r['ok']) { $map[$base] = ['sid' => $r['session'], 'at' => time()]; try { ussd_proxy_set(['mobius_session' => encrypt_secret(json_encode($map))]); } catch (Throwable $e) {} }
    return $r;
}
// Mobius answers HTTP 200 with status ERROR for a refused call. These are the ways the session might have to be presented
// (the first that works is remembered in mobius_variant): 0 = sessionID + username in the body as documented,
// 1 = the same, but on the connection that logged in (so its cookie travels too). Mobius rejects a "password" field outright.
const MOBIUS_VARIANTS = ['sessionID + username', 'login cookie'];
function mobius_is_auth_error(array $r): bool {
    $m = strtolower((string)($r['json']['errorMessage'] ?? '')); $st = strtoupper((string)($r['json']['status'] ?? ''));
    return $r['code'] === 401 || $r['code'] === 403 || ($st === 'ERROR' && (str_contains($m, 'auth') || str_contains($m, 'session') || str_contains($m, 'login') || str_contains($m, 'credential')));
}
function mobius_call_variant(array $cfg, string $base, string $path, array $data, int $variant, bool $freshLogin): array {
    $hash = decrypt_secret($cfg['mobius_pass']); $cookie = $variant === 1;
    $ch = null; $sid = null;
    if ($cookie) {
        $ch = mobius_handle(); $l = mobius_login_at($base, $cfg['mobius_user'], $hash, $ch);
        if (!$l['ok']) return ['code' => 0, 'raw' => '', 'json' => null, 'error' => 'login: '.$l['error']];
        $sid = $l['session'];
    } else {
        $s = mobius_session_for($cfg, $base, $freshLogin);
        if (!$s['ok']) return ['code' => 0, 'raw' => '', 'json' => null, 'error' => 'login: '.$s['error']];
        $sid = $s['session'];
    }
    $r = mobius_http($base.$path, array_merge($data, ['sessionID' => $sid, 'username' => $cfg['mobius_user']]), 5, $ch);
    if ($ch) curl_close($ch);
    return $r;
}
function mobius_push(array $cfg, string $callId, string $text, bool $complete): array {
    $errors = []; $data = ['data' => ['callID' => $callId, 'isComplete' => $complete, 'request' => $text]];
    $pref = max(0, min(1, (int)$cfg['mobius_variant'])); $order = array_values(array_unique([$pref, 0, 1]));
    foreach (mobius_bases($cfg) as $base) {
        foreach ($order as $n => $variant) {
            $r = mobius_call_variant($cfg, $base, 'ussdcalls/proxy', $data, $variant, $n > 0);
            $bad = $r['json'] !== null && (strtoupper((string)($r['json']['status'] ?? '')) === 'ERROR' || trim((string)($r['json']['errorMessage'] ?? '')) !== '');
            if ($r['code'] === 200 && !$bad) {
                if ($variant !== $pref) { try { ussd_proxy_set(['mobius_variant' => (string)$variant]); } catch (Throwable $e) {} }
                return ['ok' => true, 'base' => $base, 'variant' => MOBIUS_VARIANTS[$variant]];
            }
            $msg = $r['error'] !== '' ? $r['error'] : mb_substr((string)($r['json']['errorMessage'] ?? $r['raw']), 0, 100);
            $errors[] = $base.' ['.MOBIUS_VARIANTS[$variant].'] HTTP '.$r['code'].' '.$msg;
            if (!mobius_is_auth_error($r)) break; // not an authentication problem: other variants won't help
        }
    }
    return ['ok' => false, 'error' => $errors ? implode(' | ', array_slice($errors, -4)) : 'no Mobius address configured'];
}
function mobius_brief(array $r): string {
    if ($r['error'] !== '') return $r['error'];
    if (!$r['json']) return 'HTTP '.$r['code'].' '.mb_substr(trim(preg_replace('/\s+/', ' ', $r['raw'])), 0, 160);
    $j = $r['json']; if (isset($j['sessionID'])) $j['sessionID'] = '…'.substr((string)$j['sessionID'], -4);
    return 'HTTP '.$r['code'].' '.mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 220);
}
function mobius_probe_ok(array $r): bool {
    return $r['code'] === 200 && !mobius_is_auth_error($r) && strtoupper((string)($r['json']['status'] ?? 'SUCCESS')) !== 'ERROR';
}
// ---- Menus inside Mobius (REST ussdmenues/*, documented in Mobius' own API docs) ----
// A menu there is {menuID, name, shortcode, shortcodeType: PROXY|MS_INITIATED|NETWORK_INITIATED, isExtendable, remoteURL, destinationID[]}.
// Mobius' list holds every live menu of the operator (airtime recharge, data usage, …), so the portal only ever CHANGES a menu whose address is
// this portal's own endpoint (it carries our token). Everything else is shown read-only. Nothing is ever deleted from here.
// One REST call, trying the nodes in order and the ways of presenting the session. A node that answered (even with a refusal) is not followed
// by the next one, so a refused write is never repeated.
function mobius_rest(array $cfg, string $path, array $data = []): array {
    $errors = []; $pref = max(0, min(1, (int)$cfg['mobius_variant'])); $order = array_values(array_unique([$pref, 0, 1]));
    foreach (mobius_bases($cfg) as $base) {
        $r = ['code' => 0, 'json' => null, 'raw' => '', 'error' => ''];
        foreach ($order as $n => $variant) {
            $r = mobius_call_variant($cfg, $base, $path, $data, $variant, $n > 0);
            $bad = $r['json'] !== null && (strtoupper((string)($r['json']['status'] ?? '')) === 'ERROR' || trim((string)($r['json']['errorMessage'] ?? '')) !== '');
            if ($r['code'] === 200 && !$bad) { if ($variant !== $pref) { try { ussd_proxy_set(['mobius_variant' => (string)$variant]); } catch (Throwable $e) {} } return ['ok' => true, 'json' => (array)$r['json'], 'raw' => (string)$r['raw'], 'base' => $base]; }
            $errors[] = $base.' HTTP '.$r['code'].' '.($r['error'] !== '' ? $r['error'] : mb_substr(html_entity_decode((string)($r['json']['errorMessage'] ?? $r['raw']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 160));
            if (!mobius_is_auth_error($r)) break;
        }
        if ($r['code'] > 0) break; // this node answered: do not ask the other one to do the same
    }
    return ['ok' => false, 'error' => $errors ? implode(' | ', array_slice($errors, -3)) : 'no Mobius address configured'];
}
// ---- Reading Mobius' menu list, whatever shape the answer has ----
// The documented shape is {status, data: [ {menuID, name, shortcode, …}, … ]}, but a Mobius install has been seen to answer the count (270) while
// the list came back as "0 menus": the rows were somewhere else in the answer or carried other field names. So the rows are looked for anywhere in
// the answer, each row's fields are matched by their usual names, and the row exactly as Mobius gave it is kept in _raw (that is what is sent back
// when a menu is changed, with only the changed fields replaced, under the names Mobius itself used).
const MOBIUS_MENU_FIELDS = [
    'menuID' => ['menuID', 'menuId', 'menuid', 'id', 'ID', 'Id', '_id', 'menu_id'],
    'name' => ['name', 'menuName', 'menu_name', 'title'],
    'shortcode' => ['shortcode', 'shortCode', 'short_code', 'ussdCode', 'ussd_code', 'code'],
    'shortcodeType' => ['shortcodeType', 'shortCodeType', 'shortcode_type', 'type', 'menuType'],
    'isExtendable' => ['isExtendable', 'extendable', 'isExtandable', 'is_extendable'],
    'remoteURL' => ['remoteURL', 'remoteUrl', 'remote_url', 'url', 'address', 'remoteAddress'],
    'destinationID' => ['destinationID', 'destinationIDs', 'destinationId', 'destinationIds', 'applicationDestinationID', 'applicationDestinationId', 'destinations', 'destination_id'],
];
const MOBIUS_MENU_TYPES = ['PROXY', 'MS_INITIATED', 'NETWORK_INITIATED'];
function mobius_field_key(array $raw, string $canon): ?string { foreach (MOBIUS_MENU_FIELDS[$canon] as $k) if (array_key_exists($k, $raw)) return $k; return null; }
function mobius_menu_looks_like(array $row): bool { return mobius_field_key($row, 'shortcode') !== null || mobius_field_key($row, 'remoteURL') !== null; }
// One menu with the portal's field names (and the original row in _raw).
// An id as text. Mobius keeps its ids as MongoDB object ids: {"_id": {"$oid": "649f1d85d0f23c31adf8b9e6"}}.
function mobius_id_text($v): string {
    if (is_array($v)) { foreach (['$oid', 'oid', 'id', '_id', 'destinationID'] as $k) if (isset($v[$k]) && !is_array($v[$k])) return (string)$v[$k]; return $v === [] ? '' : json_encode($v); }
    return $v === null ? '' : trim((string)$v);
}
function mobius_is_oid($v): bool { return is_array($v) && array_key_exists('$oid', $v); }
// One menu with the portal's field names (and the original row in _raw).
function mobius_menu_norm(array $raw): array {
    if (isset($raw['_raw']) && is_array($raw['_raw'])) return $raw; // already done
    $g = function (string $c, $def = null) use ($raw) { $k = mobius_field_key($raw, $c); return $k === null ? $def : $raw[$k]; };
    $dest = $g('destinationID', []);
    if ($dest === null || $dest === '') $dest = []; elseif (!is_array($dest) || !array_is_list($dest)) $dest = [$dest]; // one id, or {"$oid": …}
    $dest = array_values(array_filter(array_map('mobius_id_text', $dest), fn($d) => $d !== ''));
    $ext = $g('isExtendable', false); $ext = is_bool($ext) ? $ext : in_array(strtolower(trim((string)$ext)), ['1', 'true', 'yes', 'y'], true);
    return ['menuID' => mobius_id_text($g('menuID', '')), 'name' => (string)($g('name', '') ?? ''), 'shortcode' => trim((string)($g('shortcode', '') ?? '')), 'shortcodeType' => strtoupper((string)($g('shortcodeType', '') ?? '')),
        'isExtendable' => $ext, 'remoteURL' => (string)($g('remoteURL', '') ?? ''), 'destinationID' => $dest, '_raw' => $raw];
}
// Back to what Mobius sent, with the portal's values put under Mobius' own field names. A field the menu did not have is not added: Mobius refuses
// a field it does not know (HTTP 400 "Unrecognized field").
function mobius_menu_denorm(array $m, array $refs = []): array {
    $raw = (array)($m['_raw'] ?? []); $out = $raw;
    foreach (array_keys(MOBIUS_MENU_FIELDS) as $c) {
        if (!array_key_exists($c, $m)) continue; if ($c === 'menuID' && (string)$m[$c] === '') continue;
        $k = mobius_field_key($raw, $c); if ($k === null) { if ($raw !== [] && !in_array($c, ['name', 'shortcode'], true)) continue; $k = $c; }
        $ref = $raw[$k] ?? null; if ($ref === null) $ref = $refs[$c] ?? null; // a field that is empty here: take its form from the other menus
        $out[$k] = ($m[$c] === '' || $m[$c] === []) && array_key_exists($k, $raw) && $raw[$k] === null ? null : mobius_like($m[$c], $ref, $c); // empty stays null, as Mobius had it
    }
    return $out;
}
// The field names this Mobius uses, learnt from the menus it lists: [portal name => its name, or null when its menus do not have that field].
function mobius_menu_keys(array $menus): array {
    $out = array_fill_keys(array_keys(MOBIUS_MENU_FIELDS), null); $refs = []; $seen = false;
    foreach ($menus as $m) {
        $raw = (array)(mobius_menu_norm($m)['_raw'] ?? []); if (!$raw) continue; $seen = true;
        foreach ($out as $c => $k) { $f = mobius_field_key($raw, $c); if ($f === null) continue; $out[$c] ??= $f; if (!isset($refs[$c]) && $raw[$f] !== null) $refs[$c] = $raw[$f]; }
    }
    if (!$seen) foreach ($out as $c => $_) $out[$c] = $c; // nothing listed to learn from: the documented names
    return ['keys' => $out, 'refs' => $refs];
}
// A value in the same form as Mobius holds it in its other menus (a list as one text, true/false as text, …).
function mobius_like($v, $ref, string $field = '') {
    if (mobius_is_oid($ref)) { $one = is_array($v) ? ($v[0] ?? '') : (string)$v; return trim((string)$one) === '' ? null : ['$oid' => trim((string)$one)]; } // {"$oid": …}
    if (is_array($v) && is_array($ref) && array_is_list($ref) && $ref && mobius_is_oid($ref[0])) return array_map(fn($x) => ['$oid' => (string)$x], $v);
    if ($v === '' && $ref === null) return null; // empty stays empty (null), as Mobius gave it
    if (is_array($v) && is_string($ref)) return implode(',', $v);
    if (is_array($v) && $ref === null && $field === 'destinationID') return $v === [] ? null : (count($v) === 1 ? $v[0] : $v);
    if (is_bool($v) && is_string($ref)) return $v ? 'true' : 'false';
    if (is_bool($v) && is_int($ref)) return $v ? 1 : 0;
    return $v;
}
// A new menu in the shape this Mobius uses: only the fields its menus have (name and short code always), under its names.
function mobius_new_menu_data(array $new, array $menus): array {
    ['keys' => $keys, 'refs' => $refs] = mobius_menu_keys($menus); $data = [];
    foreach ($new as $c => $v) {
        if ($c === '_raw' || $c === 'menuID' || !array_key_exists($c, $keys)) continue;
        $k = $keys[$c]; if ($k === null) { if (!in_array($c, ['name', 'shortcode'], true)) continue; $k = $c; }
        $data[$k] = mobius_like($v, $refs[$c] ?? null, $c);
    }
    return $data;
}
// The list of menu rows anywhere in an answer: data, data.list, data.items, a map keyed by id, … (null when there is none).
function mobius_find_rows($j, int $depth = 0): ?array {
    if (!is_array($j) || $depth > 4) return null;
    if (array_is_list($j)) {
        if ($j === []) return [];
        $objs = array_values(array_filter($j, fn($r) => is_array($r) && !array_is_list($r)));
        return $objs && count($objs) === count($j) ? $objs : null;
    }
    if (mobius_menu_looks_like($j) && $depth > 0) return null; // a single menu, not a list
    $assoc = array_filter($j, fn($v) => is_array($v) && !array_is_list($v) && $v !== []);
    if ($assoc && count($assoc) === count($j) && count(array_filter($assoc, 'mobius_menu_looks_like')) === count($assoc)) { // {"<id>": {menu}, …}
        $rows = []; foreach ($assoc as $k => $v) { if (mobius_field_key($v, 'menuID') === null) $v['menuID'] = (string)$k; $rows[] = $v; } return $rows;
    }
    $found = null;
    foreach (['data', 'list', 'items', 'rows', 'menus', 'ussdMenus', 'result', 'results', 'content', 'records', 'entities', 'values', 'page'] as $k) {
        if (!array_key_exists($k, $j)) continue; $r = mobius_find_rows($j[$k], $depth + 1); if ($r) return $r; if ($r === [] && $found === null) $found = [];
    }
    foreach ($j as $v) { if (!is_array($v)) continue; $r = mobius_find_rows($v, $depth + 1); if ($r) return $r; if ($r === [] && $found === null) $found = []; }
    return $found;
}
function mobius_find_count($j): ?int {
    if (is_int($j) || (is_string($j) && ctype_digit($j))) return (int)$j;
    if (!is_array($j)) return null;
    foreach (['data', 'count', 'total', 'totalCount', 'size', 'result', 'value'] as $k) if (array_key_exists($k, $j)) { $n = mobius_find_count($j[$k]); if ($n !== null) return $n; }
    return null;
}
// What Mobius answered, safe to show (session ids and anything that looks like a password or token cut).
function mobius_redact(string $raw, int $max = 3000): string {
    $raw = preg_replace('/("(?:sessionID|sessionId|password|pass|token|secret)"\s*:\s*")[^"]*(")/i', '$1…$2', $raw);
    $raw = preg_replace('~([?&](?:t|token)=)[^&"\s]+~', '$1…', (string)$raw);
    return mb_strimwidth((string)$raw, 0, $max, ' …(cut)');
}
// The ways the list may have to be asked for. The first that gives rows is used; each tried way is kept in 'diag' for the page to show.
const MOBIUS_LIST_SHAPES = ['paging fields in the body', 'paging fields inside data', 'no paging fields'];
function mobius_list_request(int $shape, int $size, ?string $first): array {
    $p = ['pageSize' => $size, 'includePreviousKey' => false, 'reverse' => false] + ($first !== null ? ['firstKey' => $first] : []);
    return match ($shape) { 0 => $p, 1 => ['data' => $p], default => [] };
}
// Every menu in Mobius: ['ok' => true, 'menus' => […], 'count' => N|null, 'shape' => …, 'diag' => […]] | ['ok' => false, 'error' => …].
function mobius_list_menus(array $cfg): array {
    if ($cfg['mobius_user'] === '' || decrypt_secret($cfg['mobius_pass']) === '') return ['ok' => false, 'error' => 'The Mobius API user and password are not saved yet (Proxy → Connection).'];
    $c = mobius_rest($cfg, 'ussdmenues/count', []); $count = $c['ok'] ? mobius_find_count($c['json']) : null;
    $diag = [['call' => 'ussdmenues/count', 'answer' => $c['ok'] ? mobius_redact((string)($c['raw'] ?? json_encode($c['json'])), 400) : 'refused: '.$c['error']]];
    $best = null; $firstError = null;
    foreach (array_keys(MOBIUS_LIST_SHAPES) as $shape) {
        $all = []; $first = null; $size = 100; $sample = null; $err = null;
        for ($page = 0; $page < 60; $page++) {
            $r = mobius_rest($cfg, 'ussdmenues/list', mobius_list_request($shape, $size, $first));
            if (!$r['ok']) { $err = $r['error']; break; }
            if ($sample === null) $sample = mobius_redact((string)($r['raw'] ?? json_encode($r['json'])));
            $rows = mobius_find_rows($r['json']) ?? []; $new = 0; $last = null;
            foreach ($rows as $row) {
                $m = mobius_menu_norm($row); $key = $m['menuID'] !== '' ? 'id:'.$m['menuID'] : 'code:'.$m['shortcode'].'|'.$m['name'];
                if (!isset($all[$key])) { $all[$key] = $m; $new++; } $last = $m;
            }
            if ($shape === 2 || $new === 0 || !$last || $last['menuID'] === '') break;
            if ($count !== null ? count($all) >= $count : count($rows) < $size) break;
            $first = $last['menuID'];
        }
        $diag[] = ['call' => 'ussdmenues/list ('.MOBIUS_LIST_SHAPES[$shape].')', 'answer' => $err !== null && $sample === null ? 'refused: '.$err : (string)$sample, 'rows' => count($all)];
        if ($err !== null && $sample === null) { $firstError ??= $err; continue; }
        if ($best === null || count($all) > count($best['menus'])) $best = ['menus' => array_values($all), 'shape' => MOBIUS_LIST_SHAPES[$shape]];
        if ($all && ($count === null || count($all) >= $count)) break; // complete: no need to try other ways
        if ($count === 0) break;
    }
    if ($best === null) return ['ok' => false, 'error' => (string)$firstError, 'diag' => $diag];
    return ['ok' => true, 'menus' => $best['menus'], 'count' => $count, 'shape' => $best['shape'], 'diag' => $diag];
}
function mobius_menu_is_ours(array $m, array $cfg): bool {
    $u = (string)($m['remoteURL'] ?? ''); return $cfg['token'] !== '' && str_contains($u, 'ussd.php') && str_contains($u, 't='.$cfg['token']);
}
// How a portal short code stands in Mobius: ['state' => missing|ours|elsewhere, 'menu' => row|null]. "elsewhere" = a menu with that short code exists but
// does not call this portal (so the portal will not touch it).
function mobius_menu_state(string $code, array $menus, array $cfg): array {
    $bare = rtrim($code, '#'); // Mobius holds some codes without the # (*16, *9990*): *16 and *16# are the same dial
    foreach ($menus as $m) if (rtrim(trim((string)($m['shortcode'] ?? '')), '#') === $bare) return ['state' => mobius_menu_is_ours($m, $cfg) ? 'ours' : 'elsewhere', 'menu' => $m];
    return ['state' => 'missing', 'menu' => null];
}
// The menu to copy the address and destinations from: one of ours (the one for the main short code first).
function mobius_template_menu(array $menus, array $cfg): ?array {
    $ours = array_values(array_filter($menus, fn($m) => mobius_menu_is_ours($m, $cfg) && ($m['shortcodeType'] ?? '') === 'PROXY' && trim((string)($m['remoteURL'] ?? '')) !== ''));
    foreach ($ours as $m) if (trim((string)$m['shortcode']) === $cfg['shortcode_proxy']) return $m;
    return $ours[0] ?? null;
}
// Create a PROXY menu for a short code in Mobius, calling this portal. Refuses a short code Mobius already has.
function mobius_create_menu(array $cfg, string $name, string $code, bool $extendable = false): array {
    $code = menu_normalize_shortcode($code); $name = trim($name);
    if (!menu_valid_shortcode($code)) throw new RuntimeException('A short code looks like *9606*7070#.'); if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Give the menu a name.');
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    if (mobius_menu_state($code, $l['menus'], $cfg)['state'] !== 'missing') throw new RuntimeException($code.' already exists in Mobius — nothing was created.');
    $tpl = mobius_template_menu($l['menus'], $cfg); if (!$tpl) throw new RuntimeException('No menu in Mobius calls this portal yet, so there is no address to copy. Create the first one by hand (Proxy → Connection shows the address), then the portal can create the rest.');
    $data = mobius_new_menu_data(['name' => $name, 'shortcode' => $code, 'shortcodeType' => 'PROXY', 'isExtendable' => $extendable, 'remoteURL' => (string)$tpl['remoteURL'], 'destinationID' => array_values((array)($tpl['destinationID'] ?? []))], $l['menus']);
    $r = mobius_rest($cfg, 'ussdmenues/set', ['data' => $data]); if (!$r['ok']) throw new RuntimeException('Mobius refused: '.$r['error']);
    audit('mobius_create_menu', null, 'mobius', $code, json_encode(['name' => $name, 'extendable' => $extendable]));
    $after = mobius_list_menus($cfg); $st = $after['ok'] ? mobius_menu_state($code, $after['menus'], $cfg) : ['state' => 'unknown', 'menu' => null];
    return ['state' => $st['state'], 'menu' => $st['menu']];
}
// Change the name, short code and "extendable" of a menu of ours in Mobius. Anything else about the menu is sent back as it was.
function mobius_update_menu(array $cfg, string $menuId, string $name, string $code, bool $extendable): array {
    $code = menu_normalize_shortcode($code); $name = trim($name);
    if (!menu_valid_shortcode($code)) throw new RuntimeException('A short code looks like *9606*7070#.'); if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Give the menu a name.');
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    $cur = null; foreach ($l['menus'] as $m) if ((string)($m['menuID'] ?? '') === $menuId) $cur = $m;
    if (!$cur) throw new RuntimeException('That menu is no longer in Mobius.');
    if (!mobius_menu_is_ours($cur, $cfg)) throw new RuntimeException('That Mobius menu does not call this portal, so it is not changed from here.');
    foreach ($l['menus'] as $m) if ((string)($m['menuID'] ?? '') !== $menuId && rtrim(trim((string)($m['shortcode'] ?? '')), '#') === rtrim($code, '#')) throw new RuntimeException($code.' is already used by another menu in Mobius.');
    $data = mobius_menu_denorm(array_merge($cur, ['name' => $name, 'shortcode' => $code, 'isExtendable' => $extendable]), mobius_menu_keys($l['menus'])['refs']);
    $r = mobius_rest($cfg, 'ussdmenues/set', ['data' => $data]); if (!$r['ok']) throw new RuntimeException('Mobius refused: '.$r['error']);
    audit('mobius_update_menu', null, 'mobius', $menuId, json_encode(['from' => $cur['shortcode'] ?? '', 'to' => $code, 'name' => $name, 'extendable' => $extendable]));
    $after = mobius_list_menus($cfg); $st = $after['ok'] ? mobius_menu_state($code, $after['menus'], $cfg) : ['state' => 'unknown', 'menu' => null];
    try { mobius_record_change($menuId, $code, 'rename', $cur, $st['menu'] ?? $data, mobius_redact((string)($r['raw'] ?? ''), 1000)); } catch (Throwable $e) { error_log('mobius history: '.$e->getMessage()); }
    return ['state' => $st['state'], 'menu' => $st['menu']];
}
// ---- Managing every menu in Mobius (the Mobius Menus page) ----
// Any menu can be created or changed from the portal, not only the ones that call it, with these guards:
//  * only users with the settings permission (manage_api_keys) — checked by the page;
//  * a menu that does NOT call this portal (airtime, data usage, …) is only changed when the user types its short code to confirm;
//  * every change keeps the menu as it was before and after (mobius_menu_changes), so it can be put back with one click (Restore);
//  * Delete always needs the short code (or name) typed, and the deleted menu is kept so Put back creates it again.
function mobius_menu_tables(): void {
    static $done = false; if ($done) return;
    portal_pdo()->exec("CREATE TABLE IF NOT EXISTS mobius_menu_changes (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, menu_id VARCHAR(120) NULL, shortcode VARCHAR(60) NULL, action VARCHAR(20) NOT NULL,
        before_json MEDIUMTEXT NULL, after_json MEDIUMTEXT NULL, answer TEXT NULL, username VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_menu (menu_id, created_at), INDEX idx_code (shortcode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
function mobius_bare_code(string $c): string { return rtrim(preg_replace('/\s+/', '', $c), '#'); }
// Mobius holds codes with and without the closing # (*16, *9990*, *9606*7070#): any * / # / digits, starting with * or #.
function mobius_valid_code(string $c): bool { return (bool)preg_match('/^[*#][0-9*#]{1,39}$/', $c) && preg_match('/\d/', $c); }
function mobius_find_menu(array $menus, string $id): ?array { foreach ($menus as $m) if ((string)($m['menuID'] ?? '') === $id) return mobius_menu_norm($m); return null; }
function mobius_menu_belongs(array $m, array $cfg): string { return mobius_menu_is_ours($m, $cfg) ? 'portal' : 'other'; }
// Checks the form fields and gives the menu fields: name, shortcode, shortcodeType, isExtendable, remoteURL, destinationID.
function mobius_menu_input(array $in, ?array $cur = null): array {
    $name = trim((string)($in['name'] ?? '')); if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Give the menu a name (up to 150 letters).');
    $code = preg_replace('/\s+/', '', (string)($in['shortcode'] ?? ''));
    $type = strtoupper(trim((string)($in['shortcodeType'] ?? ($cur['shortcodeType'] ?? 'PROXY'))));
    if (!mobius_valid_code($code) && !($code === '' && $type !== 'PROXY')) throw new RuntimeException('A short code is * or #, then digits and * (for example *9606*7070# or *16). Only a NETWORK_INITIATED or MS_INITIATED menu may have none.');
    if (!in_array($type, MOBIUS_MENU_TYPES, true) && $type !== ($cur['shortcodeType'] ?? null)) throw new RuntimeException('The type is PROXY, MS_INITIATED or NETWORK_INITIATED.');
    $url = trim((string)($in['remoteURL'] ?? ''));
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url))) throw new RuntimeException('The address must be a full http:// or https:// address.');
    if ($type === 'PROXY' && $url === '') throw new RuntimeException('A PROXY menu needs the address Mobius should call.');
    $dest = is_array($in['destinationID'] ?? null) ? $in['destinationID'] : preg_split('/[\s,;]+/', (string)($in['destinationID'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $dest = array_values(array_unique(array_map(fn($d) => trim((string)$d), $dest)));
    foreach ($dest as $d) if (!preg_match('/^[A-Za-z0-9._:@-]{1,100}$/', $d)) throw new RuntimeException('"'.$d.'" is not a valid destination id.');
    if (count($dest) > 30) throw new RuntimeException('At most 30 destinations.');
    return ['name' => $name, 'shortcode' => $code, 'shortcodeType' => $type, 'isExtendable' => !empty($in['isExtendable']), 'remoteURL' => $url, 'destinationID' => $dest];
}
function mobius_menu_public(array $m): array { $m = mobius_menu_norm($m); unset($m['_raw']); return $m; }
function mobius_record_change(?string $id, string $code, string $action, ?array $before, ?array $after, string $answer): int {
    mobius_menu_tables(); $u = function_exists('user') ? (user()['username'] ?? null) : null;
    $st = portal_pdo()->prepare('INSERT INTO mobius_menu_changes(menu_id, shortcode, action, before_json, after_json, answer, username) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$id, $code, $action, $before === null ? null : json_encode($before['_raw'] ?? $before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $after === null ? null : json_encode($after['_raw'] ?? $after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), mb_substr($answer, 0, 2000), $u]);
    return (int)portal_pdo()->lastInsertId();
}
// Create ($menuId null) or change any menu. $confirm must be the short code when the menu does not (or will not) call this portal.
// Returns ['menu' => the menu read back from Mobius (or null), 'change' => id of the History row].
function mobius_save_menu(array $cfg, ?string $menuId, array $in, string $confirm = '', string $action = ''): array {
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    $cur = null; if ($menuId !== null && $menuId !== '') { $cur = mobius_find_menu($l['menus'], $menuId); if (!$cur) throw new RuntimeException('That menu is no longer in Mobius (refresh the list).'); }
    $new = mobius_menu_input($in, $cur);
    foreach ($l['menus'] as $m) if ($new['shortcode'] !== '' && (string)$m['menuID'] !== (string)($cur['menuID'] ?? "\0") && mobius_bare_code((string)$m['shortcode']) === mobius_bare_code($new['shortcode'])) throw new RuntimeException($new['shortcode'].' is already used in Mobius by “'.$m['name'].'”. Nothing was changed.');
    $touchesOther = ($cur && !mobius_menu_is_ours($cur, $cfg)) || !mobius_menu_is_ours($new, $cfg);
    if ($touchesOther) {
        $need = $cur && trim((string)$cur['shortcode']) !== '' ? (string)$cur['shortcode'] : $new['shortcode']; // a menu without a code (NETWORK_INITIATED): the new one
        if ($need === '') { // no short code at all: its name is typed instead
            if (trim($confirm) !== trim((string)($cur['name'] ?? $new['name']))) throw new RuntimeException('This menu has no short code: type its name “'.($cur['name'] ?? $new['name']).'” in the confirmation box to go ahead. Nothing was changed.');
        } elseif (mobius_bare_code(trim($confirm)) !== mobius_bare_code($need)) throw new RuntimeException('This menu '.($cur ? 'belongs to another service' : 'will not call this portal').': type its short code '.$need.' in the confirmation box to go ahead. Nothing was changed.');
    }
    if ($cur) {
        $same = true; foreach ($new as $k => $v) if ($v != $cur[$k]) $same = false;
        if ($same) throw new RuntimeException('Nothing was changed: the values are the same as in Mobius.');
    }
    $data = $cur ? mobius_menu_denorm(array_merge($cur, $new), mobius_menu_keys($l['menus'])['refs']) : mobius_new_menu_data($new, $l['menus']);
    $r = mobius_rest($cfg, 'ussdmenues/set', ['data' => $data]);
    if (!$r['ok']) { mobius_record_change($menuId, $new['shortcode'], ($action ?: ($cur ? 'change' : 'create')).'-refused', $cur, $data, $r['error']); throw new RuntimeException('Mobius refused: '.$r['error']); }
    $after = mobius_list_menus($cfg); $menu = null;
    if ($after['ok']) { $menu = $cur ? mobius_find_menu($after['menus'], (string)$cur['menuID']) : null; if (!$menu) foreach ($after['menus'] as $m) if (mobius_bare_code((string)$m['shortcode']) === mobius_bare_code($new['shortcode'])) $menu = $m; }
    $act = $action ?: ($cur ? 'change' : 'create');
    $id = mobius_record_change($menu['menuID'] ?? $menuId, $new['shortcode'], $act, $cur, $menu ?? $data, mobius_redact((string)($r['raw'] ?? ''), 1000));
    audit('mobius_menu_'.$act, null, 'mobius', $new['shortcode'], json_encode(['menu' => $menu['menuID'] ?? $menuId, 'from' => $cur ? mobius_menu_public($cur) : null, 'to' => $new], JSON_UNESCAPED_SLASHES));
    return ['menu' => $menu, 'change' => $id];
}
// Point a menu at this portal: PROXY, the portal's address and the destinations of the menu that already calls the portal.
function mobius_point_to_portal(array $cfg, string $menuId, string $confirm): array {
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    $cur = mobius_find_menu($l['menus'], $menuId); if (!$cur) throw new RuntimeException('That menu is no longer in Mobius.');
    if (mobius_menu_is_ours($cur, $cfg)) throw new RuntimeException('That menu already calls this portal.');
    $tpl = mobius_template_menu($l['menus'], $cfg); if (!$tpl) throw new RuntimeException('No menu in Mobius calls this portal yet, so there is no address to copy.');
    return mobius_save_menu($cfg, $menuId, ['name' => $cur['name'], 'shortcode' => $cur['shortcode'], 'shortcodeType' => 'PROXY', 'isExtendable' => $cur['isExtendable'], 'remoteURL' => $tpl['remoteURL'], 'destinationID' => $tpl['destinationID']], $confirm, 'to-portal');
}
function mobius_changes(int $limit = 40, ?string $menuId = null): array {
    mobius_menu_tables(); $limit = max(1, min(500, $limit));
    if ($menuId !== null) { $st = portal_pdo()->prepare("SELECT * FROM mobius_menu_changes WHERE menu_id=? ORDER BY id DESC LIMIT $limit"); $st->execute([$menuId]); return $st->fetchAll(); }
    return portal_pdo()->query("SELECT * FROM mobius_menu_changes ORDER BY id DESC LIMIT $limit")->fetchAll();
}
// Put a menu back as it was before a change. A creation cannot be undone from here (the portal does not delete in Mobius).
function mobius_restore_change(array $cfg, int $changeId): array {
    mobius_menu_tables(); $st = portal_pdo()->prepare('SELECT * FROM mobius_menu_changes WHERE id=?'); $st->execute([$changeId]); $ch = $st->fetch();
    if (!$ch) throw new RuntimeException('That change is not in the history.');
    if (str_ends_with((string)$ch['action'], '-refused')) throw new RuntimeException('Mobius refused that change, so there is nothing to put back.');
    if (str_starts_with((string)$ch['action'], 'step-')) return mobius_step_restore($cfg, $ch);
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    if ($ch['before_json'] === null) { // a menu the portal created (or copied): putting it back = removing it again
        $cur = mobius_find_menu($l['menus'], (string)$ch['menu_id']); if (!$cur) throw new RuntimeException('That menu is no longer in Mobius.');
        mobius_delete_menu($cfg, (string)$ch['menu_id'], mobius_confirm_word($cur), 'undo-create'); return ['menu' => mobius_menu_public($cur), 'change' => 0, 'removed' => true];
    }
    $before = mobius_menu_norm((array)json_decode((string)$ch['before_json'], true)); $in = mobius_menu_public($before);
    $cur = mobius_find_menu($l['menus'], (string)$ch['menu_id']);
    if (!$cur) { // deleted: create it again with the same values (Mobius gives it a new id)
        if ((string)$ch['action'] !== 'delete') throw new RuntimeException('That menu is no longer in Mobius.');
        return mobius_save_menu($cfg, null, $in, mobius_confirm_word($before), 'undelete');
    }
    return mobius_save_menu($cfg, (string)$ch['menu_id'], $in, trim((string)$cur['shortcode']) !== '' ? (string)$cur['shortcode'] : (trim((string)$before['shortcode']) !== '' ? (string)$before['shortcode'] : (string)$cur['name']), 'restore');
}
// What a user types to confirm a risky action on a menu: its short code, or its name when it has none.
function mobius_confirm_word(array $m): string { $m = mobius_menu_norm($m); return trim($m['shortcode']) !== '' ? $m['shortcode'] : $m['name']; }
function mobius_confirm_ok(array $m, string $typed): bool {
    $m = mobius_menu_norm($m); $t = trim($typed);
    return trim($m['shortcode']) !== '' ? mobius_bare_code($t) === mobius_bare_code($m['shortcode']) : ($t !== '' && $t === trim($m['name']));
}

// ---- Copy, delete, calls, stats and steps (Mobius REST API 2.1: ussdmenues/delete, ussdcalls/*_by_menu, ussdmenusteps/list_by_menu) ----
// Copy: a new menu with every setting of the original and a new short code (and name). The original is not touched.
function mobius_copy_menu(array $cfg, string $menuId, string $newCode, string $newName, string $confirm = ''): array {
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    $cur = mobius_find_menu($l['menus'], $menuId); if (!$cur) throw new RuntimeException('That menu is no longer in Mobius.');
    $in = mobius_menu_public($cur); $in['shortcode'] = preg_replace('/\s+/', '', $newCode); $in['name'] = trim($newName) !== '' ? trim($newName) : $cur['name'].' (copy)';
    if ($in['shortcode'] === '' || mobius_bare_code($in['shortcode']) === mobius_bare_code((string)$cur['shortcode'])) throw new RuntimeException('Give the copy its own short code.');
    return mobius_save_menu($cfg, null, $in, $confirm, 'copy');
}
// Delete a menu in Mobius. Always needs its short code (or name) typed; the menu is kept in History so "Put back" creates it again.
function mobius_delete_menu(array $cfg, string $menuId, string $confirm, string $action = 'delete'): array {
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    $cur = mobius_find_menu($l['menus'], $menuId); if (!$cur) throw new RuntimeException('That menu is no longer in Mobius.');
    if (!mobius_confirm_ok($cur, $confirm)) throw new RuntimeException('To delete it, type '.(trim($cur['shortcode']) !== '' ? 'its short code '.$cur['shortcode'] : 'its name “'.$cur['name'].'”').' in the confirmation box. Nothing was deleted.');
    $r = mobius_rest($cfg, 'ussdmenues/delete', ['data' => $menuId]);
    if (!$r['ok'] || (isset($r['json']['data']) && $r['json']['data'] === false)) {
        $err = $r['ok'] ? 'Mobius answered that it did not delete it.' : $r['error'];
        mobius_record_change($menuId, (string)$cur['shortcode'], $action.'-refused', $cur, null, $err); throw new RuntimeException('Mobius refused: '.$err);
    }
    $after = mobius_list_menus($cfg); $gone = $after['ok'] ? mobius_find_menu($after['menus'], $menuId) === null : null;
    $id = mobius_record_change($menuId, (string)$cur['shortcode'], $action, $cur, null, mobius_redact((string)($r['raw'] ?? ''), 1000));
    audit('mobius_menu_'.$action, null, 'mobius', (string)$cur['shortcode'], json_encode(['menu' => $menuId, 'was' => mobius_menu_public($cur)], JSON_UNESCAPED_SLASHES));
    return ['menu' => mobius_menu_public($cur), 'change' => $id, 'gone' => $gone];
}
// When a MongoDB object id was made (its first 8 hex digits are the Unix time): call records carry no other time.
function mobius_oid_time(string $oid): ?int { return preg_match('/^[0-9a-f]{24}$/i', $oid) ? (int)hexdec(substr($oid, 0, 8)) : null; }
// One call record with plain fields.
function mobius_call_norm(array $c): array {
    $id = mobius_id_text($c['callID'] ?? ($c['_id'] ?? '')); $oid = mobius_id_text($c['_id'] ?? ($c['callID'] ?? ''));
    $t = null; foreach (['startTime', 'timestamp', 'created', 'createdAt', 'date', 'time'] as $k) if (!empty($c[$k])) { $t = is_numeric($c[$k]) ? (int)($c[$k] > 1e11 ? $c[$k] / 1000 : $c[$k]) : (strtotime((string)(is_array($c[$k]) ? ($c[$k]['$date'] ?? '') : $c[$k])) ?: null); if ($t) break; }
    $t ??= mobius_oid_time($oid) ?? mobius_oid_time($id);
    $steps = array_map(fn($s) => ['mobile' => !empty($s['isMobile']), 'message' => (string)($s['message'] ?? ''), 'step' => mobius_id_text($s['stepID'] ?? '')], array_values((array)($c['passedSteps'] ?? [])));
    $vars = []; foreach ((array)($c['variables'] ?? []) as $v) if (is_array($v)) $vars[(string)($v['propertyName'] ?? '')] = (string)($v['propertyValue'] ?? $v['defaultValue'] ?? '');
    $err = trim((string)($c['errorMessage'] ?? '')); $code = $c['errorCode'] ?? null;
    return ['id' => $id, 'time' => $t, 'msisdn' => (string)($c['msisdn'] ?? ''), 'request' => (string)($c['originalRequest'] ?? ''), 'completed' => !empty($c['isCompleted']),
        'error' => $err !== '' ? $err : (($code !== null && (int)$code !== 0) ? 'error '.$code : ''), 'steps' => $steps, 'vars' => $vars, 'language' => (string)($c['language'] ?? '')];
}
// The calls of one menu, newest first. ['ok', 'calls' => […], 'next' => key for the following page | null, 'total' => N|null].
function mobius_menu_calls(array $cfg, string $menuId, string $msisdn = '', int $size = 50, ?string $from = null): array {
    $size = max(1, min(500, $size)); $msisdn = preg_replace('/\D+/', '', $msisdn);
    $req = ['key' => $menuId, 'pageSize' => $size, 'includePreviousKey' => false, 'reverse' => true, 'msisdn' => $msisdn] + ($from !== null && $from !== '' ? ['firstKey' => $from] : []);
    $r = mobius_rest($cfg, 'ussdcalls/list_by_menu', $req);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $rows = mobius_find_rows($r['json']) ?? [];
    if (!$rows && $msisdn === '') { unset($req['msisdn']); $r2 = mobius_rest($cfg, 'ussdcalls/list_by_menu', $req); if ($r2['ok'] && ($x = mobius_find_rows($r2['json']))) { $r = $r2; $rows = $x; } } // an empty number may mean "only calls with no number"
    $calls = array_map('mobius_call_norm', $rows);
    usort($calls, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
    $c = mobius_rest($cfg, 'ussdcalls/count_by_menu', ['data' => $menuId, 'msisdn' => $msisdn]); $total = $c['ok'] ? mobius_find_count($c['json']) : null;
    $last = $rows ? mobius_id_text(end($rows)['callID'] ?? (end($rows)['_id'] ?? '')) : '';
    return ['ok' => true, 'calls' => $calls, 'next' => count($rows) >= $size && $last !== '' ? $last : null, 'total' => $total, 'answer' => mobius_redact((string)($r['raw'] ?? ''), 1500)];
}
// Numbers for one menu from its most recent calls (up to $max): per day, completed / errors, top numbers and top answers.
function mobius_menu_stats(array $cfg, string $menuId, int $max = 1000): array {
    $all = []; $from = null; $total = null;
    for ($i = 0; $i < 10 && count($all) < $max; $i++) {
        $r = mobius_menu_calls($cfg, $menuId, '', min(200, $max - count($all)), $from); if (!$r['ok']) return $r;
        $total ??= $r['total']; foreach ($r['calls'] as $c) $all[$c['id'] !== '' ? $c['id'] : count($all)] = $c;
        if ($r['next'] === null || !$r['calls']) break; $from = $r['next'];
    }
    $days = []; $numbers = []; $errors = []; $answers = []; $done = 0; $failed = 0; $first = null; $last = null;
    foreach ($all as $c) {
        $d = $c['time'] ? date('Y-m-d', $c['time']) : 'unknown'; $days[$d] = ($days[$d] ?? 0) + 1;
        if ($c['msisdn'] !== '') $numbers[$c['msisdn']] = ($numbers[$c['msisdn']] ?? 0) + 1;
        if ($c['completed']) $done++; if ($c['error'] !== '') { $failed++; $errors[$c['error']] = ($errors[$c['error']] ?? 0) + 1; }
        foreach ($c['steps'] as $s) if ($s['mobile'] && $s['message'] !== '') $answers[$s['message']] = ($answers[$s['message']] ?? 0) + 1;
        if ($c['time']) { $first = $first === null ? $c['time'] : min($first, $c['time']); $last = $last === null ? $c['time'] : max($last, $c['time']); }
    }
    ksort($days); arsort($numbers); arsort($errors); arsort($answers);
    return ['ok' => true, 'total' => $total, 'read' => count($all), 'completed' => $done, 'failed' => $failed, 'days' => $days, 'numbers' => array_slice($numbers, 0, 10, true),
        'errors' => array_slice($errors, 0, 10, true), 'answers' => array_slice($answers, 0, 15, true), 'unique' => count($numbers), 'from' => $first, 'to' => $last];
}
// ---- Editing a menu's steps (like Mobius' Builder: Edit, Delete, add an option, add the next step) — REST ussdmenusteps/set and /delete ----
// A step is kept exactly as Mobius gives it ({"_id": {"$oid"}, "menuID": {"$oid"}, "parentID": {"$oid"}|null, "localCode", "stepType", "message", "language",
// "errorMessage", "externalUrl", "externalHttpDestinationID", "nextStepID": {"$oid"}|null, "mappingType", "properties", "responseProperties", "headers"}) and
// only the fields the form changes are replaced. parentID is the step before (for an option: its menu), nextStepID the step after (or the jump target).
const MOBIUS_STEP_FIELDS = ['menuID', 'parentID', 'localCode', 'stepType', 'message', 'language', 'errorMessage', 'externalUrl', 'externalHttpDestinationID', 'nextStepID', 'mappingType', 'properties', 'responseProperties', 'headers'];
const MOBIUS_STEP_TYPES = ['MENU', 'MESSAGE', 'COLLECT', 'EXTERNAL_SERVICE', 'GO_TO_STEP', 'GO_TO_MENU', 'VARIABLE_MENU', 'WELCOME'];
const MOBIUS_STEP_LANGS = ['DEFAULT', 'ENGLISH', 'ARABIC', 'TURKISH', 'PORTUGUES', 'SPANISH', 'UTF'];
function mobius_oid(?string $id) { return $id === null || $id === '' ? null : ['$oid' => $id]; }
// Steps that go on to a next step (the others end the call, or jump).
function mobius_step_continues(string $type): bool { return in_array($type, ['WELCOME', 'WELCOME_INCOMING', 'COLLECT', 'EXTERNAL_SERVICE'], true); }
function mobius_step_has_options(string $type): bool { return in_array($type, ['MENU', 'VARIABLE_MENU'], true); }
// Header values are secrets (API keys, hashed passwords): never shown or written to History.
function mobius_step_safe(array $raw): array {
    if (!empty($raw['headers']) && is_array($raw['headers'])) foreach ($raw['headers'] as &$h) if (is_array($h)) { foreach (['propertyValue', 'defaultValue'] as $k) if (isset($h[$k]) && $h[$k] !== '') $h[$k] = '•••'; } unset($h);
    return $raw;
}
// Editing a menu's steps needs it unlocked by typing its short code (or name); it stays unlocked for 30 minutes in this browser session.
function mobius_steps_unlocked(string $menuId): bool { return (int)($_SESSION['mobius_unlock'][$menuId] ?? 0) > time(); }
function mobius_steps_unlock(array $menu, string $typed): void {
    if (!mobius_confirm_ok($menu, $typed)) throw new RuntimeException('Type '.(trim($menu['shortcode']) !== '' ? 'the short code '.$menu['shortcode'] : 'the name “'.$menu['name'].'”').' to unlock editing.');
    $_SESSION['mobius_unlock'][(string)$menu['menuID']] = time() + 1800;
}
// What Put back needs for a step change (the full steps, header secrets included) is kept encrypted, apart from the History row.
function mobius_step_backup_put(int $changeId, array $data): void {
    portal_pdo()->exec("CREATE TABLE IF NOT EXISTS mobius_step_backups (change_id INT NOT NULL PRIMARY KEY, data_enc MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    portal_pdo()->prepare('INSERT INTO mobius_step_backups(change_id, data_enc) VALUES(?,?)')->execute([$changeId, encrypt_secret(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]);
}
function mobius_step_backup_get(int $changeId): array {
    try { $st = portal_pdo()->prepare('SELECT data_enc FROM mobius_step_backups WHERE change_id=?'); $st->execute([$changeId]); $v = $st->fetchColumn(); } catch (Throwable $e) { $v = false; }
    return $v === false ? [] : (json_decode((string)decrypt_secret((string)$v), true) ?: []);
}
function mobius_step_words(array $raw): string { $t = strtoupper((string)($raw['stepType'] ?? '')); $c = (string)($raw['localCode'] ?? ''); $m = trim((string)($raw['message'] ?? ($raw['externalUrl'] ?? '')));
    return ($c !== '' ? 'key '.$c.' — ' : '').(MOBIUS_STEP_KINDS[$t][0] ?? $t).($m !== '' ? ': '.mb_strimwidth(str_replace("\n", ' ', $m), 0, 60, '…') : ''); }
// "name = value | default" lines <-> Mobius properties [{propertyName, propertyValue, defaultValue}].
function mobius_props_parse(string $text, string $what): ?array {
    $out = [];
    foreach (preg_split('/\R/', $text) as $n => $line) {
        $line = trim($line); if ($line === '') continue;
        if (!preg_match('/^([^=|]+?)\s*(?:=\s*([^|]*?))?\s*(?:\|\s*(.*))?$/', $line, $m)) throw new RuntimeException($what.', line '.($n + 1).': write it as  name = value  (and  | default  if there is one).');
        $name = trim($m[1]); if (!preg_match('/^[A-Za-z0-9_.\[\]$-]{1,120}$/', $name)) throw new RuntimeException($what.', line '.($n + 1).': "'.$name.'" is not a valid name.');
        $v = isset($m[2]) && trim($m[2]) !== '' ? trim($m[2]) : null; $d = isset($m[3]) && trim($m[3]) !== '' ? trim($m[3]) : null;
        $out[] = ['propertyName' => $name, 'propertyValue' => $v, 'defaultValue' => $d];
    }
    return $out ?: null;
}
function mobius_props_text($props): string {
    $l = []; foreach ((array)$props as $p) if (is_array($p)) $l[] = ($p['propertyName'] ?? '').(($p['propertyValue'] ?? null) !== null ? ' = '.$p['propertyValue'] : '').(($p['defaultValue'] ?? null) !== null ? ' | '.$p['defaultValue'] : '');
    return implode("\n", $l);
}
// Headers: "Name: value" lines. An empty box keeps the headers the step has.
function mobius_headers_parse(string $text, $current) {
    if (trim($text) === '') return $current;
    $out = [];
    foreach (preg_split('/\R/', $text) as $n => $line) {
        $line = trim($line); if ($line === '') continue;
        if (!preg_match('/^([A-Za-z0-9_-]{1,80})\s*:\s*(.+)$/', $line, $m)) throw new RuntimeException('Headers, line '.($n + 1).': write it as  Name: value');
        $out[] = ['propertyName' => $m[1], 'propertyValue' => null, 'defaultValue' => trim($m[2])];
    }
    return $out ?: null;
}
// The step after the form, built on the step as Mobius had it (or an empty one).
function mobius_step_from_input(array $in, ?array $raw, string $menuId, array $steps, array $menus): array {
    $type = strtoupper(trim((string)($in['type'] ?? ($raw['stepType'] ?? 'MESSAGE'))));
    if (!in_array($type, MOBIUS_STEP_TYPES, true) && $type !== ($raw['stepType'] ?? null)) throw new RuntimeException('Choose what the step does.');
    $s = $raw ?? array_fill_keys(MOBIUS_STEP_FIELDS, null); $s['menuID'] = mobius_oid($menuId); $s['stepType'] = $type;
    $lang = strtoupper(trim((string)($in['language'] ?? 'DEFAULT'))); if (!in_array($lang, MOBIUS_STEP_LANGS, true)) $lang = 'DEFAULT';
    $msg = str_replace("\r", '', (string)($in['message'] ?? '')); $msgReq = in_array($type, ['MENU', 'MESSAGE', 'COLLECT'], true);
    if ($msgReq && trim($msg) === '') throw new RuntimeException('Write the message the customer sees.');
    if (mb_strlen($msg) > 1000) throw new RuntimeException('The message is too long (1,000 characters at most; a USSD screen shows about 160).');
    foreach (['message', 'language', 'errorMessage', 'externalUrl', 'mappingType'] as $k) if (!in_array($type, ['EXTERNAL_SERVICE'], true) || in_array($k, ['message', 'language', 'errorMessage'], true)) {} // (kept below)
    $s['message'] = $msgReq || $type === 'VARIABLE_MENU' ? ($msg === '' ? null : $msg) : null;
    $s['language'] = $msgReq || $type === 'VARIABLE_MENU' ? $lang : null;
    $s['errorMessage'] = mobius_step_has_options($type) ? (trim((string)($in['invalid'] ?? '')) === '' ? null : trim((string)$in['invalid'])) : null;
    if ($type === 'COLLECT' || $type === 'VARIABLE_MENU') {
        $var = trim((string)($in['variable'] ?? '')); if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,60}$/', $var)) throw new RuntimeException('Give the variable a name (letters, digits and _), for example otherMsisdn.');
        $s['properties'] = [['propertyName' => $var, 'propertyValue' => null, 'defaultValue' => null]];
    } elseif ($type !== 'EXTERNAL_SERVICE') $s['properties'] = null;
    if ($type === 'EXTERNAL_SERVICE') {
        $url = trim((string)($in['url'] ?? '')); if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) throw new RuntimeException('Give the full address of the service (http:// or https://).');
        $fmt = strtoupper(trim((string)($in['format'] ?? 'JSON'))); if (!in_array($fmt, ['JSON', 'XML', 'ENCODED_FORM'], true)) $fmt = 'JSON';
        $s['externalUrl'] = $url; $s['mappingType'] = $fmt;
        $s['properties'] = mobius_props_parse((string)($in['sends'] ?? ''), 'Sends'); $s['responseProperties'] = mobius_props_parse((string)($in['reads'] ?? ''), 'Reads back');
        $s['headers'] = mobius_headers_parse((string)($in['headers'] ?? ''), $raw['headers'] ?? null);
    } else { $s['externalUrl'] = null; $s['mappingType'] = null; $s['responseProperties'] = null; if (($raw['stepType'] ?? '') === 'EXTERNAL_SERVICE') $s['headers'] = null; }
    if ($type === 'GO_TO_STEP') { $t = (string)($in['target_step'] ?? ''); if (!isset($steps[$t])) throw new RuntimeException('Choose the step to go to.'); $s['nextStepID'] = mobius_oid($t); }
    elseif ($type === 'GO_TO_MENU') { $t = (string)($in['target_menu'] ?? ''); if (!mobius_find_menu($menus, $t)) throw new RuntimeException('Choose the menu to go to.'); $s['nextStepID'] = mobius_oid($t); }
    elseif (in_array($raw['stepType'] ?? '', ['GO_TO_STEP', 'GO_TO_MENU'], true)) $s['nextStepID'] = null; // was a jump: the target is not a next step
    elseif (!mobius_step_continues($type) && !mobius_step_has_options($type)) { /* a message keeps whatever next Mobius had (often an end step) */ }
    return $s;
}
// Read the steps of a menu, or fail.
function mobius_steps_or_fail(array $cfg, string $menuId): array { $r = mobius_menu_steps($cfg, $menuId); if (!$r['ok']) throw new RuntimeException('Could not read the steps from Mobius: '.$r['error']); return $r; }
// A new MongoDB-style object id (8 hex digits of time + 16 random), as Mobius uses for every record.
function mobius_new_oid(): string { return sprintf('%08x', time()).bin2hex(random_bytes(8)); }
// The step in the shape of Mobius' API documentation (MenuStep: plain text ids, stepID), for installs that want that shape.
function mobius_step_swagger(array $raw, string $id): array {
    $t = fn($k) => ($x = mobius_id_text($raw[$k] ?? '')) === '' ? null : $x;
    return ['stepID' => $id, 'menuID' => $t('menuID'), 'parentID' => $t('parentID'), 'nextStepID' => $t('nextStepID'), 'localCode' => $raw['localCode'] ?? null, 'stepType' => $raw['stepType'] ?? null,
        'message' => $raw['message'] ?? null, 'language' => $raw['language'] ?? null, 'errorMessage' => $raw['errorMessage'] ?? null, 'externalUrl' => $raw['externalUrl'] ?? null, 'mappingType' => $raw['mappingType'] ?? null,
        'properties' => $raw['properties'] ?? null, 'responseProperties' => $raw['responseProperties'] ?? null];
}
// Save one step. Seen on the real Mobius (2 Nov 2026, test menu *7999#): changing an existing step through ussdmenusteps/set works, but a NEW step
// is refused with HTTP 500 whether or not it carries an id, and the documented shape (stepID) is refused as an unknown field. So a new step is
// tried: (0) set as stored, without an id; (1) set with an id made here; (3) setmulti without an id; (4) setmulti with an id. Between tries the
// menu is read again so a step Mobius did save despite an error is never created twice. The way that works is remembered (mobius_step_shape).
// When none works, MOBIUS_NO_CREATE is thrown so the page can send the user to Mobius' own Builder for that one step.
const MOBIUS_NO_CREATE = 'Mobius does not let its API create new steps';
function mobius_step_write(array $cfg, array $raw, ?array $known = null): array {
    $isNew = mobius_id_text($raw['_id'] ?? '') === ''; $menuId = mobius_id_text($raw['menuID'] ?? '');
    $pref = (string)($cfg['mobius_step_shape'] ?? ''); $errors = []; $labels = [0 => 'set', 1 => 'set with an id', 3 => 'setmulti', 4 => 'setmulti with an id'];
    if (!$isNew) { $r = mobius_rest($cfg, 'ussdmenusteps/set', ['data' => $raw]); if (!$r['ok']) throw new RuntimeException('Mobius refused: '.mb_strimwidth(preg_replace('/<[^>]+>/', ' ', $r['error']), 0, 300, '…')); return ['id' => mobius_id_text($raw['_id']), 'raw' => (string)($r['raw'] ?? '')]; }
    $order = array_values(array_unique(array_merge($pref !== '' && isset($labels[(int)$pref]) ? [(int)$pref] : [], [0, 1, 3, 4])));
    foreach ($order as $shape) {
        $id = in_array($shape, [1, 4], true) ? mobius_new_oid() : '';
        $body = $raw; unset($body['_id']); if ($id !== '') $body['_id'] = mobius_oid($id);
        $r = in_array($shape, [3, 4], true) ? mobius_rest($cfg, 'ussdmenusteps/setmulti', ['data' => [$body]]) : mobius_rest($cfg, 'ussdmenusteps/set', ['data' => $body]);
        if ($r['ok']) {
            $d = $r['json']['data'] ?? null; if (is_array($d) && array_is_list($d)) $d = $d[0] ?? null;
            $got = is_array($d) ? mobius_id_text($d['_id'] ?? ($d['stepID'] ?? '')) : '';
            if ($got === '' && $id === '' && $menuId !== '' && $known !== null) { $now = mobius_menu_steps($cfg, $menuId); if ($now['ok']) foreach ($now['steps'] as $sid => $s) if (!isset($known[$sid]) && $s['type'] === strtoupper((string)($raw['stepType'] ?? '')) && $s['parent'] === mobius_id_text($raw['parentID'] ?? '')) $got = $sid; }
            if ((string)$shape !== $pref) { try { ussd_proxy_set(['mobius_step_shape' => (string)$shape]); } catch (Throwable $e) {} }
            return ['id' => $got !== '' ? $got : $id, 'raw' => (string)($r['raw'] ?? '')];
        }
        $errors[] = '['.$labels[$shape].'] '.mb_strimwidth(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $r['error'])), 0, 160, '…');
        if ($known !== null && $menuId !== '') { // did it get saved anyway?
            $now = mobius_menu_steps($cfg, $menuId);
            if ($now['ok']) foreach ($now['steps'] as $sid => $s) if (!isset($known[$sid]) && $s['type'] === strtoupper((string)($raw['stepType'] ?? '')) && $s['parent'] === mobius_id_text($raw['parentID'] ?? '') && $s['code'] === trim((string)($raw['localCode'] ?? ''))) return ['id' => $sid, 'raw' => ''];
        }
    }
    throw new RuntimeException(MOBIUS_NO_CREATE.' (it answered: '.implode(' | ', $errors).').');
}
// Create several linked steps at once (ids made here, so they can point at each other). Seen on the real Mobius: a "Question with input" alone
// is refused (it must lead somewhere), so a question or a service is created together with the message that follows it.
function mobius_steps_create(array $cfg, array $list, array $known): void {
    $ids = array_map(fn($x) => mobius_id_text($x['_id'] ?? ''), $list); $menuId = mobius_id_text($list[0]['menuID'] ?? ''); $errors = [];
    $present = function () use ($cfg, $menuId, $ids): array { $now = mobius_menu_steps($cfg, $menuId); return $now['ok'] ? array_values(array_filter($ids, fn($i) => isset($now['steps'][$i]))) : []; };
    $tries = [['setmulti', fn() => mobius_rest($cfg, 'ussdmenusteps/setmulti', ['data' => array_values($list)])], ['set, one by one', function () use ($cfg, $list) { foreach ($list as $x) { $r = mobius_rest($cfg, 'ussdmenusteps/set', ['data' => $x]); if (!$r['ok']) return $r; } return ['ok' => true]; }]];
    foreach ($tries as [$label, $go]) {
        $r = $go(); $got = $present();
        if (count($got) === count($ids)) return;
        foreach ($got as $i) mobius_rest($cfg, 'ussdmenusteps/delete', ['data' => $i]); // half-made: take it away again
        $errors[] = '['.$label.'] '.($r['ok'] ? 'said yes, but the steps are not there' : mb_strimwidth(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $r['error'])), 0, 160, '…'));
    }
    throw new RuntimeException(MOBIUS_NO_CREATE.' (it answered: '.implode(' | ', $errors).').');
}
// Edit a step, or add one: $how = 'edit' | 'option' (under a menu, with its key) | 'next' (after $anchor) | 'first' (the Welcome of an empty menu).
function mobius_step_save(array $cfg, array $menu, string $how, string $anchor, array $in): array {
    $menuId = (string)$menu['menuID']; if (!mobius_steps_unlocked($menuId)) throw new RuntimeException('Unlock editing for this menu first (type its short code).');
    $l = mobius_list_menus($cfg); $menus = $l['ok'] ? $l['menus'] : [];
    $st = mobius_steps_or_fail($cfg, $menuId); $steps = $st['steps'];
    $code = trim((string)($in['code'] ?? ''));
    if ($how === 'edit') {
        if (!isset($steps[$anchor])) throw new RuntimeException('That step is no longer in Mobius.');
        $before = $steps[$anchor]['_raw']; $new = mobius_step_from_input($in, $before, $menuId, $steps, $menus);
        if (($before['localCode'] ?? null) !== null || $code !== '') {
            if ($code === '' && ($before['localCode'] ?? '') !== '') throw new RuntimeException('An option needs its key (the number the customer presses).');
            foreach ($steps as $o) if ($o['id'] !== $anchor && $o['parent'] === $steps[$anchor]['parent'] && $o['code'] === $code && $code !== '') throw new RuntimeException('Key '.$code.' is already used in this menu.');
            $new['localCode'] = $code === '' ? null : $code;
        }
        if ($new == $before) throw new RuntimeException('Nothing was changed.');
        $w = mobius_step_write($cfg, $new);
        $hid = mobius_record_change($menuId, (string)$menu['shortcode'], 'step-edit', null, null, 'Changed '.mobius_step_words($before).($before['stepType'] !== $new['stepType'] || ($before['message'] ?? null) !== ($new['message'] ?? null) ? ' → '.mobius_step_words($new) : ''));
        mobius_step_backup_put($hid, ['step' => $anchor, 'before' => $before]);
        audit('mobius_step_edit', null, 'mobius', (string)$menu['shortcode'], json_encode(['menu' => $menuId, 'step' => $anchor, 'type' => $new['stepType']]));
        return ['id' => $anchor, 'change' => $hid];
    }
    if ($how === 'first') { foreach ($steps as $o) if (in_array($o['type'], ['WELCOME', 'WELCOME_INCOMING'], true)) throw new RuntimeException('This menu already starts with a Welcome step.'); $in['type'] = 'WELCOME'; $parent = null; }
    else {
        if (!isset($steps[$anchor])) throw new RuntimeException('That step is no longer in Mobius.'); $p = $steps[$anchor]; $parent = $anchor;
        if ($how === 'option') {
            if (!mobius_step_has_options($p['type'])) throw new RuntimeException('Options can only be added under a Menu.');
            if (!preg_match('/^[0-9*#]{1,6}$/', $code)) throw new RuntimeException('Give the option its key: the number the customer presses (0-9, * or #).');
            foreach ($steps as $o) if ($o['parent'] === $anchor && $o['code'] === $code) throw new RuntimeException('Key '.$code.' is already used in this menu.');
        } elseif ($how === 'next') {
            if (!mobius_step_continues($p['type'])) throw new RuntimeException('A '.(MOBIUS_STEP_KINDS[$p['type']][0] ?? $p['type']).' step does not lead on to a next step.');
            if ($p['next'] !== '' && isset($steps[$p['next']])) throw new RuntimeException('That step already has a next step: change that one instead.');
        } else throw new RuntimeException('Unknown action.');
    }
    $new = mobius_step_from_input($in, null, $menuId, $steps, $menus); $new['parentID'] = mobius_oid($parent); if ($how === 'option') $new['localCode'] = $code;
    $then = trim(str_replace("\r", '', (string)($in['then'] ?? '')));
    if ($then !== '' && mobius_step_continues($new['stepType'])) { // the step and the message after it, linked, in one go
        if (mb_strlen($then) > 1000) throw new RuntimeException('The message after it is too long.');
        $id = mobius_new_oid(); $fid = mobius_new_oid(); $new['_id'] = mobius_oid($id); $new['nextStepID'] = mobius_oid($fid);
        $follow = array_fill_keys(MOBIUS_STEP_FIELDS, null); $follow['menuID'] = mobius_oid($menuId); $follow['parentID'] = mobius_oid($id); $follow['stepType'] = 'MESSAGE'; $follow['message'] = $then; $follow['language'] = $new['language'] ?? 'DEFAULT'; $follow['_id'] = mobius_oid($fid);
        mobius_steps_create($cfg, [$new, $follow], $steps); $w = ['id' => $id];
    } else { $w = mobius_step_write($cfg, $new, $steps); }
    $id = $w['id'];
    if ($id === '') { $after = mobius_steps_or_fail($cfg, $menuId)['steps']; foreach ($after as $sid => $o) if (!isset($steps[$sid]) && $o['type'] === $new['stepType'] && $o['parent'] === (string)$parent) $id = $sid; }
    if ($id === '') throw new RuntimeException('Mobius accepted the new step but did not say its id; refresh to see it.');
    if ($how === 'next') { $pr = $steps[$anchor]['_raw']; $pr['nextStepID'] = mobius_oid($id); mobius_step_write($cfg, $pr); }
    $hid = mobius_record_change($menuId, (string)$menu['shortcode'], 'step-add', null, null, 'Added '.mobius_step_words($new));
    mobius_step_backup_put($hid, ['step' => $id, 'how' => $how, 'anchor' => $anchor]);
    audit('mobius_step_add', null, 'mobius', (string)$menu['shortcode'], json_encode(['menu' => $menuId, 'step' => $id, 'type' => $new['stepType'], 'how' => $how]));
    return ['id' => $id, 'change' => $hid];
}
// The step and everything that hangs from it (its options, and the steps that follow it).
function mobius_step_subtree(array $steps, string $id): array {
    $out = []; $todo = [$id];
    while ($todo) { $x = array_shift($todo); if (isset($out[$x]) || !isset($steps[$x])) continue; $out[$x] = true;
        foreach ($steps as $o) if ($o['parent'] === $x) $todo[] = $o['id'];
        $n = $steps[$x]['next']; if ($n !== '' && isset($steps[$n]) && !in_array($steps[$x]['type'], ['GO_TO_STEP', 'GO_TO_MENU'], true) && $steps[$n]['parent'] === $x) $todo[] = $n; }
    return array_keys($out);
}
function mobius_step_delete(array $cfg, array $menu, string $stepId): array {
    $menuId = (string)$menu['menuID']; if (!mobius_steps_unlocked($menuId)) throw new RuntimeException('Unlock editing for this menu first (type its short code).');
    $st = mobius_steps_or_fail($cfg, $menuId); $steps = $st['steps']; if (!isset($steps[$stepId])) throw new RuntimeException('That step is no longer in Mobius.');
    $ids = mobius_step_subtree($steps, $stepId); $gone = array_flip($ids);
    $jumps = []; foreach ($steps as $o) if (!isset($gone[$o['id']]) && $o['type'] === 'GO_TO_STEP' && isset($gone[$o['next']])) $jumps[] = ($o['code'] !== '' ? 'key '.$o['code'] : $o['id']);
    if ($jumps) throw new RuntimeException('Other steps jump to what you are deleting ('.implode(', ', $jumps).'). Change or delete those "Go to step" first.');
    $before = array_map(fn($x) => $steps[$x]['_raw'], $ids);
    $parentFix = null; foreach ($steps as $o) if (!isset($gone[$o['id']]) && $o['next'] === $stepId && !in_array($o['type'], ['GO_TO_STEP', 'GO_TO_MENU'], true)) $parentFix = $o;
    foreach (array_reverse($ids) as $x) { $r = mobius_rest($cfg, 'ussdmenusteps/delete', ['data' => $x]); if (!$r['ok']) throw new RuntimeException('Mobius refused to delete a step: '.$r['error'].' (some steps may already be gone — refresh).'); }
    if ($parentFix) { $pr = $parentFix['_raw']; $pr['nextStepID'] = null; mobius_step_write($cfg, $pr); }
    $hid = mobius_record_change($menuId, (string)$menu['shortcode'], 'step-delete', null, null, 'Deleted '.mobius_step_words($before[0]).(count($before) > 1 ? ' and '.(count($before) - 1).' step(s) under it' : ''));
    mobius_step_backup_put($hid, ['steps' => $before, 'relink' => $parentFix['id'] ?? null]);
    audit('mobius_step_delete', null, 'mobius', (string)$menu['shortcode'], json_encode(['menu' => $menuId, 'step' => $stepId, 'count' => count($ids)]));
    return ['count' => count($ids), 'change' => $hid];
}
// Put back a step change: an edit gets its earlier values, an added step is removed, deleted steps are created again (with new ids, linked as before).
function mobius_step_restore(array $cfg, array $ch): array {
    $info = mobius_step_backup_get((int)$ch['id']); $menuId = (string)$ch['menu_id']; if (!$info) throw new RuntimeException('The earlier values were not kept for that change.');
    $l = mobius_list_menus($cfg); $menu = $l['ok'] ? mobius_find_menu($l['menus'], $menuId) : null; if (!$menu) throw new RuntimeException('That menu is no longer in Mobius.');
    $st = mobius_steps_or_fail($cfg, $menuId); $steps = $st['steps'];
    if ($ch['action'] === 'step-edit') {
        $b = $info['before'] ?? null; if (!is_array($b)) throw new RuntimeException('The earlier values were not kept (the History row is too old).');
        if (!isset($steps[(string)($info['step'] ?? '')])) throw new RuntimeException('That step is no longer in Mobius.');
        mobius_step_write($cfg, $b); mobius_record_change($menuId, (string)$ch['shortcode'], 'step-restore', null, null, 'Put back '.mobius_step_words($b));
        return ['menu' => mobius_menu_public($menu), 'change' => 0, 'steps' => true];
    }
    if ($ch['action'] === 'step-add') {
        $id = (string)($info['step'] ?? ''); if (!isset($steps[$id])) throw new RuntimeException('That step is no longer in Mobius.');
        $_SESSION['mobius_unlock'][$menuId] = time() + 60; mobius_step_delete($cfg, $menu, $id);
        return ['menu' => mobius_menu_public($menu), 'change' => 0, 'steps' => true];
    }
    if ($ch['action'] === 'step-delete') {
        $list = (array)($info['steps'] ?? []); if (!$list) throw new RuntimeException('The deleted steps were not kept.');
        $map = []; foreach ($list as $raw) $map[mobius_id_text($raw['_id'] ?? '')] = mobius_new_oid(); // new ids made here, so the steps are created already linked
        $batch = []; foreach ($list as $raw) { $x = $raw; $x['_id'] = mobius_oid($map[mobius_id_text($raw['_id'] ?? '')]);
            foreach (['parentID', 'nextStepID'] as $k) { $t = mobius_id_text($x[$k] ?? ''); if ($t !== '' && isset($map[$t])) $x[$k] = mobius_oid($map[$t]); } $batch[] = $x; }
        mobius_steps_create($cfg, $batch, $steps);
        $rel = (string)($info['relink'] ?? ''); $first = mobius_id_text($list[0]['_id'] ?? '');
        if ($rel !== '' && isset($steps[$rel], $map[$first])) { $pr = $steps[$rel]['_raw']; $pr['nextStepID'] = mobius_oid($map[$first]); mobius_step_write($cfg, $pr); }
        mobius_record_change($menuId, (string)$ch['shortcode'], 'step-undelete', null, null, 'Created again '.count($map).' step(s): '.mobius_step_words($list[0]));
        return ['menu' => mobius_menu_public($menu), 'change' => 0, 'steps' => true];
    }
    throw new RuntimeException('That change cannot be put back.');
}

// Names of the USSD destinations (ussddestinationsettings/list), so a menu shows "SS7 HLR" rather than 69a198b7…: [id => name]. Read-only; [] when refused.
function mobius_destination_names(array $cfg): array {
    static $memo = null; if ($memo !== null) return $memo;
    $r = mobius_rest($cfg, 'ussddestinationsettings/list', ['pageSize' => 500, 'includePreviousKey' => false, 'reverse' => false]);
    $out = []; if ($r['ok']) foreach (mobius_find_rows($r['json']) ?? [] as $d) {
        $id = mobius_id_text($d['_id'] ?? ($d['destinationID'] ?? ($d['id'] ?? ''))); if ($id === '') continue;
        $name = ''; foreach (['name', 'destinationName', 'title', 'description', 'remoteName', 'address'] as $k) if (isset($d[$k]) && is_scalar($d[$k]) && trim((string)$d[$k]) !== '') { $name = trim((string)$d[$k]); break; }
        $out[$id] = $name !== '' ? $name : $id;
    }
    return $memo = $out;
}
// What a menu is, in plain words (for the list): portal = calls this portal; mobius = built in Mobius' Builder; push = sent by the network; other = another service.
const MOBIUS_KINDS = ['mobius' => ['Built in Mobius', 'bg-primary-subtle text-primary-emphasis'], 'portal' => ['Runs in the portal', 'bg-success-subtle text-success-emphasis'], 'push' => ['Push message', 'bg-warning-subtle text-warning-emphasis'], 'other' => ['Other service', 'bg-secondary-subtle text-secondary-emphasis']];
function mobius_menu_kind(array $m, array $cfg): string {
    if (mobius_menu_is_ours($m, $cfg)) return 'portal';
    $t = strtoupper((string)($m['shortcodeType'] ?? ''));
    return $t === 'MS_INITIATED' ? 'mobius' : ($t === 'NETWORK_INITIATED' ? 'push' : 'other');
}
// Words for the History card.
const MOBIUS_ACTION_LABELS = ['create' => 'Created', 'copy' => 'Copied', 'change' => 'Changed', 'rename' => 'Renamed', 'to-portal' => 'Sent to portal', 'restore' => 'Put back', 'delete' => 'Deleted', 'undelete' => 'Created again', 'undo-create' => 'Removed again', 'step-edit' => 'Step changed', 'step-add' => 'Step added', 'step-delete' => 'Steps deleted', 'step-restore' => 'Step put back', 'step-undelete' => 'Steps created again'];
function mobius_action_label(string $a): string { $refused = str_ends_with($a, '-refused'); $b = $refused ? substr($a, 0, -8) : $a; return (MOBIUS_ACTION_LABELS[$b] ?? ucfirst($b)).($refused ? ' — refused' : ''); }
// The steps of a menu built inside Mobius (its Builder): ['ok', 'steps' => [id => step], 'tree' => nested nodes, 'raw' => rows as Mobius gave them].
// How Mobius links steps (seen on Main Menu *9606*02141#): WELCOME → nextStepID → MENU; a MENU's options are steps whose parentID is the menu and
// whose localCode is the key the customer presses; COLLECT ("Question with input") → nextStepID → the following step; GO_TO_STEP jumps to a step of
// this menu and GO_TO_MENU to another menu (both in nextStepID); MESSAGE ends the call.
function mobius_menu_steps(array $cfg, string $menuId): array {
    $r = mobius_rest($cfg, 'ussdmenusteps/list_by_menu', ['key' => $menuId, 'pageSize' => 500, 'includePreviousKey' => false, 'reverse' => false]);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $raw = mobius_find_rows($r['json']) ?? []; $steps = [];
    foreach ($raw as $s) {
        $id = mobius_id_text($s['stepID'] ?? ($s['_id'] ?? '')); if ($id === '') continue;
        $props = fn($k) => array_values(array_filter(array_map(fn($p) => is_array($p) ? ['name' => (string)($p['propertyName'] ?? ''), 'value' => (string)($p['propertyValue'] ?? ''), 'default' => (string)($p['defaultValue'] ?? '')] : null, (array)($s[$k] ?? []))));
        $steps[$id] = ['id' => $id, 'parent' => mobius_id_text($s['parentID'] ?? ''), 'type' => strtoupper((string)($s['stepType'] ?? '')), 'code' => trim((string)($s['localCode'] ?? '')), 'message' => (string)($s['message'] ?? ''),
            'next' => mobius_id_text($s['nextStepID'] ?? ''), 'url' => (string)($s['externalUrl'] ?? ''), 'mapping' => (string)($s['mappingType'] ?? ''), 'error' => (string)($s['errorMessage'] ?? ''),
            'language' => (string)($s['language'] ?? ''), 'props' => $props('properties'), 'resp' => $props('responseProperties'), '_raw' => $s];
    }
    return ['ok' => true, 'steps' => $steps, 'tree' => mobius_steps_tree($steps), 'raw' => $raw];
}
// Steps as a tree for drawing: [['id', 'via' => 'option'|'next'|'root', 'kids' => […], 'ref' => true when the step is already drawn elsewhere]].
function mobius_steps_tree(array $steps): array {
    $byParent = []; foreach ($steps as $s) if ($s['parent'] !== '' && isset($steps[$s['parent']]) && $s['parent'] !== $s['id']) $byParent[$s['parent']][] = $s['id'];
    $order = function (array $ids) use ($steps): array { usort($ids, function ($a, $b) use ($steps) { $ca = $steps[$a]['code']; $cb = $steps[$b]['code'];
        if (($ca === '') !== ($cb === '')) return $ca === '' ? -1 : 1; return is_numeric($ca) && is_numeric($cb) ? $ca <=> $cb : strnatcasecmp($ca, $cb); }); return $ids; };
    $placed = [];
    $build = function (string $id, string $via, int $depth) use (&$build, &$placed, $steps, $byParent, $order): array {
        if (isset($placed[$id]) || $depth > 40) return ['id' => $id, 'via' => $via, 'kids' => [], 'ref' => true];
        $placed[$id] = true; $s = $steps[$id]; $kids = [];
        foreach ($order($byParent[$id] ?? []) as $k) $kids[] = $build($k, 'option', $depth + 1);
        $jump = in_array($s['type'], ['GO_TO_STEP', 'GO_TO_MENU'], true);
        if (!$jump && $s['next'] !== '' && isset($steps[$s['next']]) && !in_array($s['next'], $byParent[$id] ?? [], true) && !isset($placed[$s['next']])) $kids[] = $build($s['next'], 'next', $depth + 1);
        return ['id' => $id, 'via' => $via, 'kids' => $kids, 'ref' => false];
    };
    $roots = []; foreach ($steps as $s) if (in_array($s['type'], ['WELCOME', 'WELCOME_INCOMING'], true)) $roots[] = $build($s['id'], 'root', 0);
    $nexts = []; foreach ($steps as $s) if ($s['next'] !== '') $nexts[$s['next']] = true;
    foreach ($steps as $s) if (!isset($placed[$s['id']]) && ($s['parent'] === '' || !isset($steps[$s['parent']])) && !isset($nexts[$s['id']])) $roots[] = $build($s['id'], 'root', 0);
    foreach ($steps as $s) if (!isset($placed[$s['id']])) $roots[] = $build($s['id'], 'root', 0); // whatever is left (loops)
    return $roots;
}
// How Mobius' Builder names each kind of step, and the colour of its icon.
const MOBIUS_STEP_KINDS = ['WELCOME' => ['Welcome', 'fa-hashtag', '#3fae5a'], 'WELCOME_INCOMING' => ['Welcome (incoming)', 'fa-hashtag', '#3fae5a'], 'MENU' => ['Menu', 'fa-list-ol', '#7b61ff'],
    'VARIABLE_MENU' => ['Variable menu', 'fa-list-check', '#7b61ff'], 'MESSAGE' => ['Message Response', 'fa-comment', '#e5534b'], 'COLLECT' => ['Question with input', 'fa-keyboard', '#e0a800'],
    'GO_TO_STEP' => ['Go to step', 'fa-arrow-right', '#5f6b7a'], 'GO_TO_MENU' => ['Go to menu', 'fa-rotate-right', '#5f6b7a'], 'EXTERNAL_SERVICE' => ['External service', 'fa-cloud-arrow-up', '#1f8ad1'], 'EMPTY' => ['Empty step', 'fa-plus', '#aab3be']];
// The second line of a card: what the step says or where it goes.
function mobius_step_summary(array $s, array $steps, array $menuNames): string {
    $first = fn(string $t) => trim(strtok(str_replace("\r", '', $t), "\n") ?: $t);
    switch ($s['type']) {
        case 'GO_TO_MENU': return $menuNames[$s['next']] ?? ($s['next'] !== '' ? 'menu …'.substr($s['next'], -8) : '(no menu chosen)');
        case 'GO_TO_STEP': $t = $steps[$s['next']] ?? null; return $t ? (MOBIUS_STEP_KINDS[$t['type']][0] ?? $t['type']).($t['code'] !== '' ? ' '.$t['code'] : '').': '.mb_strimwidth(mobius_step_summary($t, [], $menuNames), 0, 40, '…') : ($s['next'] !== '' ? 'step …'.substr($s['next'], -8) : '(no step chosen)');
        case 'EXTERNAL_SERVICE': $u = parse_url($s['url']); return ($u['host'] ?? '').($u['path'] ?? '') ?: '(no address)';
        default: return $s['message'] !== '' ? $first($s['message']) : ($s['error'] !== '' ? $s['error'] : '');
    }
}
// ---- "Create a menu": a menu built in Mobius, with its first screens (or a copy of another menu's steps) ----
// Starters for a new Mobius-built menu. Each is a list of steps: [key, parent key|null, type, fields], linked by key; ids are made at creation.
function mobius_starters(): array {
    return [
        'options' => ['Menu with options', 'A menu: the customer presses 1, 2 or 0 and sees a message. Change the texts afterwards.', [
            ['w', null, 'WELCOME', ['next' => 'm']],
            ['m', 'w', 'MENU', ['message' => "MENU NAME\n1. First choice\n2. Second choice\n0. Exit", 'errorMessage' => 'Wrong choice, please try again.']],
            ['o1', 'm', 'MESSAGE', ['code' => '1', 'message' => 'You chose the first choice.']],
            ['o2', 'm', 'MESSAGE', ['code' => '2', 'message' => 'You chose the second choice.']],
            ['o0', 'm', 'MESSAGE', ['code' => '0', 'message' => 'Thank you, goodbye.']]]],
        'question' => ['Question and reply', 'Asks the customer to type something (for example a number) and answers with it.', [
            ['w', null, 'WELCOME', ['next' => 'q']],
            ['q', 'w', 'COLLECT', ['message' => 'Please type the number:', 'var' => 'number', 'next' => 'r']],
            ['r', 'q', 'MESSAGE', ['message' => 'Thank you. We received {number}.']]]],
        'message' => ['One message', 'Shows one message and ends (an information code).', [
            ['w', null, 'WELCOME', ['next' => 'x']],
            ['x', 'w', 'MESSAGE', ['message' => 'Welcome to Comium. Thank you for your call.']]]],
    ];
}
function mobius_starter_steps(string $key, string $menuId): array {
    $st = mobius_starters()[$key] ?? throw new RuntimeException('Choose how the menu starts.');
    $ids = []; foreach ($st[2] as [$k]) $ids[$k] = mobius_new_oid(); $out = [];
    foreach ($st[2] as [$k, $p, $type, $f]) {
        $x = array_fill_keys(MOBIUS_STEP_FIELDS, null); $x['menuID'] = mobius_oid($menuId); $x['parentID'] = $p ? mobius_oid($ids[$p]) : null; $x['stepType'] = $type; $x['_id'] = mobius_oid($ids[$k]);
        if (isset($f['next'])) $x['nextStepID'] = mobius_oid($ids[$f['next']]);
        if (isset($f['code'])) $x['localCode'] = $f['code'];
        if (isset($f['message'])) { $x['message'] = $f['message']; $x['language'] = 'DEFAULT'; }
        if (isset($f['errorMessage'])) $x['errorMessage'] = $f['errorMessage'];
        if (isset($f['var'])) $x['properties'] = [['propertyName' => $f['var'], 'propertyValue' => null, 'defaultValue' => null]];
        $out[] = $x;
    }
    return $out;
}
// The steps of another menu, for a new menu: new ids, same links, same everything else (service headers included; they stay on the server).
function mobius_copied_steps(array $cfg, string $fromMenuId, string $menuId): array {
    $src = mobius_steps_or_fail($cfg, $fromMenuId); if (!$src['steps']) throw new RuntimeException('That menu has no steps to copy.');
    $map = []; foreach ($src['raw'] as $r) $map[mobius_id_text($r['_id'] ?? '')] = mobius_new_oid(); $out = [];
    foreach ($src['raw'] as $r) { $x = $r; $x['_id'] = mobius_oid($map[mobius_id_text($r['_id'] ?? '')]); $x['menuID'] = mobius_oid($menuId);
        foreach (['parentID', 'nextStepID'] as $k) { $t = mobius_id_text($x[$k] ?? ''); if ($t !== '' && isset($map[$t])) $x[$k] = mobius_oid($map[$t]); } $out[] = $x; }
    return $out;
}
// A new MS_INITIATED menu in Mobius with its first steps. Returns ['menu' => …, 'steps' => true|false, 'note' => why the steps could not be made].
function mobius_create_built(array $cfg, string $name, string $code, string $starter, string $copyFrom = ''): array {
    $l = mobius_list_menus($cfg); if (!$l['ok']) throw new RuntimeException('Could not read Mobius: '.$l['error']);
    $dest = []; $count = []; foreach ($l['menus'] as $m) if ($m['shortcodeType'] === 'MS_INITIATED') foreach ($m['destinationID'] as $d) $count[$d] = ($count[$d] ?? 0) + 1;
    if ($count) { arsort($count); $dest = [array_key_first($count)]; } // the destination most Mobius-built menus use
    $r = mobius_save_menu($cfg, null, ['name' => $name, 'shortcode' => $code, 'shortcodeType' => 'MS_INITIATED', 'remoteURL' => '', 'destinationID' => $dest], $code, 'create');
    $menu = $r['menu']; if (!$menu || $menu['menuID'] === '') return ['menu' => $menu, 'steps' => false, 'note' => 'Mobius created the menu but the portal could not read it back.'];
    $_SESSION['mobius_unlock'][(string)$menu['menuID']] = time() + 1800; // just made by this user: editing is on
    if ($starter === 'empty') return ['menu' => $menu, 'steps' => false, 'note' => ''];
    try {
        $steps = $starter === 'copy' ? mobius_copied_steps($cfg, $copyFrom, (string)$menu['menuID']) : mobius_starter_steps($starter, (string)$menu['menuID']);
        mobius_steps_create($cfg, $steps, []);
        $hid = mobius_record_change((string)$menu['menuID'], (string)$menu['shortcode'], 'step-add', null, null, 'Added '.count($steps).' first steps ('.($starter === 'copy' ? 'copied' : (mobius_starters()[$starter][0] ?? $starter)).')');
        mobius_step_backup_put($hid, ['step' => mobius_id_text($steps[0]['_id'] ?? ''), 'how' => 'first', 'anchor' => '']);
        return ['menu' => $menu, 'steps' => true, 'note' => ''];
    } catch (RuntimeException $e) { return ['menu' => $menu, 'steps' => false, 'note' => $e->getMessage()]; }
}
// Who already uses a short code: ['portal' => bool, 'mobius' => menu|null].
function ussd_code_taken(string $code, array $mobiusMenus): array {
    $n = menu_normalize_shortcode($code); $portal = in_array($n, menu_shortcodes(), true);
    if (!$portal) { $r = portal_pdo()->prepare("SELECT 1 FROM portal_short_codes WHERE channel_type='USSD' AND short_code=?"); $r->execute([$n]); $portal = (bool)$r->fetch(); }
    $mob = null; foreach ($mobiusMenus as $m) if (trim((string)$m['shortcode']) !== '' && mobius_bare_code((string)$m['shortcode']) === mobius_bare_code($n)) $mob = $m;
    return ['portal' => $portal, 'mobius' => $mob];
}

// For the export buttons: every menu exactly as Mobius gave it.
function mobius_menus_export(array $menus): array { return array_map(fn($m) => mobius_menu_norm($m)['_raw'], $menus); }

// Rename a menu in the portal: its short code (every item, its history, its register entry and the proxy setting) and its name.
function menu_update_identity(string $old, string $newCode, string $newName): void {
    $newCode = menu_normalize_shortcode($newCode); $newName = trim($newName);
    if (!menu_valid_shortcode($newCode)) throw new RuntimeException('A short code looks like *9606*7070# — a *, then numbers (more *numbers if you like), ending with #.');
    if ($newName === '' || mb_strlen($newName) > 150) throw new RuntimeException('Give the menu a name (up to 150 letters).');
    menu_versions_table(); $db = portal_pdo();
    $taken = function (string $c) use ($db): bool { $r = $db->prepare("SELECT 1 FROM portal_short_codes WHERE channel_type='USSD' AND short_code=?"); $r->execute([$c]); return in_array($c, menu_shortcodes(), true) || (bool)$r->fetch(); };
    $oldKnown = $taken($old); if (!$oldKnown) throw new RuntimeException($old.' is not a menu of this portal.');
    $move = $newCode !== $old; if ($move && $taken($newCode)) throw new RuntimeException($newCode.' already exists — pick another short code.');
    $db->beginTransaction();
    try {
        if ($move) {
            foreach (['ussd_menu_nodes', 'ussd_menu_versions'] as $t) $db->prepare("UPDATE `$t` SET short_code=? WHERE short_code=?")->execute([$newCode, $old]);
            $db->prepare("UPDATE portal_short_codes SET short_code=? WHERE channel_type='USSD' AND short_code=?")->execute([$newCode, $old]);
            $cfg = ussd_proxy_config(); $set = []; foreach (['shortcode_proxy', 'shortcode_ms_initiated'] as $k) if ($cfg[$k] === $old) $set[$k] = $newCode; if ($set) ussd_proxy_set($set);
        }
        $has = $db->prepare("SELECT id FROM portal_short_codes WHERE channel_type='USSD' AND short_code=?"); $has->execute([$newCode]); $id = $has->fetchColumn();
        if ($id) $db->prepare('UPDATE portal_short_codes SET service_name=? WHERE id=?')->execute([$newName, $id]);
        else $db->prepare("INSERT INTO portal_short_codes(channel_type,short_code,service_name,provider,status,description) VALUES('USSD',?,?,'Comium','Pending','Named from the Menus page')")->execute([$newCode, $newName]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit('rename_menu', null, 'ussd_menu_nodes', $newCode, json_encode(['from' => $old, 'name' => $newName]));
    if ($move) { try { menu_snapshot($newCode, 'Short code changed from '.$old); } catch (Throwable $e) {} }
}
// Read-only: logs in, then makes harmless read calls in the ways Mobius might want the session presented, and shows
// what it says to each (session ids are cut to their last 4 characters, the password hash is never shown).
function mobius_probe(array $cfg): array {
    if ($cfg['mobius_user'] === '' || decrypt_secret($cfg['mobius_pass']) === '') return [['label' => 'Setup', 'ok' => false, 'msg' => 'Enter the Mobius API user name and password first.']];
    $bases = mobius_bases($cfg); if (!$bases) return [['label' => 'Setup', 'ok' => false, 'msg' => 'No valid Mobius address saved.']];
    $out = [];
    foreach ($bases as $base) {
        $rows = mobius_probe_base($cfg, $base);
        if (count($bases) > 1) foreach ($rows as &$r) $r['label'] = parse_url($base, PHP_URL_HOST).' · '.$r['label'];
        unset($r); $out = array_merge($out, $rows);
    }
    return $out;
}
function mobius_probe_base(array $cfg, string $base): array {
    $out = []; $hash = decrypt_secret($cfg['mobius_pass']); $u = $cfg['mobius_user'];
    $ch = mobius_handle(); $l = mobius_http($base.'auth/login', ['username' => $u, 'password' => $hash], 5, $ch);
    $out[] = ['label' => 'Login (POST)', 'ok' => mobius_probe_ok($l), 'msg' => mobius_brief($l)];
    $sid = (string)($l['json']['sessionID'] ?? '');
    if ($sid !== '') {
        foreach (['ussdmenues/count' => 'Read: menus count', 'ussdcalls/count' => 'Read: calls count', 'auditlog/count' => 'Read: audit log count (management)'] as $path => $label) {
            $r = mobius_http($base.$path, array_merge($path === 'ussdcalls/count' ? ['msisdn' => ''] : [], ['sessionID' => $sid, 'username' => $u]), 5, $ch);
            $out[] = ['label' => $label, 'ok' => mobius_probe_ok($r), 'msg' => mobius_brief($r)];
        }
        foreach (['Authorization: '.$sid => 'Header Authorization', 'sessionID: '.$sid => 'Header sessionID'] as $hdr => $label) {
            $c2 = mobius_handle(); curl_setopt($c2, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', $hdr]);
            $r = mobius_http($base.'ussdmenues/count', ['sessionID' => $sid, 'username' => $u], 5, $c2); curl_close($c2);
            $out[] = ['label' => 'Read: menus count + '.$label, 'ok' => mobius_probe_ok($r), 'msg' => mobius_brief($r)];
        }
    }
    curl_close($ch);
    // GET form of the login, then a read with that session
    $g = mobius_http($base.'auth/login/'.rawurlencode($u).'/'.rawurlencode($hash), [], 5, null, 'GET');
    $out[] = ['label' => 'Login (GET form)', 'ok' => mobius_probe_ok($g), 'msg' => mobius_brief($g)];
    if (!empty($g['json']['sessionID'])) { $r = mobius_http($base.'ussdmenues/count', ['sessionID' => $g['json']['sessionID'], 'username' => $u]); $out[] = ['label' => 'Read: menus count with the GET-login session', 'ok' => mobius_probe_ok($r), 'msg' => mobius_brief($r)]; }
    return $out;
}
function mobius_test(array $cfg): array {
    $out = []; $hash = decrypt_secret($cfg['mobius_pass']);
    if ($cfg['mobius_user'] === '' || $hash === '') return [['base' => '', 'ok' => false, 'msg' => 'Enter the Mobius API user name and password first.']];
    foreach (mobius_bases($cfg) as $base) { $t = microtime(true); $r = mobius_login_at($base, $cfg['mobius_user'], $hash); $out[] = ['base' => $base, 'ok' => $r['ok'], 'msg' => $r['ok'] ? 'Logged in ('.round((microtime(true) - $t) * 1000).' ms).' : $r['error']]; }
    return $out ?: [['base' => '', 'ok' => false, 'msg' => 'No valid Mobius address saved.']];
}
function save_mobius_config(array $d): void {
    $cfg = ussd_proxy_config();
    $bases = []; foreach (preg_split('/[\s,;]+/', trim((string)($d['mobius_base'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $x) $bases[] = mobius_norm_base($x);
    if (!$bases) throw new RuntimeException('Enter at least one Mobius address.');
    if (count($bases) > 4) throw new RuntimeException('At most 4 Mobius addresses.');
    $user = trim((string)($d['mobius_user'] ?? ''));
    if ($user !== '' && !preg_match('/^[A-Za-z0-9._@-]{1,80}$/', $user)) throw new RuntimeException('The API user name may only contain letters, digits and . _ @ -');
    $vals = ['push_enabled' => empty($d['push_enabled']) ? '0' : '1', 'mobius_base' => implode(',', array_unique($bases)), 'mobius_user' => $user];
    $pass = (string)($d['mobius_pass'] ?? '');
    // Mobius wants the MD5 of the password; only that hash is kept, encrypted, and the plain password is never stored.
    if ($pass !== '') $vals['mobius_pass'] = encrypt_secret(md5($pass));
    if ($pass !== '' || $user !== $cfg['mobius_user'] || $vals['mobius_base'] !== $cfg['mobius_base']) { $vals['mobius_session'] = ''; $vals['mobius_variant'] = '0'; }
    ussd_proxy_set($vals);
    audit('ussd_proxy_mobius_save', null, 'ussd_proxy_config', null, 'push='.$vals['push_enabled'].' user='.$user.' password_changed='.($pass !== '' ? 'yes' : 'no'));
}
// PROXY menus: Mobius posts each reply here; the screen goes back through Mobius's REST API, so we acknowledge first
// (keeping the HTTP exchange short) and push afterwards. Session state is the replies so far, keyed by callID.
function ussd_proxy_push_endpoint(array $cfg, array $flat, string $raw, array $get, string $ip, float $t0): never {
    ignore_user_abort(true); @set_time_limit(40);
    $callId = trim((string)($flat['callID'] ?? '')); $msisdn = (string)($flat['msisdn'] ?? '');
    $initial = ($flat['isInitial'] ?? '') === 'true'; $ended = ($flat['isComplete'] ?? '') === 'true'; $typed = trim((string)($flat['request'] ?? ''));
    $note = ''; $text = null; $complete = false; $sc = $cfg['shortcode_proxy'];
    // Serve whichever short code was dialled, as long as it has a menu in the Menu Builder; the code saved on the
    // USSD Proxy page is only the fallback. So several PROXY menus in Mobius can share this one address.
    // A direct code such as *9606*9090*2*1# is the short code followed by the choices to make: the extra digits are typed for the customer.
    $dialled = trim((string)($flat['originalRequest'] ?? '')); $extras = [];
    try { $res = ussd_resolve_dialled($dialled, menu_shortcodes()); if ($res) { [$dc, $extras] = $res; if ($dc !== $sc) { $sc = $dc; $cfg['shortcode_proxy'] = $sc; } } } catch (Throwable $e) {}
    try {
        $db = portal_pdo();
        if ($callId === '') $note = 'no callID in request';
        elseif ($ended && $typed === '') { $db->prepare('DELETE FROM ussd_proxy_sessions WHERE session_key=?')->execute([$callId]); $note = 'dialog finished (nothing to send)'; }
        else {
            $replies = []; $fresh = $initial;
            if (!$initial) {
                $st = $db->prepare('SELECT replies FROM ussd_proxy_sessions WHERE session_key=? AND shortcode=? AND updated_at >= NOW() - INTERVAL '.(int)$cfg['session_ttl'].' SECOND');
                $st->execute([$callId, $sc]); $row = $st->fetchColumn();
                if ($row !== false) { $replies = json_decode((string)$row, true) ?: []; if ($typed !== '') $replies[] = $typed; } else { $fresh = true; $note = 'session not found - restarted from the first screen; '; }
            }
            if ($fresh && $extras) { $replies = $extras; $note .= 'direct code: '.implode('*', $extras).'; '; }
            $hk = ['seed' => $callId, 'msisdn' => $msisdn, 'ctx' => ussd_purchase_ctx($raw)]; $mkScreen = fn() => ussd_screen($sc, array_slice($replies, -30), ['active'], null, $hk);
            $screen = $mkScreen();
            for ($fi = 0; $fi < 5 && ($screen['kind'] ?? '') === 'flow_call'; $fi++) { flow_execute_call(flow_get($screen['flow_key']), $screen['flow_pending'], $callId, $msisdn, $hk['ctx'], $sc); $screen = $mkScreen(); }
            ussd_quiz_record($screen, $msisdn, $callId);
            if (in_array($screen['kind'] ?? '', ['share_subscribe', 'share_add'], true)) { $sr = ussd_share_execute($cfg, $screen, $msisdn, $callId, ussd_purchase_ctx($raw)); $screen['text'] = $sr['text']; $note .= $sr['note'].'; '; }
            if ($screen['end']) $db->prepare('DELETE FROM ussd_proxy_sessions WHERE session_key=?')->execute([$callId]);
            else $db->prepare('REPLACE INTO ussd_proxy_sessions(session_key,shortcode,replies,updated_at) VALUES(?,?,?,NOW())')->execute([$callId, $sc, json_encode($replies)]);
            if (($screen['kind'] ?? '') === 'purchase') { $pr = ussd_execute_purchase($cfg, $screen, $msisdn, $callId, ussd_purchase_ctx($raw)); $screen['text'] = $pr['text']; $note .= $pr['note'].'; '; }
            $text = $screen['text']; $complete = $screen['end'];
        }
    } catch (Throwable $e) { error_log('ussd.php push: '.$e->getMessage()); $note = 'error: '.substr($e->getMessage(), 0, 150); $text = null; }
    $ack = (string)json_encode(['callID' => $callId, 'msisdn' => $msisdn], JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json'); header('Cache-Control: no-store'); header('Connection: close'); header('Content-Length: '.strlen($ack));
    echo $ack;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { while (ob_get_level() > 0) @ob_end_flush(); flush(); }
    if ($text !== null) {
        $res = mobius_push($cfg, $callId, $text, $complete);
        $note .= $res['ok'] ? 'pushed to Mobius'.($complete ? ' (final screen)' : '').' ['.$res['variant'].']' : 'PUSH FAILED: '.$res['error'];
    }
    ussd_proxy_write_log('proxy', $ip, $get, $raw, $text ?? $ack, (int)round((microtime(true) - $t0) * 1000), $note);
    exit;
}
function ussd_proxy_endpoint(): never {
    $t0 = microtime(true); $cfg = ussd_proxy_config();
    $mode = ['proxy' => 'proxy', 'ms' => 'ms_initiated', 'ms_initiated' => 'ms_initiated'][(string)($_GET['m'] ?? '')] ?? null;
    $ip = client_ip(false);
    $ok = $cfg['enabled'] === '1' && $cfg['token'] !== '' && hash_equals($cfg['token'], (string)($_GET['t'] ?? '')) && $mode !== null;
    try { $ok = $ok && ussd_proxy_ip_allowed($cfg['allow_ips'], $ip); } catch (Throwable $e) { $ok = false; }
    if (!$ok) { http_response_code(404); header('Content-Type: text/plain'); exit('Not found'); }
    menu_apply_schedules_tick(); // scheduled items go live / switch off on time, even when nobody is using the portal
    $raw = (string)file_get_contents('php://input', false, null, 0, 65536);
    $get = $_GET; unset($get['t'], $get['m']);
    $flat = ussd_proxy_flatten((string)($_SERVER['CONTENT_TYPE'] ?? ''), $raw, $get, $_POST);
    if ($mode === 'proxy' && $cfg['mode'] === 'live' && $cfg['push_enabled'] === '1') ussd_proxy_push_endpoint($cfg, $flat, $raw, $get, $ip, $t0);
    try { $res = ussd_proxy_process($cfg, $mode, $flat); }
    catch (Throwable $e) { error_log('ussd.php: '.$e->getMessage()); $res = ['ctype' => 'text/plain; charset=UTF-8', 'body' => 'Service temporarily unavailable. Please try again.', 'note' => 'error: '.substr($e->getMessage(), 0, 150)]; }
    ussd_proxy_write_log($mode, $ip, $get, $raw, $res['body'], (int)round((microtime(true) - $t0) * 1000), $res['note'] ?? '');
    header('Content-Type: '.$res['ctype']); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    echo $res['body']; exit;
}
