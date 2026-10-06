<?php
declare(strict_types=1);
/**
 * Service flows — special USSD menus built from a few blocks instead of code.
 *
 * A flow is a set of named steps. A step is one of:
 *   choices  a list of options, each leading to another step
 *   offers   the offers of catalogue sub-categories (paged); the picked one fills {offer.*}
 *   ask      the customer types something (a phone number, digits, text) into a variable
 *   lookup   a read-only call to Hera/another service; its reply fills {result.*} and the text is shown
 *   confirm  a question; 1 = yes, 0 or 2 = no
 *   call     the call that changes something — runs once per customer session; its OUTCOME
 *            (success / lowbal / fail) decides the next step
 *   message  a text that ends the session
 * Texts may use {placeholders}. A step may lead to "@exit" = leave the flow, back to the menu it was opened from.
 *
 * Like the menu engine, a flow is stateless: the screen is rebuilt from the customer's replies so far, so any replica can
 * serve any step. The one thing that cannot be replayed is a call that changes something — its outcome is stored the first
 * time (ussd_flow_calls) and read back afterwards, so Mobius repeating a request never repeats the call.
 */
const FLOW_TYPES = ['choices', 'offers', 'ask', 'lookup', 'confirm', 'call', 'message'];
const FLOW_MAX_STEPS = 60;

function flow_tables(): void {
    static $done = false; if ($done) return;
    $db = portal_pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_flows (
        flow_key VARCHAR(40) NOT NULL PRIMARY KEY, title VARCHAR(80) NOT NULL, definition LONGTEXT NOT NULL,
        mode ENUM('test','live') NOT NULL DEFAULT 'test', test_outcome ENUM('success','lowbal','fail') NOT NULL DEFAULT 'success',
        status ENUM('active','inactive') NOT NULL DEFAULT 'active', updated_by VARCHAR(80) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_flow_versions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, flow_key VARCHAR(40) NOT NULL, definition LONGTEXT NOT NULL, reason VARCHAR(160) NULL,
        created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_flow (flow_key, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_connections (
        conn_key VARCHAR(40) NOT NULL PRIMARY KEY, title VARCHAR(80) NOT NULL, base_url VARCHAR(300) NOT NULL, headers_enc TEXT NULL,
        ok_code VARCHAR(12) NOT NULL DEFAULT '0', lowbal VARCHAR(200) NOT NULL DEFAULT 'insufficient,low balance,not enough', timeout TINYINT NOT NULL DEFAULT 8,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS ussd_flow_calls (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, call_id VARCHAR(120) NOT NULL, flow_key VARCHAR(40) NOT NULL, step_key VARCHAR(70) NOT NULL,
        outcome VARCHAR(12) NULL, vars LONGTEXT NULL, mode VARCHAR(8) NOT NULL DEFAULT 'live', http_code INT NULL, response TEXT NULL, request_body TEXT NULL,
        msisdn VARCHAR(30) NULL, ms INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_call_step (call_id, flow_key, step_key), INDEX idx_flow_time (flow_key, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

// ------------------------------------------------------------------ storing flows
function flow_get(string $key): ?array {
    flow_tables(); $st = portal_pdo()->prepare('SELECT * FROM ussd_flows WHERE flow_key=?'); $st->execute([$key]); $r = $st->fetch();
    if (!$r) return null;
    $d = json_decode((string)$r['definition'], true); $r['def'] = is_array($d) ? $d : ['start' => '', 'steps' => []];
    return $r;
}
function flow_list(): array { flow_tables(); return portal_pdo()->query('SELECT flow_key,title,mode,status,test_outcome,updated_at FROM ussd_flows ORDER BY title')->fetchAll(); }
function flow_snapshot(string $key, string $reason): void {
    try {
        $f = flow_get($key); if (!$f) return; $db = portal_pdo();
        $db->prepare('INSERT INTO ussd_flow_versions(flow_key,definition,reason,created_by) VALUES(?,?,?,?)')->execute([$key, json_encode($f['def'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), mb_substr($reason, 0, 160), user()['username'] ?? null]);
        $db->prepare('DELETE FROM ussd_flow_versions WHERE flow_key=? AND id < (SELECT m FROM (SELECT MIN(id) m FROM (SELECT id FROM ussd_flow_versions WHERE flow_key=? ORDER BY id DESC LIMIT 40) t) x)')->execute([$key, $key]);
    } catch (Throwable $e) { error_log('flow snapshot: '.$e->getMessage()); }
}
function flow_slug(string $s, int $max = 40): string {
    $s = strtolower(trim($s)); if (!preg_match('/^[a-z0-9_]{1,'.$max.'}$/', $s)) throw new RuntimeException('Keys use lowercase letters, digits and _ only (up to '.$max.' characters).');
    return $s;
}
// New flow (from a template or empty), or the settings of an existing one.
function save_flow(array $d): string {
    flow_tables(); $key = flow_slug((string)($d['flow_key'] ?? ''));
    $title = trim((string)($d['title'] ?? '')); if ($title === '' || mb_strlen($title) > 80) throw new RuntimeException('Give the flow a title (up to 80 characters).');
    $mode = ($d['mode'] ?? '') === 'live' ? 'live' : 'test'; $out = in_array($d['test_outcome'] ?? '', ['success', 'lowbal', 'fail'], true) ? $d['test_outcome'] : 'success';
    $status = ($d['status'] ?? '') === 'inactive' ? 'inactive' : 'active'; $db = portal_pdo(); $existing = flow_get($key);
    if ($existing) {
        $db->prepare('UPDATE ussd_flows SET title=?, mode=?, test_outcome=?, status=?, updated_by=? WHERE flow_key=?')->execute([$title, $mode, $out, $status, user()['username'] ?? null, $key]);
        audit('ussd_flow_settings', null, 'ussd_flows', $key, 'mode='.$mode.' status='.$status);
    } else {
        $tpl = (string)($d['template'] ?? ''); $def = flow_templates()[$tpl]['def'] ?? ['start' => 'start', 'steps' => ['start' => ['type' => 'message', 'text' => 'This service is being set up.']]];
        $db->prepare('INSERT INTO ussd_flows(flow_key,title,definition,mode,test_outcome,status,updated_by) VALUES(?,?,?,?,?,?,?)')->execute([$key, $title, json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'test', 'success', 'active', user()['username'] ?? null]);
        if ($tpl !== '') flow_template_connections($tpl);
        audit('ussd_flow_create', null, 'ussd_flows', $key, 'template='.($tpl ?: '-')); flow_snapshot($key, 'Created'.($tpl !== '' ? ' from the "'.$tpl.'" template' : ''));
    }
    return $key;
}
function flow_save_def(string $key, array $def, string $reason): void {
    $steps = $def['steps'] ?? null; if (!is_array($steps) || count($steps) > FLOW_MAX_STEPS) throw new RuntimeException('A flow needs steps (at most '.FLOW_MAX_STEPS.').');
    foreach ($steps as $k => $s) { flow_slug((string)$k, 30); if (!is_array($s) || !in_array($s['type'] ?? '', FLOW_TYPES, true)) throw new RuntimeException('Step "'.$k.'" has no valid type.'); }
    portal_pdo()->prepare('UPDATE ussd_flows SET definition=?, updated_by=? WHERE flow_key=?')->execute([json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), user()['username'] ?? null, $key]);
    audit('ussd_flow_edit', null, 'ussd_flows', $key, $reason); flow_snapshot($key, $reason);
}
// Add or change one step from the editor's form fields.
function save_flow_step(string $key, array $d): void {
    $f = flow_get($key) ?: throw new RuntimeException('That flow does not exist.'); $def = $f['def'];
    $sk = flow_slug((string)($d['step_key'] ?? ''), 30); $type = (string)($d['type'] ?? ''); if (!in_array($type, FLOW_TYPES, true)) throw new RuntimeException('Choose what kind of step this is.');
    $t = fn(string $k, int $max = 400) => mb_substr(trim((string)($d[$k] ?? '')), 0, $max); $to = fn(string $k) => trim((string)($d[$k] ?? ''));
    $step = ['type' => $type];
    switch ($type) {
        case 'choices':
            $opts = []; foreach (preg_split('/\R/', (string)($d['options'] ?? '')) as $n => $line) {
                $line = trim($line); if ($line === '') continue; $p = array_map('trim', explode('->', $line, 2));
                if (count($p) < 2 || $p[0] === '' || $p[1] === '') throw new RuntimeException('Option line '.($n + 1).': write  Label -> step_key  (use @exit to leave the flow)');
                $opts[] = ['label' => mb_substr($p[0], 0, 80), 'next' => $p[1]];
            }
            if (!$opts) throw new RuntimeException('Give at least one option.'); if (count($opts) > 9) throw new RuntimeException('At most 9 options on a screen.');
            $step += ['text' => $t('text'), 'options' => $opts]; break;
        case 'offers':
            $subs = array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string)($d['sub_categories'] ?? '')))));
            $step += ['text' => $t('text'), 'sub_categories' => $subs, 'next' => $to('next'), 'auto_single' => !empty($d['auto_single'])]; break;
        case 'ask':
            $kind = in_array($d['kind'] ?? '', ['phone', 'digits', 'text'], true) ? $d['kind'] : 'phone'; $var = trim((string)($d['var'] ?? 'input')); if (!preg_match('/^[a-z][a-z0-9_]{0,20}$/', $var)) throw new RuntimeException('The variable name is lowercase letters, digits and _ (e.g. other).');
            $step += ['text' => $t('text'), 'kind' => $kind, 'var' => $var, 'next' => $to('next')]; break;
        case 'lookup':
            $step += ['text' => $t('text'), 'connection' => $to('connection'), 'path' => $to('path'), 'body' => $t('body', 2000)]; break;
        case 'confirm':
            $step += ['text' => $t('text'), 'yes' => $to('yes'), 'no' => $to('no') ?: '@exit']; break;
        case 'call':
            $step += ['connection' => $to('connection'), 'path' => $to('path'), 'body' => $t('body', 2000), 'outcomes' => ['success' => $to('on_success'), 'lowbal' => $to('on_lowbal'), 'fail' => $to('on_fail')]]; break;
        case 'message':
            $step += ['text' => $t('text')]; break;
    }
    $isNew = !isset($def['steps'][$sk]); $def['steps'][$sk] = $step; if (empty($def['start']) || !isset($def['steps'][$def['start']])) $def['start'] = $sk;
    if (!empty($d['make_start'])) $def['start'] = $sk;
    flow_save_def($key, $def, ($isNew ? 'Added' : 'Changed').' step "'.$sk.'"');
}
function flow_delete_step(string $key, string $sk): void {
    $f = flow_get($key) ?: throw new RuntimeException('That flow does not exist.'); $def = $f['def'];
    if (!isset($def['steps'][$sk])) return; if (($def['start'] ?? '') === $sk) throw new RuntimeException('That is the first step — make another step the start before removing it.');
    unset($def['steps'][$sk]); flow_save_def($key, $def, 'Removed step "'.$sk.'"');
}
function flow_set_start(string $key, string $sk): void { $f = flow_get($key) ?: throw new RuntimeException('That flow does not exist.'); if (!isset($f['def']['steps'][$sk])) throw new RuntimeException('No such step.'); $d = $f['def']; $d['start'] = $sk; flow_save_def($key, $d, 'First step is now "'.$sk.'"'); }
function flow_restore_version(int $vid): string {
    flow_tables(); $st = portal_pdo()->prepare('SELECT * FROM ussd_flow_versions WHERE id=?'); $st->execute([$vid]); $v = $st->fetch(); if (!$v) throw new RuntimeException('That saved version is gone.');
    $def = json_decode((string)$v['definition'], true); if (!is_array($def)) throw new RuntimeException('That saved version cannot be read.'); flow_save_def($v['flow_key'], $def, 'Restored an earlier version'); return $v['flow_key'];
}
// Replace the whole definition from JSON (the "advanced" box).
function flow_save_json(string $key, string $json): void { $d = json_decode($json, true); if (!is_array($d)) throw new RuntimeException('That is not valid JSON.'); flow_save_def($key, $d, 'Replaced from JSON'); }

// ------------------------------------------------------------------ connections (where the calls go)
function flow_connection(string $key): ?array {
    flow_tables(); $st = portal_pdo()->prepare('SELECT * FROM ussd_connections WHERE conn_key=?'); $st->execute([$key]); $r = $st->fetch(); if (!$r) return null;
    $r['headers'] = $r['headers_enc'] ? decrypt_secret((string)$r['headers_enc']) : ''; return $r;
}
function flow_connections(): array { flow_tables(); return portal_pdo()->query('SELECT conn_key,title,base_url,(headers_enc IS NOT NULL AND headers_enc<>"") AS has_headers,ok_code,lowbal,timeout FROM ussd_connections ORDER BY title')->fetchAll(); }
// "Name: value" per line, forgiving about commas/quotes; blank values are skipped. A bad line is named by number only (it may hold the key).
function flow_parse_headers(string $in): string {
    $lines = [];
    foreach (preg_split('/\r\n|\n|\r/', $in) as $n => $l) {
        $l = trim(str_replace(["\xC2\xA0", "\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x93", "\xE2\x80\x94"], [' ', '-', '-', '-', '-'], $l)); if ($l === '') continue;
        if (!preg_match('/^([A-Za-z0-9\-]{1,40})\s*:\s*(.*)$/', $l, $m)) throw new RuntimeException('Line '.($n + 1).' of the headers has no "Name: value" shape.');
        $v = trim(rtrim(trim($m[2]), ','), " \t\"'"); if ($v === '') continue; if (mb_strlen($v) > 400) throw new RuntimeException('The value on line '.($n + 1).' is too long.');
        $lines[] = $m[1].': '.$v;
    }
    if (count($lines) > 6) throw new RuntimeException('At most 6 headers.'); return implode("\n", $lines);
}
function save_flow_connection(array $d): string {
    flow_tables(); $key = flow_slug((string)($d['conn_key'] ?? ''));
    $title = trim((string)($d['title'] ?? '')); if ($title === '' || mb_strlen($title) > 80) throw new RuntimeException('Give the connection a title.');
    $base = trim((string)($d['base_url'] ?? '')); if (!preg_match('#^https?://#i', $base) || !filter_var($base, FILTER_VALIDATE_URL) || mb_strlen($base) > 300) throw new RuntimeException('The address must be a full http:// or https:// URL.');
    $ok = trim((string)($d['ok_code'] ?? '0')); if ($ok === '' || mb_strlen($ok) > 12) throw new RuntimeException('Enter the result code that means success (e.g. 0).');
    $low = trim((string)($d['lowbal'] ?? '')); if (mb_strlen($low) > 200) throw new RuntimeException('The low-balance words are at most 200 characters.');
    $to = filter_var($d['timeout'] ?? 8, FILTER_VALIDATE_INT); if ($to === false || $to < 2 || $to > 15) throw new RuntimeException('Timeout: 2 to 15 seconds.');
    $db = portal_pdo(); $old = flow_connection($key); $hdr = trim((string)($d['headers'] ?? ''));
    if (!empty($d['headers_clear'])) $enc = null; elseif ($hdr !== '') $enc = encrypt_secret(flow_parse_headers($hdr)); else $enc = $old['headers_enc'] ?? null;
    $db->prepare('INSERT INTO ussd_connections(conn_key,title,base_url,headers_enc,ok_code,lowbal,timeout) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), base_url=VALUES(base_url), headers_enc=VALUES(headers_enc), ok_code=VALUES(ok_code), lowbal=VALUES(lowbal), timeout=VALUES(timeout)')
        ->execute([$key, $title, rtrim($base, '/').'/', $enc, $ok, $low !== '' ? $low : 'insufficient,low balance,not enough', $to]);
    audit('ussd_connection_save', null, 'ussd_connections', $key, 'host='.parse_url($base, PHP_URL_HOST).' headers_changed='.($hdr !== '' || !empty($d['headers_clear']) ? 'yes' : 'no'));
    return $key;
}

// ------------------------------------------------------------------ templating
function flow_flatten($v, string $prefix = '', array &$out = []): array {
    if (is_array($v)) { foreach ($v as $k => $x) flow_flatten($x, $prefix === '' ? (string)$k : $prefix.'.'.$k, $out); }
    elseif (is_scalar($v) || $v === null) $out[$prefix] = $v === null ? '' : (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
    return $out;
}
function flow_text(string $tpl, array $vars): string {
    return trim((string)preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', fn($m) => isset($vars[$m[1]]) && is_scalar($vars[$m[1]]) ? trim((string)$vars[$m[1]]) : '', $tpl));
}
// A request body: every {placeholder} becomes a JSON-safe value; the *_json ones (caller addresses) go in as JSON.
function flow_render_body(string $tpl, array $vars, array $ctx, string $callId, string $shortcode): string {
    $esc = fn($s) => substr((string)json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    $special = ['txn' => $esc($callId), 'shortcode' => $esc($shortcode), 'imsi' => $esc($ctx['imsi'] ?? ''), 'local_dialog_id' => isset($ctx['localDialogID']) ? (string)(int)$ctx['localDialogID'] : 'null', 'remote_dialog_id' => isset($ctx['remoteDialogID']) ? (string)(int)$ctx['remoteDialogID'] : 'null',
        'local_address_json' => json_encode($ctx['localAddress'] ?? null, JSON_UNESCAPED_SLASHES), 'remote_address_json' => json_encode($ctx['remoteAddress'] ?? null, JSON_UNESCAPED_SLASHES)];
    return (string)preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', function ($m) use ($special, $vars, $esc) { if (isset($special[$m[1]])) return $special[$m[1]]; return isset($vars[$m[1]]) && is_scalar($vars[$m[1]]) ? $esc($vars[$m[1]]) : ''; }, $tpl);
}
function flow_base_vars(array $hooks): array {
    $m = preg_replace('/\D+/', '', (string)($hooks['msisdn'] ?? '')); return ['msisdn' => $m, 'msisdn_local' => ussd_local_number($m)];
}
function flow_offer_vars(array $row, string $code): array {
    $price = ($row['one_time_price'] ?? '') !== '' && $row['one_time_price'] !== null ? (string)$row['one_time_price'] : ''; $name = trim((string)($row['name'] ?? '')) ?: $code;
    return ['offer.code' => $code, 'offer.name' => $name, 'offer.price' => $price, 'offer.price_d' => $price !== '' ? 'D'.$price : '', 'offer.validity' => (string)($row['validity_amount'] ?? ''), 'offer.vendor' => (string)($row['vendor'] ?? ''), 'offer.other_code' => (string)($row['offer_code_for_other'] ?? ''),
        // short names the existing Shared Bundle / purchase request bodies use
        'offer_code' => $code, 'offer_name' => $name, 'price' => $price, 'price_d' => $price !== '' ? 'D'.$price : '', 'vendor' => (string)($row['vendor'] ?? ''), 'other_offer_code' => (string)($row['offer_code_for_other'] ?? '')];
}

// ------------------------------------------------------------------ the engine
final class UssdFlowRun {
    public string $cur; public array $hist = []; public int $page = 0; public ?string $note = null; public array $vars; public array $visits = []; private array $steps; private ?array $rows = null; private array $h;
    public function __construct(array $def, array $vars, array $hooks) { $this->steps = is_array($def['steps'] ?? null) ? $def['steps'] : []; $this->cur = (string)($def['start'] ?? ''); $this->vars = $vars; $this->h = $hooks; }
    private function isDisplay(?array $s): bool { return $s !== null && in_array($s['type'] ?? '', ['choices', 'offers', 'ask', 'lookup', 'confirm', 'message'], true); }
    private function unavailable(): array { return ['text' => 'This service is not available right now.', 'end' => true, 'unavailable' => true]; }
    // Moves to another step ("@exit" leaves the flow) and runs whatever needs no customer input on the way.
    public function enter(string $to): ?array {
        if ($to === '@exit') return ['exit' => true];
        if ($this->isDisplay($this->steps[$this->cur] ?? null) && !(($this->steps[$this->cur]['type'] ?? '') === 'message')) $this->hist[] = $this->cur;
        $this->cur = $to; $this->page = 0; $this->note = null; $this->rows = null;
        return $this->settle();
    }
    // Runs steps that need no reply (calls, one-offer lists, lookups) until one has to be shown.
    public function settle(): ?array {
        for ($guard = 0; $guard < 20; $guard++) {
            $s = $this->steps[$this->cur] ?? null; if ($s === null) return $this->unavailable();
            switch ($s['type']) {
                case 'call':
                    $n = $this->visits[$this->cur] = ($this->visits[$this->cur] ?? 0) + 1; $key = $this->cur.'#'.$n;
                    $res = ($this->h['call'])($this->cur, $s, $this->vars, $key);
                    if ($res === null) return ['pending' => ['step' => $this->cur, 'key' => $key, 'spec' => $s, 'vars' => $this->vars]];
                    $this->vars = array_merge($this->vars, (array)($res['vars'] ?? [])); $to = (string)(($s['outcomes'][$res['outcome']] ?? '') ?: ($s['outcomes']['fail'] ?? ''));
                    if ($to === '') return ['text' => 'Sorry, this could not be completed. Please try again later.', 'end' => true];
                    if ($to === '@exit') return ['exit' => true];
                    $this->cur = $to; $this->page = 0; $this->rows = null; $this->note = null; break;
                case 'lookup':
                    if (empty($this->vars['_looked_'.$this->cur])) { $r = ($this->h['lookup'])($s, $this->vars); $this->vars = array_merge($this->vars, (array)($r['vars'] ?? []), ['_looked_'.$this->cur => '1']); }
                    return null;
                case 'offers':
                    if ($this->rows === null) $this->rows = array_values((array)($this->h['offers'])($s));
                    if (!$this->rows) return null;
                    if (count($this->rows) === 1 && ($s['auto_single'] ?? true)) { $to = $this->pick(0); if ($to === null) return null; $this->cur = $to; $this->page = 0; $this->rows = null; break; }
                    return null;
                default: return null;
            }
        }
        return $this->unavailable();
    }
    // Chooses offer number $i of the loaded list: fills {offer.*}; returns the next step, or null if the offer is no longer for sale.
    private function pick(int $i): ?string {
        $code = (string)($this->rows[$i]['offer_code'] ?? ''); $row = ($this->h['offer'])($code);
        if (!$row) { $this->note = 'This offer is not available right now.'; return null; }
        $this->vars = array_merge($this->vars, flow_offer_vars($row, $code)); $s = $this->steps[$this->cur]; return (string)($s['next'] ?? '') ?: '@exit';
    }
    public function reply(string $r): ?array {
        $s = $this->steps[$this->cur] ?? null; if ($s === null) return $this->unavailable(); $this->note = null;
        $back = function () { if ($this->hist) { $to = array_pop($this->hist); $this->cur = $to; $this->page = 0; $this->rows = null; return $this->settleBack(); } return ['exit' => true]; };
        switch ($s['type']) {
            case 'message': return $this->unavailableEnd();
            case 'choices':
                if ($r === '0') return $back();
                $opts = $s['options'] ?? []; if (!ctype_digit($r) || (int)$r < 1 || (int)$r > count($opts)) { $this->note = 'Invalid choice.'; return null; }
                return $this->enter((string)$opts[(int)$r - 1]['next']);
            case 'offers':
                if ($r === '0') return $back();
                $per = USSD_PAGE_SIZE; $slice = array_slice($this->rows ?? [], $this->page * $per, $per); $more = count($this->rows ?? []) > ($this->page + 1) * $per;
                if ($more && $r === (string)($per + 1)) { $this->page++; return null; }
                if (!ctype_digit($r) || (int)$r < 1 || (int)$r > count($slice)) { $this->note = 'Invalid choice.'; return null; }
                $to = $this->pick($this->page * $per + (int)$r - 1); if ($to === null) return null; return $this->enter($to);
            case 'ask':
                if ($r === '0') return $back();
                $kind = $s['kind'] ?? 'phone'; $var = (string)($s['var'] ?? 'input');
                if ($kind === 'phone') { $n = ussd_normalize_msisdn($r); if ($n === null) { $this->note = 'Invalid number.'; return null; } $this->vars[$var] = $n; $this->vars[$var.'_raw'] = preg_replace('/\D+/', '', $r); $this->vars[$var.'_local'] = ussd_local_number($n); }
                elseif ($kind === 'digits') { if (!preg_match('/^\d{1,20}$/', $r)) { $this->note = 'Invalid entry.'; return null; } $this->vars[$var] = $r; }
                else { if ($r === '' || mb_strlen($r) > 60) { $this->note = 'Invalid entry.'; return null; } $this->vars[$var] = $r; }
                if ($var === 'other' || str_starts_with($var, 'other')) $this->vars['other_msisdn'] = $this->vars[$var.'_raw'] ?? $this->vars[$var];
                return $this->enter((string)($s['next'] ?? '') ?: '@exit');
            case 'lookup':
                if ($r === '0') return $back(); $this->note = 'Invalid choice.'; return null;
            case 'confirm':
                if ($r === '1') return $this->enter((string)($s['yes'] ?? '') ?: '@exit');
                if ($r === '0' || $r === '2') return $this->enter((string)($s['no'] ?? '') ?: '@exit');
                $this->note = 'Invalid choice.'; return null;
        }
        return null;
    }
    private function unavailableEnd(): array { return ['text' => '', 'end' => true, 'ended' => true]; }
    private function settleBack(): ?array { return $this->settle(); }
    public function render(): array {
        $s = $this->steps[$this->cur] ?? null; if ($s === null) return $this->unavailable();
        $L = []; if ($this->note) $L[] = $this->note; $txt = flow_text((string)($s['text'] ?? ''), $this->vars);
        switch ($s['type']) {
            case 'message': return ['text' => $txt !== '' ? $txt : 'Done.', 'end' => true];
            case 'choices':
                if ($txt !== '') $L[] = $txt; foreach ($s['options'] as $i => $o) $L[] = ($i + 1).'. '.flow_text((string)$o['label'], $this->vars); $L[] = '0. Back'; break;
            case 'offers':
                $rows = $this->rows ?? []; if ($txt !== '') $L[] = $txt;
                if (!$rows) { $L[] = 'No offers are available right now.'; $L[] = '0. Back'; break; }
                $per = USSD_PAGE_SIZE; foreach (array_slice($rows, $this->page * $per, $per) as $i => $o) $L[] = ($i + 1).'. '.mb_strimwidth(trim((string)$o['name']) ?: (string)$o['offer_code'], 0, 18, '…').(($o['one_time_price'] ?? '') !== '' ? ' - D'.$o['one_time_price'] : '');
                if (count($rows) > ($this->page + 1) * $per) $L[] = ($per + 1).'. More'; $L[] = '0. Back'; break;
            case 'ask': if ($txt !== '') $L[] = $txt; $L[] = '0. Back'; break;
            case 'lookup': $L[] = $txt !== '' ? $txt : '(nothing to show)'; $L[] = '0. Back'; break;
            case 'confirm': if ($txt !== '') $L[] = $txt; break;
        }
        return ['text' => implode("\n", $L), 'end' => false];
    }
}
// One screen of a flow. $replies = what the customer typed since opening it. Returns ['text','end'] — or ['pending' => the call to run],
// or ['exit_at' => N] when the flow was left after N replies (the menu carries on from there).
function flow_screen(array $def, array $replies, array $hooks): array {
    $hooks += ['offers' => 'flow_offers_default', 'offer' => 'ussd_offer_lookup', 'lookup' => 'flow_lookup_default', 'call' => 'flow_call_stored'];
    $run = new UssdFlowRun($def, flow_base_vars($hooks), $hooks);
    $o = $run->settle(); if ($o !== null) { if (isset($o['exit'])) return ['exit_at' => 0]; return $o + ['vars' => $run->vars]; }
    foreach ($replies as $i => $r) {
        $o = $run->reply(trim((string)$r, " \t\r\n*#"));
        if ($o !== null) { if (isset($o['exit'])) return ['exit_at' => $i + 1]; if (!empty($o['ended'])) return $run->render(); return $o + ['vars' => $run->vars]; }
    }
    return $run->render() + ['vars' => $run->vars];
}
function flow_offers_default(array $step): array { return ussd_catalog_offers(['catalog_filter' => implode("\n", (array)($step['sub_categories'] ?? []))]); }
// What a call returned before, for this customer session (the engine never repeats a call that changes something).
function flow_call_stored(string $stepId, array $step, array $vars, string $key): ?array { return null; }
function flow_lookup_default(array $step, array $vars): array { return ['vars' => []]; }

// ------------------------------------------------------------------ talking to the other system
function flow_post(array $conn, string $url, string $body): array {
    $hdr = ['Content-Type: application/json', 'Accept: application/json'];
    foreach (preg_split('/\r\n|\n/', (string)($conn['headers'] ?? '')) as $l) if (preg_match('/^[A-Za-z0-9\-]{1,40}:\s*\S.*$/', trim($l))) $hdr[] = trim($l);
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => max(2, min(15, (int)($conn['timeout'] ?? 8)))]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return ['code' => $code, 'raw' => is_string($raw) ? $raw : '', 'error' => $err];
}
// Reads a reply: its outcome (success / lowbal / fail) and the values a later screen can use ({result.…}, {reply_text}).
function flow_classify(array $conn, array $r): array {
    $j = json_decode((string)$r['raw'], true); $ok2xx = $r['error'] === '' && $r['code'] >= 200 && $r['code'] < 300; $code = null;
    if (is_array($j)) { if (is_array($j['result'] ?? null) && isset($j['result']['resultCode'])) $code = (string)$j['result']['resultCode']; elseif (isset($j['resultCode'])) $code = ((string)$j['resultCode'] === '000') ? (string)$conn['ok_code'] : (string)$j['resultCode']; }
    $low = false; foreach (array_filter(array_map('trim', explode(',', (string)$conn['lowbal']))) as $t) if (stripos((string)$r['raw'], $t) !== false) { $low = true; break; }
    $outcome = $low ? 'lowbal' : (($ok2xx && $code !== null && $code === (string)$conn['ok_code']) ? 'success' : 'fail');
    $vars = is_array($j) ? flow_flatten($j) : []; $text = $ok2xx ? ussd_share_text($j, (string)$r['raw']) : '';
    return ['outcome' => $outcome, 'vars' => $vars + ['reply_text' => $text, 'reply_code' => (string)$code, 'reply_http' => (string)$r['code']]];
}
// Runs one call that changes something, once. Test mode never sends: it answers with the flow's rehearsal outcome.
function flow_execute_call(array $flow, array $pending, string $callId, string $msisdn, array $ctx, string $shortcode): array {
    flow_tables(); $db = portal_pdo(); $key = (string)$pending['key']; $t0 = microtime(true); $spec = (array)$pending['spec']; $mode = $flow['mode'] === 'live' ? 'live' : 'test';
    $ins = $db->prepare('INSERT IGNORE INTO ussd_flow_calls(call_id,flow_key,step_key,mode,msisdn) VALUES(?,?,?,?,?)'); $ins->execute([substr($callId, 0, 120), $flow['flow_key'], $key, $mode, preg_replace('/\D+/', '', $msisdn)]);
    if ($ins->rowCount() === 0) { $st = $db->prepare('SELECT outcome, vars FROM ussd_flow_calls WHERE call_id=? AND flow_key=? AND step_key=?'); $st->execute([substr($callId, 0, 120), $flow['flow_key'], $key]); $r = $st->fetch(); if ($r && $r['outcome']) return ['outcome' => $r['outcome'], 'vars' => (array)json_decode((string)$r['vars'], true)]; return ['outcome' => 'fail', 'vars' => ['reply_text' => 'Your request is already being processed.']]; }
    $http = null; $resp = null; $sent = null;
    if ($mode === 'test') { $out = $flow['test_outcome']; $res = ['outcome' => $out, 'vars' => ['reply_text' => ['success' => 'TEST: it worked', 'lowbal' => 'TEST: balance too low', 'fail' => 'TEST: it failed'][$out], 'result.resultDescription' => 'TEST', 'reply_code' => '', 'reply_http' => '0']]; }
    else {
        $conn = flow_connection((string)($spec['connection'] ?? '')); $path = trim((string)($spec['path'] ?? '')); $tpl = trim((string)($spec['body'] ?? ''));
        if (!$conn || $path === '' || $tpl === '') $res = ['outcome' => 'fail', 'vars' => ['reply_text' => 'This part is not switched on yet.', 'blocked' => '1']];
        else {
            $body = flow_render_body($tpl, (array)$pending['vars'], $ctx, $callId, $shortcode); $sent = ussd_request_for_log($body);
            $r = flow_post($conn, rtrim((string)$conn['base_url'], '/').'/'.ltrim($path, '/'), $body); $http = $r['code']; $resp = $r['error'] !== '' ? 'error: '.$r['error'] : mb_substr($r['raw'], 0, 1000);
            $res = flow_classify($conn, $r);
        }
    }
    $db->prepare('UPDATE ussd_flow_calls SET outcome=?, vars=?, http_code=?, response=?, request_body=?, ms=? WHERE call_id=? AND flow_key=? AND step_key=?')
        ->execute([$res['outcome'], json_encode($res['vars'], JSON_UNESCAPED_UNICODE), $http, $resp, $sent, (int)round((microtime(true) - $t0) * 1000), substr($callId, 0, 120), $flow['flow_key'], $key]);
    return $res;
}
// The hook the endpoints give the engine: outcomes already stored for this customer session.
function flow_stored_hook(string $flowKey, string $callId): callable {
    return function (string $stepId, array $step, array $vars, string $key) use ($flowKey, $callId): ?array {
        try { flow_tables(); $st = portal_pdo()->prepare('SELECT outcome, vars FROM ussd_flow_calls WHERE call_id=? AND flow_key=? AND step_key=? AND outcome IS NOT NULL'); $st->execute([substr($callId, 0, 120), $flowKey, $key]); $r = $st->fetch(); }
        catch (Throwable $e) { return null; }
        return $r ? ['outcome' => $r['outcome'], 'vars' => (array)json_decode((string)$r['vars'], true)] : null;
    };
}
function flow_lookup_hook(array $flow, array $ctx, string $callId, string $shortcode): callable {
    return function (array $step, array $vars) use ($flow, $ctx, $callId, $shortcode): array {
        if ($flow['mode'] !== 'live') return flow_sim_lookup($step, $vars);
        $conn = flow_connection((string)($step['connection'] ?? '')); $path = trim((string)($step['path'] ?? '')); $tpl = trim((string)($step['body'] ?? ''));
        if (!$conn || $path === '' || $tpl === '') return ['vars' => ['result.resultDescription' => 'This service is not available right now.', 'reply_text' => 'This service is not available right now.']];
        $body = flow_render_body($tpl, $vars, $ctx, 'lk-'.substr(md5($callId.$path), 0, 8), $shortcode);
        $r = cached('flowlookup:'.md5($conn['conn_key'].$path.$body), 20, function () use ($conn, $path, $body, $flow, $callId, $vars) {
            $t0 = microtime(true); $r = flow_post($conn, rtrim((string)$conn['base_url'], '/').'/'.ltrim($path, '/'), $body);
            try { $cl = flow_classify($conn, $r); $resp = $r['error'] !== '' ? 'error: '.$r['error'] : mb_substr($r['raw'], 0, 1000);
                portal_pdo()->prepare('INSERT IGNORE INTO ussd_flow_calls(call_id,flow_key,step_key,outcome,vars,mode,http_code,response,request_body,msisdn,ms) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([substr($callId, 0, 120), $flow['flow_key'], substr('look:'.$path, 0, 70), $cl['outcome'], json_encode($cl['vars'], JSON_UNESCAPED_UNICODE), 'live', $r['code'], $resp, ussd_request_for_log($body), preg_replace('/\D+/', '', (string)($vars['msisdn'] ?? '')), (int)round((microtime(true) - $t0) * 1000)]);
            } catch (Throwable $e) {}
            return $r;
        }); $c = flow_classify($conn, $r);
        return ['vars' => $c['vars'] + ['lookup_ok' => $c['outcome'] === 'success' ? '1' : '']];
    };
}
function flow_sim_lookup(array $step, array $vars): array { return ['vars' => ['result.resultDescription' => 'Sample reply (simulated)', 'result.package1' => 'Sample bundle 1', 'result.package2' => 'Sample bundle 2', 'result.balance' => 'D 100', 'result' => '12.50', 'reply_text' => 'Sample reply (simulated)']]; }
// $outcomes: one outcome for every call ("success"), or a list used call by call ("lowbal,success" = the first call finds the
// balance too low, the next one — e.g. taking a loan — works).
function flow_sim_call_hook(string $outcomes): callable {
    $list = array_values(array_filter(array_map('trim', explode(',', $outcomes)))) ?: ['success']; $n = 0;
    return function (string $stepId, array $step, array $vars, string $key) use ($list, &$n): ?array {
        $outcome = $list[min($n++, count($list) - 1)]; if (!in_array($outcome, ['success', 'lowbal', 'fail'], true)) $outcome = 'fail';
        $t = ['success' => 'Your subscription is successful', 'lowbal' => 'Low balance', 'fail' => 'Subscription failed'][$outcome];
        return ['outcome' => $outcome, 'vars' => ['reply_text' => $t, 'result.resultDescription' => $t, 'reply_code' => $outcome === 'success' ? '0' : '-1']];
    };
}

// ------------------------------------------------------------------ the check-up of one flow
function flow_validate(array $def): array {
    $out = []; $add = function (string $lvl, string $msg) use (&$out) { $out[] = ['level' => $lvl, 'msg' => $msg]; };
    $steps = is_array($def['steps'] ?? null) ? $def['steps'] : []; $start = (string)($def['start'] ?? '');
    if (!$steps) { $add('error', 'The flow has no steps.'); return $out; }
    if (!isset($steps[$start])) $add('error', 'The first step "'.$start.'" does not exist.');
    $known = ['msisdn', 'msisdn_local', 'txn', 'shortcode', 'reply_text', 'reply_code', 'reply_http', 'lookup_ok', 'blocked']; $varsAsked = [];
    foreach ($steps as $k => $s) if (($s['type'] ?? '') === 'ask') { $v = (string)($s['var'] ?? 'input'); array_push($varsAsked, $v, $v.'_raw', $v.'_local'); if (str_starts_with($v, 'other')) $varsAsked[] = 'other_msisdn'; }
    $offerVars = ['offer.code', 'offer.name', 'offer.price', 'offer.price_d', 'offer.validity', 'offer.vendor', 'offer.other_code', 'offer_code', 'offer_name', 'price', 'price_d', 'vendor', 'other_offer_code'];
    $target = function (string $from, string $field, $to) use (&$add, $steps) { $to = (string)$to; if ($to === '') { $add('error', '"'.$from.'": '.$field.' leads nowhere — pick the next step.'); return; } if ($to !== '@exit' && !isset($steps[$to])) $add('error', '"'.$from.'": '.$field.' leads to "'.$to.'", which does not exist.'); };
    $reach = []; $stack = [$start];
    while ($stack) { $c = array_pop($stack); if (isset($reach[$c]) || !isset($steps[$c])) continue; $reach[$c] = 1; $s = $steps[$c];
        foreach (($s['options'] ?? []) as $o) $stack[] = (string)($o['next'] ?? ''); foreach (['next', 'yes', 'no'] as $f) if (!empty($s[$f])) $stack[] = (string)$s[$f]; foreach (($s['outcomes'] ?? []) as $o) $stack[] = (string)$o; }
    foreach ($steps as $k => $s) {
        $t = $s['type'] ?? ''; $txt = (string)($s['text'] ?? '');
        if (mb_strlen($txt) > 170) $add('warn', '"'.$k.'": the text is '.mb_strlen($txt).' characters — a phone screen holds about '.USSD_MAX_CHARS.'.');
        foreach (['text' => $txt, 'body' => (string)($s['body'] ?? '')] as $what => $str) if (preg_match_all('/\{([A-Za-z0-9_.]+)\}/', $str, $m)) foreach (array_unique($m[1]) as $p) {
            if ($what === 'body' && in_array($p, ['imsi', 'local_address_json', 'remote_address_json', 'local_dialog_id', 'remote_dialog_id'], true)) continue;
            if (in_array($p, $known, true) || in_array($p, $offerVars, true) || in_array($p, $varsAsked, true) || $p === 'result' || str_starts_with($p, 'result.')) continue; // {result} alone is a plain reply, e.g. Hera's balance
            $add('warn', '"'.$k.'": {'.$p.'} is not something this flow collects, so it will be empty.');
        }
        if ($t === 'choices') { foreach ($s['options'] ?? [] as $o) $target((string)$k, 'option "'.($o['label'] ?? '').'"', $o['next'] ?? ''); if (empty($s['options'])) $add('error', '"'.$k.'" has no options.'); }
        if ($t === 'offers') { $target((string)$k, 'the next step', $s['next'] ?? ''); if (empty($s['sub_categories'])) $add('error', '"'.$k.'": choose the sub-category (or categories) whose offers are listed.'); }
        if ($t === 'ask') $target((string)$k, 'the next step', $s['next'] ?? '');
        if ($t === 'confirm') { $target((string)$k, '"yes"', $s['yes'] ?? ''); $target((string)$k, '"no"', $s['no'] ?? ''); }
        if ($t === 'call' || $t === 'lookup') {
            if (trim((string)($s['connection'] ?? '')) === '' || !flow_connection((string)$s['connection'])) $add($t === 'call' ? 'warn' : 'warn', '"'.$k.'": no API connection chosen (or it does not exist) — in Live this part stays switched off.');
            if (trim((string)($s['path'] ?? '')) === '' || trim((string)($s['body'] ?? '')) === '') $add('warn', '"'.$k.'": the address path or the request body is empty — in Live this part stays switched off.');
        }
        if ($t === 'call') { $oc = (array)($s['outcomes'] ?? []); foreach (['success' => 'success', 'lowbal' => 'insufficient balance', 'fail' => 'any other failure'] as $f => $lab) { if (empty($oc[$f])) $add($f === 'success' ? 'error' : 'warn', '"'.$k.'": say what happens on '.$lab.($f === 'success' ? '' : ' (otherwise the "other failure" path is used)')); else $target((string)$k, 'on '.$lab, $oc[$f]); } }
        if ($t === 'message' && $txt === '') $add('warn', '"'.$k.'" is a message with no text.');
        if (!isset($reach[$k])) $add('info', '"'.$k.'" cannot be reached from the first step.');
    }
    $rank = ['error' => 0, 'warn' => 1, 'info' => 2]; usort($out, fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]); return $out;
}

// ------------------------------------------------------------------ reading a Mobius log line into a request body
// Paste a Mobius "Sending request :{...} to application:URL" line; the caller-specific values become placeholders.
function flow_body_from_log(string $line): array {
    if (!preg_match('/\{.*\}/s', $line, $m)) throw new RuntimeException('No JSON found in that line.');
    $j = json_decode($m[0], true); if (!is_array($j)) throw new RuntimeException('That line does not contain readable JSON.');
    $url = preg_match('/to application:(\S+)/', $line, $u) ? $u[1] : ($j['remoteURL'] ?? '');
    foreach (['headers', 'mappingType', 'menuID', 'virtualNetworkID', 'remoteURL', 'httpDestinationID', 'lang'] as $drop) unset($j[$drop]);
    $map = ['callID' => '{txn}', 'originalRequest' => '{shortcode}', 'msisdn' => '{msisdn}', 'imsi' => '{imsi}', 'offerCode' => '{offer.code}', 'chargesWithCurrency' => '{offer.price_d}', 'vendor' => '{offer.vendor}', 'otherOfferCode' => '{offer.other_code}', 'otherMsisdn' => '{other_msisdn}'];
    $raw = ['localAddress' => '{local_address_json}', 'remoteAddress' => '{remote_address_json}', 'localDialogID' => '{local_dialog_id}', 'remoteDialogID' => '{remote_dialog_id}'];
    $parts = []; $notes = [];
    foreach ($j as $k => $v) {
        $key = json_encode($k);
        if (isset($raw[$k])) $parts[] = $key.':'.$raw[$k];
        elseif (isset($map[$k])) { $parts[] = $key.':"'.$map[$k].'"'; if (in_array($k, ['offerCode', 'chargesWithCurrency', 'vendor', 'otherOfferCode'], true)) $notes[] = $k.' → '.$map[$k].' (the offer the customer picked; change it if this call is not about a picked offer)'; if ($k === 'otherMsisdn') $notes[] = 'otherMsisdn → {other_msisdn} (an Ask step with variable "other" provides it)'; }
        else $parts[] = $key.':'.json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $path = ''; $conn = ''; if ($url !== '') { $p = parse_url($url); $conn = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/'); $path = basename((string)($p['path'] ?? '')); }
    return ['body' => '{'.implode(',', $parts).'}', 'address' => $conn, 'path' => $path, 'notes' => $notes];
}

// ------------------------------------------------------------------ starter flows
function flow_templates(): array {
    $seddo = ['start' => 'main', 'steps' => [
        'main' => ['type' => 'choices', 'text' => 'Shared Bundle', 'options' => [['label' => 'Buy Shared Bundle', 'next' => 'buy_pick'], ['label' => 'Add Sharing Number', 'next' => 'add_ask'], ['label' => 'My Account', 'next' => 'acct']]],
        'buy_pick' => ['type' => 'offers', 'text' => 'Shared Bundle offers:', 'sub_categories' => ['Seddo'], 'next' => 'buy_confirm', 'auto_single' => true],
        'buy_confirm' => ['type' => 'confirm', 'text' => 'Press 1 to subscribe to Seddo {offer.name} for {offer.price_d}, valid for {offer.validity} days, or press 0 to return to the menu.', 'yes' => 'buy_do', 'no' => 'main'],
        'buy_do' => ['type' => 'call', 'connection' => 'sharebundle', 'path' => 'subscribe', 'body' => SHARE_BODY_SUBSCRIBE, 'outcomes' => ['success' => 'buy_ok', 'lowbal' => 'buy_low', 'fail' => 'buy_fail']],
        'buy_ok' => ['type' => 'message', 'text' => '{reply_text}'], 'buy_low' => ['type' => 'message', 'text' => 'Sorry, your balance is too low for this bundle. Please top up and try again.'], 'buy_fail' => ['type' => 'message', 'text' => '{reply_text}'],
        'add_ask' => ['type' => 'ask', 'text' => 'Enter the beneficiary Seddo number:', 'kind' => 'phone', 'var' => 'other', 'next' => 'add_confirm'],
        'add_confirm' => ['type' => 'confirm', 'text' => 'Please confirm that your Seddo number {other_local} is correct. Press 1 to confirm or press 0 to return.', 'yes' => 'add_do', 'no' => 'main'],
        'add_do' => ['type' => 'call', 'connection' => 'sharebundle', 'path' => 'addNumber', 'body' => SHARE_BODY_ADD, 'outcomes' => ['success' => 'add_ok', 'lowbal' => 'add_fail', 'fail' => 'add_fail']],
        'add_ok' => ['type' => 'message', 'text' => '{reply_text}'], 'add_fail' => ['type' => 'message', 'text' => '{reply_text}'],
        'acct' => ['type' => 'choices', 'text' => 'My Account', 'options' => [['label' => 'Check Balance', 'next' => 'bal'], ['label' => 'My Seddo Numbers', 'next' => 'nums']]],
        'bal' => ['type' => 'lookup', 'text' => 'Your Seddo bundle balance is:\n{result.result.package1}\n{result.result.package2}', 'connection' => 'sharebundle', 'path' => 'DataUsage', 'body' => SHARE_BODY_READ],
        'nums' => ['type' => 'lookup', 'text' => 'My Seddo numbers:\n{reply_text}', 'connection' => 'sharebundle', 'path' => 'listNumber', 'body' => SHARE_BODY_NUMBERS],
    ]];
    $loan = ['start' => 'main', 'steps' => [
        'main' => ['type' => 'choices', 'text' => 'Welcome to menu', 'options' => [['label' => 'Purchase offer', 'next' => 'pick'], ['label' => 'Check balance', 'next' => 'balance']]],
        'pick' => ['type' => 'offers', 'text' => '', 'sub_categories' => [], 'next' => 'confirm', 'auto_single' => false],
        'confirm' => ['type' => 'confirm', 'text' => "You are purchasing {offer.name}\n1. Confirm\n2. Cancel", 'yes' => 'buy', 'no' => 'main'],
        'buy' => ['type' => 'call', 'connection' => '', 'path' => '', 'body' => '', 'outcomes' => ['success' => 'ok', 'lowbal' => 'loan_offer', 'fail' => 'failed']],
        'ok' => ['type' => 'message', 'text' => 'Your subscription is successful'],
        'loan_offer' => ['type' => 'confirm', 'text' => "You don't have enough balance for {offer.name}. You are eligible to take a loan and subscribe.\n1. Confirm\n2. Cancel", 'yes' => 'loan_do', 'no' => 'main'],
        'loan_do' => ['type' => 'call', 'connection' => '', 'path' => '', 'body' => '', 'outcomes' => ['success' => 'loan_ok', 'lowbal' => 'failed', 'fail' => 'failed']],
        'loan_ok' => ['type' => 'message', 'text' => 'The loan has been taken and your subscription is successful'],
        'failed' => ['type' => 'message', 'text' => 'Subscription failed'],
        'balance' => ['type' => 'lookup', 'text' => 'Your balance is: {result.balance}', 'connection' => '', 'path' => '', 'body' => ''],
    ]];
    $bal = ['start' => 'bal', 'steps' => ['bal' => ['type' => 'lookup', 'text' => 'Your balance is: D{result}', 'connection' => 'hera_balance', 'path' => 'Balance', 'body' => '{"msisdn":"{msisdn_local}"}']]];
    return [
        'check_balance' => ['title' => 'Check balance — Hera prepaid/Balance (give the flow the key check_balance; menu items called Check Balance then use it by themselves)', 'def' => $bal],
        'seddo' => ['title' => 'Shared Bundle (Seddo) — buy, add a number, account', 'def' => $seddo],
        'purchase_with_loan' => ['title' => 'Purchase, with a loan offered when the balance is too low (fill in the API calls later)', 'def' => $loan],
    ];
}
// The connection a starter flow talks to, created from what the portal already knows (so the Seddo template works at once).
function flow_template_connections(string $tpl): void {
    if ($tpl === 'check_balance' && !flow_connection('hera_balance')) // headers are added by hand on this page (they are secret): X-API-KEY, X-USERNAME and X-HASHED-PASSWORD as for purchases
        portal_pdo()->prepare('INSERT IGNORE INTO ussd_connections(conn_key,title,base_url,headers_enc,ok_code,lowbal,timeout) VALUES(?,?,?,?,?,?,?)')->execute(['hera_balance', 'Hera Balance', 'https://vas-testing.comium.gm/hera/prepaid/', null, '0', 'insufficient,low balance,not enough', 8]);
    if ($tpl !== 'seddo' || flow_connection('sharebundle')) return; $c = ussd_proxy_config();
    portal_pdo()->prepare('INSERT IGNORE INTO ussd_connections(conn_key,title,base_url,headers_enc,ok_code,lowbal,timeout) VALUES(?,?,?,?,?,?,?)')
        ->execute(['sharebundle', 'Hera ShareBundle', $c['share_base'], $c['purchase_auth'] !== '' ? $c['purchase_auth'] : null, $c['share_ok_code'], $c['purchase_lowbal'] ?: 'insufficient,low balance,not enough', 8]);
}
