<?php
declare(strict_types=1);
// System Status.

// ===================== System status =====================
// One look at whether everything the USSD service depends on is working. Read-only: it only connects and reads
// (the Mobius check is a login, nothing is sent to a customer or to Hera).
function system_status(): array {
    $row = fn(string $label, string $state, string $detail, ?string $link = null) => ['label' => $label, 'state' => $state, 'detail' => $detail, 'link' => $link];
    $ms = fn(float $t0) => (int)round((microtime(true) - $t0) * 1000).' ms';
    $tcp = function (string $url) use ($ms): array {
        $p = parse_url($url); $host = $p['host'] ?? ''; $port = (int)($p['port'] ?? (($p['scheme'] ?? '') === 'https' ? 443 : 80));
        if ($host === '') return [false, 'no address saved'];
        $t0 = microtime(true); $c = @fsockopen($host, $port, $en, $es, 3);
        if (!$c) return [false, (trim((string)$es) ?: 'cannot connect').' ('.$host.':'.$port.')']; fclose($c);
        return [true, $host.' answers in '.$ms($t0)];
    };
    $age = function (?string $ts): string { if (!$ts) return 'never'; $d = time() - strtotime($ts); return $d < 90 ? $d.' s ago' : ($d < 5400 ? round($d / 60).' min ago' : ($d < 172800 ? round($d / 3600).' h ago' : round($d / 86400).' days ago')); };
    $cfg = ussd_proxy_config(); $out = [];

    $sys = [$row('Version', 'info', substr((string)(getenv('APP_VERSION') ?: 'dev'), 0, 12)), $row('PHP', 'info', PHP_VERSION),
        $row('Your address', 'info', client_ip().' (connection '.(string)($_SERVER['REMOTE_ADDR'] ?? '?').', forwarded '.((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') ?: 'none').') — what login lock-out and the audit trail record for you')];
    try { $t0 = microtime(true); portal_pdo()->query('SELECT 1'); $sys[] = $row('Portal database', 'ok', 'answers in '.$ms($t0)); } catch (Throwable $e) { $sys[] = $row('Portal database', 'bad', 'not reachable'); }
    foreach ((array)app_config('allowed_schemas') as $s) {
        try { $t0 = microtime(true); pdo($s)->query('SELECT 1'); $sys[] = $row('Database '.$s, 'ok', 'answers in '.$ms($t0).(is_protected_schema($s) ? ' · live data, read-only here' : '')); }
        catch (Throwable $e) { $sys[] = $row('Database '.$s, 'warn', 'not reachable from the portal'); }
    }
    $out[] = ['title' => 'System', 'rows' => $sys];

    $conn = [];
    [$ok, $d] = $tcp((string)$cfg['purchase_url']); $conn[] = $row('Hera (purchases)', $ok ? 'ok' : 'bad', $d, '?page=ussd_proxy');
    if (parse_url((string)$cfg['share_base'], PHP_URL_HOST) !== parse_url((string)$cfg['purchase_url'], PHP_URL_HOST)) { [$ok, $d] = $tcp((string)$cfg['share_base']); $conn[] = $row('Hera (Shared Bundle)', $ok ? 'ok' : 'bad', $d, '?page=ussd_proxy'); }
    if ($cfg['mobius_user'] === '' || $cfg['mobius_pass'] === '') $conn[] = $row('Mobius', 'warn', 'API user not set up', '?page=ussd_proxy');
    else foreach (mobius_test($cfg) as $m) $conn[] = $row('Mobius '.(parse_url($m['base'], PHP_URL_HOST) ?: ''), $m['ok'] ? 'ok' : 'bad', $m['msg'], '?page=ussd_proxy');
    $out[] = ['title' => 'Connections', 'rows' => $conn];

    $u = [];
    $live = $cfg['enabled'] === '1' && $cfg['mode'] === 'live' && $cfg['push_enabled'] === '1';
    $u[] = $row('USSD endpoint', $live ? 'ok' : 'warn', $cfg['enabled'] !== '1' ? 'switched off' : ($cfg['mode'] !== 'live' ? 'in Capture mode (not serving menus)' : ($cfg['push_enabled'] !== '1' ? 'answering is switched off' : 'on, Live, answering through Mobius')), '?page=ussd_proxy');
    try {
        $db = portal_pdo();
        $r = $db->query("SELECT COUNT(*) n, SUM(note LIKE 'PUSH FAILED%') f, ROUND(AVG(ms)) a, MAX(created_at) last FROM ussd_proxy_log WHERE created_at >= NOW() - INTERVAL 1 HOUR")->fetch();
        $lastAny = $db->query('SELECT MAX(created_at) FROM ussd_proxy_log')->fetchColumn();
        $u[] = $row('Requests, last hour', 'info', (int)$r['n'].' (last one '.$age($lastAny ?: null).')'.((int)$r['n'] ? ' · average '.(int)$r['a'].' ms' : ''));
        $u[] = $row('Screens that failed to reach Mobius', (int)$r['f'] > 0 ? 'bad' : 'ok', (int)$r['f'] > 0 ? (int)$r['f'].' in the last hour — see the captured requests' : 'none in the last hour', '?page=ussd_proxy');
        ussd_purchase_table();
        $st = $db->query("SELECT status, COUNT(*) n FROM ussd_purchases WHERE created_at >= NOW() - INTERVAL 1 DAY GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $parts = []; foreach ($st as $k => $n) $parts[] = $k.' '.$n;
        $u[] = $row('Purchases, last 24 h', (($st['failed'] ?? 0) > 0) ? 'warn' : 'info', $parts ? implode(' · ', $parts) : 'none', '?page=ussd_proxy');
        ussd_quiz_tables(); $u[] = $row('Quiz games, last 24 h', 'info', (string)(int)$db->query("SELECT COUNT(*) FROM ussd_quiz_plays WHERE created_at >= NOW() - INTERVAL 1 DAY")->fetchColumn(), '?page=ussd_quiz');
    } catch (Throwable $e) { $u[] = $row('USSD activity', 'warn', 'could not be read'); }
    $out[] = ['title' => 'USSD service', 'rows' => $u];

    $m = [];
    try {
        foreach (menu_shortcodes() as $sc) {
            $h = menu_health($sc); $e = count(array_filter($h, fn($x) => $x['level'] === 'error')); $w = count(array_filter($h, fn($x) => $x['level'] === 'warn'));
            $m[] = $row('Menu '.$sc, $e ? 'bad' : ($w ? 'warn' : 'ok'), $e ? $e.' to fix, '.$w.' to look at' : ($w ? $w.' to look at' : 'all good'), '?page=ussd_menu&short_code='.urlencode($sc));
        }
    } catch (Throwable $e) {}
    $out[] = ['title' => 'Menus', 'rows' => $m ?: [$row('Menus', 'info', 'no short codes yet')]];

    // secrets: are they set, and when were they last changed (from the audit trail); older than 90 days is flagged
    $last = function (array $actions) { try { $in = implode(',', array_fill(0, count($actions), '?')); $st = portal_pdo()->prepare("SELECT MAX(created_at) FROM portal_audit_trail WHERE action IN ($in)"); $st->execute($actions); return $st->fetchColumn() ?: null; } catch (Throwable $e) { return null; } };
    $sec = []; $secRow = function (string $label, bool $set, array $actions, string $link, string $how) use (&$sec, $row, $last, $age) {
        $ts = $last($actions); $old = $ts && time() - strtotime($ts) > 90 * 86400;
        $sec[] = $row($label, !$set ? 'warn' : (($old || !$ts) ? 'warn' : 'ok'), ($set ? 'set' : 'not set').' · last changed '.$age($ts).($set && (!$ts || $old) ? ' — rotate it ('.$how.')' : ''), $link);
    };
    $secRow('USSD endpoint token', ussd_proxy_token() !== '', ['ussd_proxy_token_rotated'], '?page=ussd_proxy', 'New token on the USSD Proxy page, then update the URL in Mobius');
    $secRow('Mobius API password', $cfg['mobius_pass'] !== '', ['ussd_proxy_mobius_save'], '?page=ussd_proxy', 'use a dedicated Mobius API user');
    $secRow('Hera headers (API key)', $cfg['purchase_auth'] !== '', ['ussd_purchase_save'], '?page=ussd_proxy', 'ask the Hera owner for a new key, enter it on the purchase card');
    $secRow('Alert cron token', true, ['alert_cron_token_rotated'], '?page=alert_settings', 'Alert Settings, rotate, then update the CronJob');
    $out[] = ['title' => 'Secrets (rotate at least every 90 days)', 'rows' => $sec];
    return $out;
}
