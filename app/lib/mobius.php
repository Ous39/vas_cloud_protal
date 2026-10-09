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
            if ($r['code'] === 200 && !$bad) { if ($variant !== $pref) { try { ussd_proxy_set(['mobius_variant' => (string)$variant]); } catch (Throwable $e) {} } return ['ok' => true, 'json' => (array)$r['json'], 'base' => $base]; }
            $errors[] = $base.' HTTP '.$r['code'].' '.($r['error'] !== '' ? $r['error'] : mb_substr((string)($r['json']['errorMessage'] ?? $r['raw']), 0, 160));
            if (!mobius_is_auth_error($r)) break;
        }
        if ($r['code'] > 0) break; // this node answered: do not ask the other one to do the same
    }
    return ['ok' => false, 'error' => $errors ? implode(' | ', array_slice($errors, -3)) : 'no Mobius address configured'];
}
// Every menu in Mobius: [['ok' => true, 'menus' => […], 'count' => N|null] | ['ok' => false, 'error' => …]]. Pages by key; duplicates are ignored.
function mobius_list_menus(array $cfg): array {
    if ($cfg['mobius_user'] === '' || decrypt_secret($cfg['mobius_pass']) === '') return ['ok' => false, 'error' => 'The Mobius API user and password are not saved yet (Proxy → Connection).'];
    $all = []; $first = null; $size = 100;
    for ($page = 0; $page < 30; $page++) {
        $r = mobius_rest($cfg, 'ussdmenues/list', ['pageSize' => $size, 'includePreviousKey' => false, 'reverse' => false] + ($first !== null ? ['firstKey' => $first] : []));
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
        $rows = array_values((array)($r['json']['data'] ?? [])); $new = 0;
        foreach ($rows as $m) { $id = (string)($m['menuID'] ?? ''); if ($id !== '' && !isset($all[$id])) { $all[$id] = $m; $new++; } }
        if (count($rows) < $size || $new === 0) break;
        $first = (string)($rows[count($rows) - 1]['menuID'] ?? ''); if ($first === '') break;
    }
    $c = mobius_rest($cfg, 'ussdmenues/count', []); $count = $c['ok'] && isset($c['json']['data']) && is_numeric($c['json']['data']) ? (int)$c['json']['data'] : null;
    return ['ok' => true, 'menus' => array_values($all), 'count' => $count];
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
    $data = ['name' => $name, 'shortcode' => $code, 'shortcodeType' => 'PROXY', 'isExtendable' => $extendable, 'remoteURL' => (string)$tpl['remoteURL'], 'destinationID' => array_values((array)($tpl['destinationID'] ?? []))];
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
    $data = $cur; $data['name'] = $name; $data['shortcode'] = $code; $data['isExtendable'] = $extendable;
    $r = mobius_rest($cfg, 'ussdmenues/set', ['data' => $data]); if (!$r['ok']) throw new RuntimeException('Mobius refused: '.$r['error']);
    audit('mobius_update_menu', null, 'mobius', $menuId, json_encode(['from' => $cur['shortcode'] ?? '', 'to' => $code, 'name' => $name, 'extendable' => $extendable]));
    $after = mobius_list_menus($cfg); $st = $after['ok'] ? mobius_menu_state($code, $after['menus'], $cfg) : ['state' => 'unknown', 'menu' => null];
    return ['state' => $st['state'], 'menu' => $st['menu']];
}
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
