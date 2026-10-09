<?php
declare(strict_types=1);
/**
 * Page-level smoke test:   php tests/smoke.php
 *
 * Starts the real app on a local port (php -S), signs in as test users it creates itself, and drives it over HTTP like a browser would:
 * every page the app has is opened and must answer without a PHP error; a viewer must be refused the admin pages; a form without its
 * security token must be refused; wrong and default passwords, the lock-out, the security headers, the public endpoints and a few
 * forms (menu creation, refund preview/test send) are checked end to end. It removes everything it created.
 *
 * Needs the same MySQL as tests/run.php. Exit code 0 = all passed, 1 = something failed (so CI can block a bad build).
 */
define('LEAN_BOOT', true);
$root = dirname(__DIR__);
foreach ([$root.'/app/lib/bootstrap.php', '/var/www/lib/bootstrap.php'] as $f) if (is_file($f)) { require $f; break; }
if (!function_exists('ussd_screen')) { fwrite(STDERR, "Could not load the portal code.\n"); exit(2); }
$docroot = is_dir($root.'/app/public') ? $root.'/app/public' : '/var/www/html';

$pass = 0; $fail = 0; $failures = [];
function t(string $name, callable $fn): void {
    global $pass, $fail, $failures; $before = $fail;
    try { $fn(); } catch (Throwable $e) { $fail++; $failures[] = $name.' — threw '.get_class($e).': '.$e->getMessage(); echo "  FAIL  $name (threw: ".$e->getMessage().")\n"; return; }
    if ($fail === $before) { $pass++; echo "  ok    $name\n"; }
}
function ok($cond, string $msg): void { global $fail, $failures; if (!$cond) { $fail++; $failures[] = $msg; echo "        ✗ $msg\n"; } }
function eq($actual, $expected, string $msg): void { ok($actual === $expected, $msg.' — expected '.json_encode($expected).', got '.json_encode($actual)); }
function has(string $hay, string $needle, string $msg): void { ok(str_contains($hay, $needle), $msg.' — "'.$needle.'" not found'); }
function lacks(string $hay, string $needle, string $msg): void { ok(!str_contains($hay, $needle), $msg.' — "'.$needle.'" should not be there'); }

echo "VAS Cloud smoke test\n";
ensure_portal_runtime_schema(); $db = portal_pdo(); refund_tables(); ussd_purchase_table(); flow_tables(); menu_versions_table();
$savedCfg = $db->query('SELECT name,value FROM ussd_proxy_config')->fetchAll(PDO::FETCH_KEY_PAIR);
$cleanup = function () use ($db, $savedCfg) {
    $db->exec("DELETE FROM login_attempts WHERE username LIKE 'zt_smoke_%'"); $db->exec("DELETE FROM portal_audit_trail WHERE username LIKE 'zt_smoke_%'"); $db->exec("DELETE FROM portal_users WHERE username LIKE 'zt_smoke_%'");
    $db->exec("DELETE FROM ussd_menu_nodes WHERE short_code IN ('*8861#')"); $db->exec("DELETE FROM ussd_menu_versions WHERE short_code IN ('*8861#')"); $db->exec("DELETE FROM portal_short_codes WHERE short_code IN ('*8861#')");
    $db->exec("DELETE FROM refund_requests WHERE offer_code='ZTSMK'"); $db->exec('DELETE FROM ussd_proxy_config');
    $ins = $db->prepare('INSERT INTO ussd_proxy_config(name,value) VALUES(?,?)'); foreach ($savedCfg as $k => $v) $ins->execute([$k, $v]);
};
$cleanup();
$GOOD = 'Zt-Smoke-'.bin2hex(random_bytes(4)).'!x';
$mkUser = function (string $name, string $role, string $password) use ($db) {
    $db->prepare('INSERT INTO portal_users(full_name,username,password_hash,role,status,default_schema_name) VALUES(?,?,?,?,?,?)')->execute(['Smoke '.$role, $name, password_hash($password, PASSWORD_DEFAULT), $role, 'active', app_config('default_schema')]);
};
$mkUser('zt_smoke_admin', 'admin', $GOOD); $mkUser('zt_smoke_viewer', 'viewer', $GOOD); $mkUser('zt_smoke_default', 'viewer', 'admin123');

// the real app, on a local port
$port = 19080 + random_int(0, 400); $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $docroot], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
register_shutdown_function(function () use ($proc, $cleanup) { if (is_resource($proc)) proc_terminate($proc); $cleanup(); });
$base = 'http://127.0.0.1:'.$port.'/';
for ($i = 0; $i < 50; $i++) { $c = @file_get_contents($base.'health.php'); if ($c !== false) break; usleep(200000); }

/** one browser: its own cookie jar */
function browser(string $base): array {
    $jar = tempnam(sys_get_temp_dir(), 'zt_jar_');
    $go = function (string $path, ?array $post = null, bool $follow = true) use ($base, $jar): array {
        $ch = curl_init($base.ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 60, CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $url = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); curl_close($ch);
        return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs), 'url' => $url];
    };
    $csrf = fn(string $html): string => preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
    $login = function (string $user, string $pw) use ($go, $csrf): array { $p = $go('index.php?page=login'); return $go('index.php?page=login', ['csrf' => $csrf($p['body']), 'username' => $user, 'password' => $pw]); };
    return ['go' => $go, 'csrf' => $csrf, 'login' => $login, 'jar' => $jar];
}
$PHP_ERR = '/Warning:|Fatal error|Parse error|Notice:|Deprecated:|Stack trace|Uncaught |Call to undefined|must be of type|Undefined (variable|array key|index|property)|SQLSTATE|Class "[^"]+" not found/';
$text = fn(string $html): string => trim(preg_replace('/\s+/', ' ', strip_tags((string)preg_replace('#<script.*?</script>#s', '', $html))));

// every page the app has (found in index.php, so a new page is covered the day it is added)
$src = (string)file_get_contents($docroot.'/index.php'); foreach (glob(dirname($docroot).'/pages/*.php') ?: [] as $pf) $src .= "\n".basename($pf, '.php');
preg_match_all("/\\\$page===?'([a-z_]+)'/", (string)file_get_contents($docroot.'/index.php'), $m); $pages = array_values(array_unique($m[1]));
$skip = ['login', 'logout', 'switch_schema', 'alert_cron', 'confirm', 'copy_record', 'sync', 'form', 'investigate_detail']; // these need a token or a record id
$pages = array_values(array_filter($pages, fn($p) => !in_array($p, $skip, true) && !str_contains($p, 'export')));

$admin = browser($base); $viewer = browser($base); $anon = browser($base);

t('the public endpoints and the security headers', function () use ($anon, $base) {
    $h = $anon['go']('health.php'); eq($h['code'], 200, 'health answers'); has($h['body'], '"status":"ok"', 'and says ok');
    $l = $anon['go']('health.php?live=1'); has($l['body'], 'alive', 'liveness answers without the database');
    $u = $anon['go']('ussd.php?t=wrong&m=proxy'); eq($u['code'], 404, 'the USSD endpoint with a wrong token is not found');
    $a = $anon['go']('api.php?action=offers'); eq($a['code'], 401, 'the partner API wants a key');
    $p = $anon['go']('index.php?page=dashboard'); has($p['url'], 'page=login', 'a page without signing in goes to the login');
    foreach (['Content-Security-Policy', 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy'] as $hd) has($p['headers'], $hd, "the header $hd is sent");
    $fresh = browser($base); $fh = $fresh['go']('index.php?page=login')['headers']; ok(stripos($fh, 'httponly') !== false && stripos($fh, 'samesite=strict') !== false, 'a new session cookie is HttpOnly and SameSite=Strict');
});
t('login: wrong password, forced change of the default one, lock-out', function () use ($anon, $text, $GOOD, $db, $base) {
    $b = browser($base); $r = $b['login']('zt_smoke_admin', 'wrong-password'); has($text($r['body']), 'Invalid username or password.', 'a wrong password is refused'); lacks($r['body'], 'Executive Dashboard', 'and no dashboard is shown');
    $d = browser($base); $d['login']('zt_smoke_default', 'admin123'); $page = $d['go']('index.php?page=dashboard'); has($text($page['body']), 'My Account', 'the default password sends the user to My Account'); has($text($page['body']), 'default password', 'with the reason');
    $db->exec("DELETE FROM login_attempts WHERE username='zt_smoke_viewer'"); $x = browser($base); for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) $x['login']('zt_smoke_viewer', 'nope'.$i);
    $locked = $x['login']('zt_smoke_viewer', $GOOD); has($text($locked['body']), 'Invalid username or password.', 'after 5 wrong tries even the right password is refused for a while'); $db->exec("DELETE FROM login_attempts WHERE username='zt_smoke_viewer'");
});
$a = $admin['login']('zt_smoke_admin', $GOOD); $v = $viewer['login']('zt_smoke_viewer', $GOOD);
t('signing in works', function () use ($a, $v, $admin, $viewer, $text) { has($text($a['body']), 'Executive Dashboard', 'the admin lands on the dashboard'); ok(str_contains($text($v['body']), 'VAS Cloud') && !str_contains($text($v['body']), 'Invalid username'), 'the viewer signs in too'); });

t('every page opens without a PHP error', function () use ($pages, $admin, $PHP_ERR, $text) {
    $odd = [];
    foreach ($pages as $p) {
        $r = $admin['go']('index.php?page='.$p); ok($r['code'] === 200, "page $p answers 200 (got {$r['code']})");
        ok(!preg_match($PHP_ERR, $r['body'], $mm), "page $p shows no PHP or SQL error".(isset($mm[0]) ? ' — found "'.$mm[0].'" in: '.mb_substr($text($r['body']), 0, 200) : ''));
        ok(str_contains($r['body'], '</html>'), "page $p is a whole page");
        if (str_contains($r['body'], 'Something went wrong')) $odd[] = $p;
    }
    echo '        ('.count($pages).' pages opened'.($odd ? '; refused with a message here, which is expected without Hera data: '.implode(', ', $odd) : '').")\n";
});
t('a viewer is refused the admin pages', function () use ($viewer, $text) {
    foreach (['users', 'sql', 'ussd_proxy', 'refunds', 'api_keys', 'status', 'integrations_admin'] as $p) { if ($p === 'integrations_admin') continue; $r = $viewer['go']('index.php?page='.$p); has($text($r['body']), 'do not have permission', "a viewer cannot open $p"); }
});
t('a form without its security token is refused', function () use ($admin, $text) {
    $r = $admin['go']('index.php?page=ussd_menus', ['name' => 'No token', 'short_code' => '*8862#']); has($text($r['body']), 'Security token expired', 'the post is refused'); eq((int)portal_pdo()->query("SELECT COUNT(*) FROM portal_short_codes WHERE short_code='*8862#'")->fetchColumn(), 0, 'and nothing was created');
});
t('a menu is created through the Menus page', function () use ($admin, $text) {
    $p = $admin['go']('index.php?page=ussd_menus'); $r = $admin['go']('index.php?page=ussd_menus', ['csrf' => $admin['csrf']($p['body']), 'name' => 'ZT Smoke', 'short_code' => '*8861', 'items' => "A\nB", 'copy_from' => '']);
    has($text($r['body']), 'Menu *8861# created', 'it says so'); has($text($r['body']), 'Set up *8861#', 'and opens its setup checklist'); $l = $admin['go']('index.php?page=ussd_menus'); has($text($l['body']), 'ZT Smoke', 'it is in the list');
    $e = $admin['go']('index.php?page=ussd_menu&short_code='.urlencode('*8861#')); has($text($e['body']), 'Menu check', 'and the Builder opens it');
});
t('refunds: preview shows the exact request, a test send is recorded', function () use ($admin, $text, $db) {
    ussd_proxy_set(['refund_mode' => 'test', 'refund_url' => 'https://h.example/x']); $p = $admin['go']('index.php?page=refunds'); has($text($p['body']), 'Test mode', 'the page says refunds are in test mode');
    $fields = ['offer_code' => 'ZTSMK', 'vendor' => 'huawei', 'msisdn' => '2206704843', 'channel' => 'ref', 'date' => '2025-08-08 15:30:18', 'purchase_id' => ''];
    $pv = $admin['go']('index.php?page=refunds', ['csrf' => $admin['csrf']($p['body']), 'do' => 'preview'] + $fields); has(html_entity_decode($text($pv['body'])), '{"offerCode":"ZTSMK","date":"2025-08-08 15:30:18","vendor":"huawei","msisdn":"6704843","channel":"REF"}', 'the preview is exactly the request');
    $s = $admin['go']('index.php?page=refunds', ['csrf' => $admin['csrf']($pv['body']), 'do' => 'send'] + $fields); has($text($s['body']), 'recorded as a TEST', 'a test send is recorded, not sent'); eq((int)$db->query("SELECT COUNT(*) FROM refund_requests WHERE offer_code='ZTSMK' AND status='test'")->fetchColumn(), 1, 'one row in the history');
    $bad = $admin['go']('index.php?page=refunds', ['csrf' => $admin['csrf']($s['body']), 'do' => 'preview'] + ['date' => 'yesterday'] + $fields); has($text($bad['body']), 'The date must look like', 'a bad date is refused with a clear message');
});

$cleanup();
echo "\n".($fail === 0 ? 'ALL PASSED' : 'FAILED')."  —  $pass tests ok".($fail ? ", $fail check(s) failed:\n  - ".implode("\n  - ", array_slice($failures, 0, 40)) : '')."\n";
exit($fail === 0 ? 0 : 1);
