<?php
declare(strict_types=1);
// USSD Menu Builder: platform overview helpers, creating menus, history, reordering, copying, quick add, menu health.

// ===================== USSD Menu Builder =====================
// A design/staging tool: define and preview a USSD menu tree, export it as JSON. It does NOT push
// configuration to Mobius (or any other gateway) — that needs the gateway's own menu-config API,
// which this app does not have access to. Until that's wired up, this is the source of truth you'd
// hand-enter (or later auto-push) into the real gateway.
function menu_shortcodes(): array {
    return array_column(portal_pdo()->query("SELECT DISTINCT short_code FROM ussd_menu_nodes WHERE short_code NOT LIKE '% [archived %' ORDER BY short_code")->fetchAll(), 'short_code');
}
// ---- The USSD platform at a glance (Short Codes, USSD & IVR, USSD Proxy and the Menu Builder all use these) ----
// Is a short code answering customers right now, and is it in the Short Code register?
function shortcode_state(string $code, ?array $cfg = null, ?array $registry = null): array {
    $cfg = $cfg ?? ussd_proxy_config();
    if ($registry === null) { $registry = []; try { foreach (portal_pdo()->query("SELECT * FROM portal_short_codes WHERE channel_type='USSD'")->fetchAll() as $r) $registry[$r['short_code']] = $r; } catch (Throwable $e) {} }
    $why = $cfg['enabled'] !== '1' ? 'the USSD endpoint is switched off' : ($cfg['mode'] !== 'live' ? 'the USSD endpoint is in Capture mode' : ($cfg['push_enabled'] !== '1' ? 'screens are not being pushed back through Mobius' : ''));
    return ['live' => $why === '', 'why' => $why, 'registered' => $registry[$code] ?? null];
}
// One entry per short code that has a menu: items on / draft and what the Menu check says.
function menu_overview(): array {
    $out = [];
    foreach (menu_shortcodes() as $code) {
        $flat = menu_nodes_flat($code); $h = menu_health($code);
        $out[$code] = ['items' => count($flat), 'active' => count(array_filter($flat, fn($n) => $n['status'] === 'active')), 'draft' => count(array_filter($flat, fn($n) => $n['status'] === 'draft')),
            'errors' => count(array_filter($h, fn($x) => $x['level'] === 'error')), 'warns' => count(array_filter($h, fn($x) => $x['level'] === 'warn'))];
    }
    return $out;
}
// Menus (short code + item) that open a given service flow.
function flow_usage(string $flowKey): array {
    try { $st = portal_pdo()->prepare("SELECT short_code, prompt_text AS label, status FROM ussd_menu_nodes WHERE node_type='flow' AND offer_code=? AND short_code NOT LIKE '% [archived %' ORDER BY short_code, id"); $st->execute([$flowKey]); return $st->fetchAll(); }
    catch (Throwable $e) { return []; }
}
// The catalogue row behind each offer code used by a routing table (the active one when several share a code).
function ussd_route_offers(string $schema, array $codes): array {
    $codes = array_values(array_unique(array_filter(array_map(fn($c) => trim((string)$c), $codes), fn($c) => $c !== ''))); if (!$codes) return [];
    try {
        if (!table_exists($schema, 'vas_offers')) return [];
        $del = column_exists($schema, 'vas_offers', 'deleted_at') ? " AND (deleted_at IS NULL OR deleted_at='')" : '';
        $st = pdo($schema)->prepare('SELECT offer_code, name, one_time_price, status FROM vas_offers WHERE offer_code IN ('.implode(',', array_fill(0, count($codes), '?')).')'.$del.' ORDER BY id'); $st->execute($codes);
    } catch (Throwable $e) { return []; }
    $by = []; foreach ($st->fetchAll() as $o) { $c = (string)$o['offer_code']; if (!isset($by[$c]) || offer_is_active($o['status']) || !offer_is_active($by[$c]['status'])) $by[$c] = $o; }
    return $by;
}
function ussd_route_on($status): bool { return in_array(strtolower(trim((string)$status)), ['1', 'active', 'on', 'true', 'yes'], true); }
// What is wrong with each routing row: [route id => [['level' => 'error'|'warn', 'msg' => …], …]]. Only rows that are switched on can be a problem for customers.
function ussd_route_review(array $routes, array $offers): array {
    $out = []; $add = function ($r, string $lvl, string $msg) use (&$out) { $out[(int)$r['id']][] = ['level' => $lvl, 'msg' => $msg]; };
    $byService = []; foreach ($routes as $r) if (ussd_route_on($r['status']) && trim((string)$r['service_code']) !== '') $byService[$r['type'].'|'.trim((string)$r['service_code'])][] = $r;
    foreach ($routes as $r) {
        $on = ussd_route_on($r['status']); $code = trim((string)$r['offer_code']); if (!$on) continue;
        if ($code === '') $add($r, 'warn', 'No offer code — dialling this does not sell anything.');
        else {
            if (!ctype_digit($code)) $add($r, 'warn', 'The offer code "'.$code.'" is not a plain number — check it for a typo.');
            if (!isset($offers[$code])) $add($r, 'error', 'Offer '.$code.' is not in the catalogue.');
            elseif (!offer_is_active($offers[$code]['status'])) $add($r, 'error', 'Offer '.$code.' ('.$offers[$code]['name'].') is switched off in the catalogue.');
        }
        $same = $byService[$r['type'].'|'.trim((string)$r['service_code'])] ?? [];
        if (count($same) > 1) $add($r, 'warn', 'The service code '.$r['service_code'].' is also used by '.implode(', ', array_map(fn($x) => $x['shortcode'], array_filter($same, fn($x) => (int)$x['id'] !== (int)$r['id']))).'.');
    }
    return $out;
}
// The setup steps of the USSD Proxy page as a checklist: [['state' => ok|warn|off, 'title', 'detail', 'tab'], …].
function ussd_setup_checklist(array $cfg): array {
    $o = []; $add = function (string $state, string $title, string $detail, string $tab) use (&$o) { $o[] = ['state' => $state, 'title' => $title, 'detail' => $detail, 'tab' => $tab]; };
    if ($cfg['enabled'] !== '1') $add('off', 'Endpoint', 'Switched off — Mobius gets "Not found", customers are not served.', 'connect');
    elseif ($cfg['mode'] !== 'live') $add('warn', 'Endpoint', 'On, but in Capture mode: it only records what Mobius sends and answers with a fixed test text.', 'connect');
    else $add('ok', 'Endpoint', 'On and serving the menus.', 'connect');
    $add(trim((string)$cfg['allow_ips']) === '' ? 'warn' : 'ok', 'Who may call it', trim((string)$cfg['allow_ips']) === '' ? 'Anyone with the secret address can call it. Best practice: list the Mobius servers\' addresses.' : 'Only: '.$cfg['allow_ips'], 'connect');
    $mobiusOk = $cfg['push_enabled'] === '1' && $cfg['mobius_user'] !== '' && $cfg['mobius_pass'] !== '';
    $add($mobiusOk ? 'ok' : ($cfg['push_enabled'] === '1' ? 'warn' : 'off'), 'Answering through Mobius', $mobiusOk ? 'Switched on with a saved API user.' : ($cfg['push_enabled'] === '1' ? 'Switched on, but the API user or password is missing.' : 'Switched off — PROXY menus cannot get their screens back to the phone.'), 'connect');
    $pm = $cfg['purchase_mode'];
    if ($pm === 'off') $add('off', 'Buying offers', 'Off — customers are told it is not switched on.', 'buy');
    elseif ($pm !== 'live') $add('warn', 'Buying offers', 'In test mode: nothing is bought, customers are told it was a test.', 'buy');
    elseif (trim((string)$cfg['purchase_url']) === '') $add('warn', 'Buying offers', 'Live, but there is no purchase address.', 'buy');
    elseif ($cfg['purchase_auth'] === '') $add('warn', 'Buying offers', 'Live, but no headers (API key) are saved — Hera will refuse the requests.', 'buy');
    else $add('ok', 'Buying offers', 'Live, with an address and headers saved.', 'buy');
    $sm = $cfg['share_mode'];
    $add($sm === 'live' ? ($cfg['purchase_auth'] === '' ? 'warn' : 'ok') : ($sm === 'test' ? 'warn' : 'off'), 'Shared Bundle (Seddo)', $sm === 'live' ? ($cfg['purchase_auth'] === '' ? 'Live, but the headers saved under Buying are missing.' : 'Live.') : ($sm === 'test' ? 'In test mode: every answer is simulated.' : 'Off.'), 'share');
    return $o;
}
// ---- Creating a whole menu in one step (the Menus page) ----
function menu_normalize_shortcode(string $c): string { $c = preg_replace('/\s+/', '', trim($c)); if ($c !== '' && !str_ends_with($c, '#')) $c .= '#'; return $c; }
function menu_valid_shortcode(string $c): bool { return (bool)preg_match('/^\*\d{1,6}(\*\d{1,6}){0,4}#$/', $c); }
// Validates the short code and the name, adds the typed items as Drafts and writes the menu into the Short Code register (status Pending until it is
// live). Nothing is created unless everything is valid. Returns ['code' => …, 'items' => number of items added].
function menu_create(string $code, string $name, string $items = ''): array {
    $code = menu_normalize_shortcode($code); $name = trim($name);
    if (!menu_valid_shortcode($code)) throw new RuntimeException('A short code looks like *9606*7070# — a *, then numbers (more *numbers if you like), ending with #.');
    if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Give the menu a name (up to 150 letters), for example "Data bundles".');
    $r = portal_pdo()->prepare("SELECT id FROM portal_short_codes WHERE channel_type='USSD' AND short_code=?"); $r->execute([$code]);
    if (in_array($code, menu_shortcodes(), true) || $r->fetch()) throw new RuntimeException($code.' already exists — open it from the list, or pick another short code.');
    $n = trim($items) === '' ? 0 : menu_quick_add($code, null, $items, 'draft');
    save_shortcode(['channel_type' => 'USSD', 'short_code' => $code, 'service_name' => $name, 'provider' => 'Comium', 'status' => 'Pending', 'description' => 'Created from the Menus page']);
    audit('create_menu', null, 'ussd_menu_nodes', $code, json_encode(['name' => $name, 'items' => $n]));
    return ['code' => $code, 'items' => $n];
}
// An Action item (e.g. "Check Balance", key check_balance) is run by the service flow of that name once that flow exists, is on and is Live —
// so a menu that already has the item starts working the moment the flow is finished, with no change to the menu.
function ussd_action_flow_key(array $n): string { return trim(strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', (string)($n['action_key'] ?? ''))), '_'); }
function menu_node(int $id): ?array {
    $st = portal_pdo()->prepare('SELECT * FROM ussd_menu_nodes WHERE id=?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}
function menu_nodes_flat(string $shortCode): array {
    $st = portal_pdo()->prepare('SELECT * FROM ussd_menu_nodes WHERE short_code=? ORDER BY parent_id IS NULL DESC, parent_id, display_order, id');
    $st->execute([$shortCode]);
    return $st->fetchAll();
}
function menu_tree(string $shortCode): array {
    $flat = menu_nodes_flat($shortCode);
    $byParent = [];
    foreach ($flat as $n) $byParent[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    $build = function ($parentId) use (&$build, $byParent) {
        $out = [];
        foreach ($byParent[$parentId] ?? [] as $n) {
            $n['children'] = $build((int)$n['id']);
            $out[] = $n;
        }
        return $out;
    };
    return $build(0);
}
function save_menu_node(array $data, ?int $id = null, bool $snapshot = true): int {
    $shortCode = trim((string)($data['short_code'] ?? ''));
    $prompt = trim((string)($data['prompt_text'] ?? ''));
    if ($shortCode === '' || $prompt === '') throw new RuntimeException('Short code and prompt text are required.');
    if (mb_strlen($prompt) > 120) throw new RuntimeException('The label is at most 120 characters — it is a line in a menu. Put longer wording in "Screen text".');
    $parentId = trim((string)($data['parent_id'] ?? '')) !== '' ? (int)$data['parent_id'] : null;
    if ($id && $parentId === $id) throw new RuntimeException('An item cannot be its own parent.');
    $type = in_array($data['node_type'] ?? '', ['menu','offer','action','end','catalog','recipient','quiz','sharedbundle','flow'], true) ? $data['node_type'] : 'menu';
    $body = in_array($type, ['menu', 'catalog', 'end'], true) ? trim((string)($data['body_text'] ?? '')) : '';
    if (mb_strlen($body) > 400) throw new RuntimeException('Screen text is at most 400 characters (a phone screen holds about 180).');
    $fields = [$parentId, $shortCode, (int)($data['display_order'] ?? 0), $prompt, $type,
        normalize_value($data['offer_code'] ?? ''), normalize_value($data['action_key'] ?? ''),
        in_array($data['status'] ?? '', ['active','inactive','draft'], true) ? $data['status'] : 'draft',
        in_array($type, ['catalog', 'sharedbundle'], true) ? menu_catalog_filter($data['catalog_filter'] ?? '') : null,
        $body !== '' ? $body : null];
    if ($type === 'sharedbundle' && $fields[8] === '') $fields[8] = 'Seddo';
    if ($type === 'catalog' && $fields[8] === '') throw new RuntimeException('A catalogue list needs at least one sub-category.');
    if ($type === 'offer' && trim((string)$fields[5]) === '') throw new RuntimeException('Choose the offer this item sells.');
    if ($type === 'flow') {
        $fields[5] = trim((string)($data['flow_key'] ?? $data['offer_code'] ?? '')); flow_tables();
        if ($fields[5] === '' || !flow_get($fields[5])) throw new RuntimeException('Choose which service flow this item opens (create it on the Service Flows page first).');
    }
    if ($type === 'quiz') {
        $fields[5] = trim((string)($data['quiz_key'] ?? $data['offer_code'] ?? ''));
        ussd_quiz_tables(); $ex = portal_pdo()->prepare('SELECT 1 FROM ussd_quizzes WHERE quiz_key=?'); $ex->execute([$fields[5]]);
        if ($fields[5] === '' || !$ex->fetchColumn()) throw new RuntimeException('Choose which quiz this item plays (create it on the USSD Quiz page first).');
    }
    $db = portal_pdo();
    if ($id) {
        $db->prepare('UPDATE ussd_menu_nodes SET parent_id=?,short_code=?,display_order=?,prompt_text=?,node_type=?,offer_code=?,action_key=?,status=?,catalog_filter=?,body_text=? WHERE id=?')->execute([...$fields, $id]);
    } else {
        if ($fields[2] === 0) { $mx = $db->prepare('SELECT COALESCE(MAX(display_order),0)+1 FROM ussd_menu_nodes WHERE short_code=? AND parent_id <=> ?'); $mx->execute([$shortCode, $parentId]); $fields[2] = (int)$mx->fetchColumn(); }
        $db->prepare('INSERT INTO ussd_menu_nodes(parent_id,short_code,display_order,prompt_text,node_type,offer_code,action_key,status,catalog_filter,body_text,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([...$fields, user()['username'] ?? null]);
        $id = (int)$db->lastInsertId();
    }
    audit('save_menu_node', null, 'ussd_menu_nodes', (string)$id, json_encode(['short_code'=>$shortCode,'prompt'=>$prompt]));
    if ($snapshot) menu_snapshot($shortCode, 'Saved "'.mb_substr($prompt, 0, 60).'"');
    return $id;
}
// ---- Menu building tools: history, reordering, copying, quick add, health check ----
function menu_versions_table(): void {
    static $done = false; if ($done) return;
    portal_pdo()->exec("CREATE TABLE IF NOT EXISTS ussd_menu_versions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, short_code VARCHAR(80) NOT NULL, snapshot LONGTEXT NOT NULL, reason VARCHAR(160) NULL, nodes INT NOT NULL DEFAULT 0,
        created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_code_time (short_code, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}
// The menu of one short code as nested nodes — the same shape Import / Export JSON use.
function menu_export_tree(string $code): array {
    $by = []; foreach (menu_nodes_flat($code) as $n) $by[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    $build = function (int $pid) use (&$build, $by): array {
        $out = [];
        foreach ($by[$pid] ?? [] as $n) {
            $x = ['prompt_text' => $n['prompt_text'], 'node_type' => $n['node_type'], 'status' => $n['status'], 'display_order' => (int)$n['display_order']];
            foreach (['offer_code', 'action_key', 'catalog_filter', 'body_text'] as $k) if (isset($n[$k]) && $n[$k] !== '') $x[$k] = $n[$k];
            $c = $build((int)$n['id']); if ($c) $x['children'] = $c; $out[] = $x;
        }
        return $out;
    };
    return $build(0);
}
// Every change keeps a copy of the whole menu, so any state can be brought back (last 60 per short code).
function menu_snapshot(string $code, string $reason): void {
    try {
        menu_versions_table(); $tree = menu_export_tree($code); $n = count(menu_nodes_flat($code)); $db = portal_pdo();
        $db->prepare('INSERT INTO ussd_menu_versions(short_code,snapshot,reason,nodes,created_by) VALUES(?,?,?,?,?)')->execute([$code, json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), mb_substr($reason, 0, 160), $n, user()['username'] ?? null]);
        $db->prepare('DELETE FROM ussd_menu_versions WHERE short_code=? AND id < (SELECT m FROM (SELECT MIN(id) m FROM (SELECT id FROM ussd_menu_versions WHERE short_code=? ORDER BY id DESC LIMIT 60) t) x)')->execute([$code, $code]);
    } catch (Throwable $e) { error_log('menu snapshot: '.$e->getMessage()); }
}
function menu_restore_version(int $vid): string {
    menu_versions_table(); $st = portal_pdo()->prepare('SELECT * FROM ussd_menu_versions WHERE id=?'); $st->execute([$vid]); $v = $st->fetch();
    if (!$v) throw new RuntimeException('That saved version is gone.');
    import_menu_json($v['short_code'], $v['snapshot']);
    audit('restore_menu', null, 'ussd_menu_versions', (string)$vid, $v['short_code']);
    return $v['short_code'];
}
function menu_move_node(int $id, string $dir): string {
    $n = menu_node($id) ?: throw new RuntimeException('That item is gone.');
    $db = portal_pdo(); $st = $db->prepare('SELECT id FROM ussd_menu_nodes WHERE short_code=? AND parent_id <=> ? ORDER BY display_order, id'); $st->execute([$n['short_code'], $n['parent_id']]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id')); $i = array_search((int)$id, $ids, true);
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($i !== false && isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; }
    $u = $db->prepare('UPDATE ussd_menu_nodes SET display_order=? WHERE id=?'); foreach ($ids as $k => $nid) $u->execute([$k + 1, $nid]);
    menu_snapshot($n['short_code'], 'Moved "'.mb_substr($n['prompt_text'], 0, 50).'" '.($dir === 'up' ? 'up' : 'down'));
    return $n['short_code'];
}
function menu_set_status(int $id, string $status, bool $branch = false): string {
    if (!in_array($status, ['active', 'inactive', 'draft'], true)) throw new RuntimeException('Unknown status.');
    $n = menu_node($id) ?: throw new RuntimeException('That item is gone.'); $db = portal_pdo(); $ids = [$id];
    if ($branch) { $by = []; foreach (menu_nodes_flat($n['short_code']) as $x) $by[$x['parent_id'] === null ? 0 : (int)$x['parent_id']][] = (int)$x['id']; $stack = [$id]; while ($stack) { $c = array_pop($stack); foreach ($by[$c] ?? [] as $k) { $ids[] = $k; $stack[] = $k; } } }
    $db->prepare('UPDATE ussd_menu_nodes SET status=? WHERE id IN ('.implode(',', array_map('intval', $ids)).')')->execute([$status]);
    menu_snapshot($n['short_code'], '"'.mb_substr($n['prompt_text'], 0, 50).'" set to '.$status.($branch ? ' (with everything under it)' : ''));
    return $n['short_code'];
}
function menu_activate_drafts(string $code): int {
    $st = portal_pdo()->prepare("UPDATE ussd_menu_nodes SET status='active' WHERE short_code=? AND status='draft'"); $st->execute([$code]); $n = $st->rowCount();
    if ($n) menu_snapshot($code, 'Activated '.$n.' draft items');
    return $n;
}
// Copies an item and everything under it, next to the original, as Drafts.
function menu_duplicate_node(int $id): string {
    $n = menu_node($id) ?: throw new RuntimeException('That item is gone.'); $db = portal_pdo();
    $by = []; foreach (menu_nodes_flat($n['short_code']) as $x) $by[$x['parent_id'] === null ? 0 : (int)$x['parent_id']][] = $x;
    $count = 0;
    $copy = function (array $src, ?int $parent, bool $top) use (&$copy, $by, $db, &$count): void {
        if (++$count > 150) throw new RuntimeException('That branch is too big to copy in one go (150 items).');
        $mx = $db->prepare('SELECT COALESCE(MAX(display_order),0)+1 FROM ussd_menu_nodes WHERE short_code=? AND parent_id <=> ?'); $mx->execute([$src['short_code'], $parent]);
        $db->prepare("INSERT INTO ussd_menu_nodes(parent_id,short_code,display_order,prompt_text,node_type,offer_code,action_key,status,catalog_filter,body_text,created_by) VALUES(?,?,?,?,?,?,?,'draft',?,?,?)")
            ->execute([$parent, $src['short_code'], $top ? (int)$mx->fetchColumn() : (int)$src['display_order'], $top ? mb_substr($src['prompt_text'].' (copy)', 0, 120) : $src['prompt_text'], $src['node_type'], $src['offer_code'], $src['action_key'], $src['catalog_filter'] ?? null, $src['body_text'] ?? null, user()['username'] ?? null]);
        $nid = (int)$db->lastInsertId();
        foreach ($by[(int)$src['id']] ?? [] as $c) $copy($c, $nid, false);
    };
    $copy($n, $n['parent_id'] === null ? null : (int)$n['parent_id'], true);
    audit('duplicate_menu_node', null, 'ussd_menu_nodes', (string)$id, $n['short_code']);
    menu_snapshot($n['short_code'], 'Copied "'.mb_substr($n['prompt_text'], 0, 50).'"');
    return $n['short_code'];
}
// One item per line:  Label | type | extra    (type and extra are optional; the default is a submenu)
//   Label | catalog | Sub-category A; Sub-category B     offers from the catalogue, live
//   Label | offer   | 40015                               one offer
//   Label | end     | text shown on its own screen
//   Label | quiz    | quiz key          Label | shared | Seddo          Label | other   (buy for another number)
function menu_quick_add(string $code, ?int $parent, string $lines, string $status = 'draft'): int {
    $code = trim($code); if ($code === '') throw new RuntimeException('Pick a short code first.');
    $map = ['menu' => 'menu', 'submenu' => 'menu', 'catalog' => 'catalog', 'list' => 'catalog', 'offer' => 'offer', 'end' => 'end', 'quiz' => 'quiz', 'shared' => 'sharedbundle', 'sharedbundle' => 'sharedbundle', 'other' => 'recipient', 'recipient' => 'recipient', 'flow' => 'flow'];
    $rows = [];
    foreach (preg_split('/\R/', $lines) as $n => $line) {
        $line = trim($line); if ($line === '' || $line[0] === '#') continue;
        $p = array_map('trim', explode('|', $line, 3)); $type = strtolower($p[1] ?? 'menu'); if ($type === '') $type = 'menu';
        if (!isset($map[$type])) throw new RuntimeException('Line '.($n + 1).': "'.$p[1].'" is not a type. Use menu, catalog, offer, end, quiz, flow, shared or other.');
        $t = $map[$type]; $extra = $p[2] ?? '';
        $d = ['short_code' => $code, 'parent_id' => $parent, 'prompt_text' => $p[0], 'node_type' => $t, 'status' => $status];
        if ($t === 'catalog') { if ($extra === '') throw new RuntimeException('Line '.($n + 1).': a catalogue list needs its sub-categories after the second |, separated by ;'); $d['catalog_filter'] = str_replace(';', "\n", $extra); }
        if ($t === 'sharedbundle') $d['catalog_filter'] = str_replace(';', "\n", $extra !== '' ? $extra : 'Seddo');
        if ($t === 'offer') { if ($extra === '') throw new RuntimeException('Line '.($n + 1).': an offer item needs the offer code after the second |'); $d['offer_code'] = $extra; }
        if ($t === 'quiz') $d['quiz_key'] = $extra;
        if ($t === 'flow') $d['flow_key'] = $extra;
        if ($t === 'end') $d['body_text'] = $extra;
        $rows[] = [$n + 1, $d];
    }
    if (!$rows) throw new RuntimeException('Nothing to add — write one item per line.');
    if (count($rows) > 60) throw new RuntimeException('At most 60 items at a time.');
    flow_tables(); menu_versions_table(); // creating a table inside a transaction would end it (MySQL commits on any CREATE TABLE)
    $db = portal_pdo(); $db->beginTransaction();
    try { foreach ($rows as [$ln, $d]) { try { save_menu_node($d, null, false); } catch (RuntimeException $e) { throw new RuntimeException('Line '.$ln.': '.$e->getMessage()); } } $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); throw $e; }
    menu_snapshot($code, 'Quick add: '.count($rows).' items');
    return count($rows);
}
function menu_copy_to(string $from, string $to, bool $confirmReplace): int {
    $to = trim($to); if ($to === '' || $to === $from) throw new RuntimeException('Enter a different short code to copy to.');
    if (menu_nodes_flat($to) && !$confirmReplace) throw new RuntimeException($to.' already has a menu. Tick the box to replace it (the old one is kept, inactive, as an archive).');
    $tree = menu_export_tree($from); if (!$tree) throw new RuntimeException('There is nothing to copy.');
    $draft = function (array $nodes) use (&$draft): array { foreach ($nodes as &$n) { $n['status'] = 'draft'; if (!empty($n['children'])) $n['children'] = $draft($n['children']); } return $nodes; };
    $r = import_menu_json($to, json_encode($draft($tree)));
    audit('copy_menu', null, 'ussd_menu_nodes', null, $from.' -> '.$to);
    return $r['nodes'];
}
// A check-up of one short code: what would confuse customers or fail on a phone. [level error|warn|info, node id, message]
function menu_health(string $code): array {
    $out = []; $add = function (string $lvl, ?int $id, string $msg) use (&$out) { $out[] = ['level' => $lvl, 'node' => $id, 'msg' => $msg]; };
    $flat = menu_nodes_flat($code);
    if (!$flat) return [['level' => 'info', 'node' => null, 'msg' => 'This short code has no items yet — add the first one, or use Quick add.']];
    $kids = []; foreach ($flat as $n) $kids[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n;
    $isActive = fn($n) => $n['status'] === 'active';
    $drafts = count(array_filter($flat, fn($n) => $n['status'] === 'draft')); $offs = count(array_filter($flat, fn($n) => $n['status'] === 'inactive'));
    if (!array_filter($kids[0] ?? [], $isActive)) $add('error', null, 'Nothing is Active, so customers hear "No menu is set up" for this code.');
    if ($drafts) $add('warn', null, $drafts.' item'.($drafts > 1 ? 's are' : ' is').' still Draft — customers do not see '.($drafts > 1 ? 'them' : 'it').' until set Active (use "Activate all drafts").');
    if ($offs) $add('info', null, $offs.' item'.($offs > 1 ? 's are' : ' is').' switched off.');
    $cfg = ussd_proxy_config();
    if (!($cfg['enabled'] === '1' && $cfg['mode'] === 'live' && $cfg['push_enabled'] === '1')) $add('warn', null, 'The USSD Proxy is not fully live (endpoint on, Live mode, answering through Mobius) — phones will not get this menu yet.');
    $walked = 0; $siblingsSeen = [];
    $walk = function (array $n) use (&$walk, &$add, $kids, $isActive, &$walked, $code, $flat) {
        if (++$walked > 200) return;
        $id = (int)$n['id']; $label = '"'.mb_substr($n['prompt_text'], 0, 40).'"'; $t = $n['node_type'];
        $live = array_values(array_filter($kids[$id] ?? [], $isActive));
        if (mb_strlen($n['prompt_text']) > 45) $add('warn', $id, $label.' is a long line for a menu ('.mb_strlen($n['prompt_text']).' characters).');
        if ($t === 'menu') {
            if (!$live) $add('error', $id, $label.' is a submenu with nothing Active inside — customers would see an empty menu.');
            else {
                $len = mb_strlen(trim((string)($n['body_text'] ?? '')) !== '' ? $n['body_text'] : $n['prompt_text']) + 8; foreach ($live as $i => $c) $len += mb_strlen(($i + 1).'. '.$c['prompt_text']) + 1;
                if ($len > USSD_MAX_CHARS) $add('error', $id, $label.' is too long for one phone screen ('.$len.' characters, at most '.USSD_MAX_CHARS.') — move some items into a submenu.');
            }
        }
        if ($t === 'catalog') {
            $offers = ussd_catalog_offers($n); $subs = str_replace("\n", ', ', (string)$n['catalog_filter']);
            if (!$offers) $add('error', $id, $label.' lists offers from ['.$subs.'] but none are Active in the '.USSD_OFFER_SCHEMA.' catalogue — customers see "No offers are available right now".');
        }
        if ($t === 'offer') { if (!ussd_offer_lookup(trim((string)$n['offer_code']))) $add('error', $id, $label.' sells offer '.$n['offer_code'].', which is missing or not Active in '.USSD_OFFER_SCHEMA.' — customers see "not available".'); }
        if ($t === 'quiz') {
            $info = ussd_quiz_info(trim((string)$n['offer_code']));
            if (!$info) $add('error', $id, $label.' plays quiz "'.$n['offer_code'].'", which does not exist or is switched off.');
            else { $q = ussd_quiz_questions($info['quiz_key'], 'health', 0, 99); if (count($q) < (int)$info['per_game']) $add('warn', $id, $label.': the quiz has only '.count($q).' active questions but a game asks '.(int)$info['per_game'].'.'); }
        }
        if ($t === 'sharedbundle') {
            if (!ussd_catalog_offers($n)) $add('error', $id, $label.': no Active offer in ['.str_replace("\n", ', ', (string)$n['catalog_filter']).'] — customers cannot buy.');
            $c2 = ussd_proxy_config(); if ($c2['share_mode'] !== 'live') $add('info', $id, $label.': Shared Bundle is in '.strtoupper($c2['share_mode']).' mode — '.($c2['share_mode'] === 'test' ? 'answers are simulated, nothing is bought.' : 'it is switched off.'));
        }
        if ($t === 'flow') {
            $fl = flow_get(trim((string)$n['offer_code']));
            if (!$fl) $add('error', $id, $label.' opens the service flow "'.$n['offer_code'].'", which does not exist.');
            elseif ($fl['status'] !== 'active') $add('error', $id, $label.': the service flow "'.$n['offer_code'].'" is switched off.');
            else {
                $fv = flow_validate($fl['def']); $fe = array_values(array_filter($fv, fn($x) => $x['level'] === 'error')); $fw = array_filter($fv, fn($x) => $x['level'] === 'warn');
                if ($fe) $add('error', $id, $label.': the flow has '.count($fe).' thing(s) to fix — '.$fe[0]['msg']);
                if ($fw) $add('warn', $id, $label.': the flow has '.count($fw).' thing(s) to look at on the Service Flows page.');
                if ($fl['mode'] !== 'live') $add('info', $id, $label.': the flow is in TEST mode — every call is simulated ('.$fl['test_outcome'].'), nothing is sent.');
            }
        }
        if ($t === 'recipient') {
            $actKids = []; foreach ($flat as $x) if ($x['status'] === 'active') $actKids[$x['parent_id'] === null ? 0 : (int)$x['parent_id']][] = $x;
            $top = $actKids[0] ?? []; if (count($top) === 1 && $top[0]['node_type'] === 'menu') $top = $actKids[(int)$top[0]['id']] ?? [];
            $memo = []; $any = false; foreach ($top as $c) if (ussd_allowed_for_other($c, $actKids, 'ussd_offer_lookup', 'ussd_catalog_offers', $memo)) { $any = true; break; }
            if (!$any) $add('error', $id, $label.': nothing in this menu can be bought for another number (offers need an "other" code in the catalogue) — customers would see an empty list.');
        }
        if ($t === 'action') {
            $ak = ussd_action_flow_key($n); $afl = $ak !== '' ? flow_get($ak) : null;
            if (!$afl) $add('warn', $id, $label.' is an Action ('.$n['action_key'].') that is not connected to anything yet — customers are told it is not available. To connect it, create a service flow called "'.($ak ?: 'the action name').'" on Service Flows (for a balance there is a starter: "Check balance").');
            elseif ($afl['status'] !== 'active') $add('warn', $id, $label.' is connected to the service flow "'.$ak.'", which is switched off — customers are told it is not available.');
            elseif ($afl['mode'] !== 'live') $add('warn', $id, $label.' is connected to the service flow "'.$ak.'", which is still in Test mode — customers are told it is not available until the flow is set to Live.');
            else { $fe = array_filter(flow_validate($afl['def']), fn($x) => $x['level'] === 'error'); if ($fe) $add('error', $id, $label.': the service flow "'.$ak.'" has '.count($fe).' thing(s) to fix — '.array_values($fe)[0]['msg']); }
        }
        if ($t === 'end' && trim((string)($n['body_text'] ?? '')) === '' && mb_strlen($n['prompt_text']) > 60) $add('info', $id, $label.' shows its whole label as its screen; use "Screen text" for a longer message.');
        $seen = []; foreach ($live as $c) { $k = mb_strtolower($c['prompt_text']); if (isset($seen[$k])) $add('info', (int)$c['id'], 'Two items under '.$label.' are both called "'.$c['prompt_text'].'".'); $seen[$k] = 1; }
        foreach ($live as $c) $walk($c);
    };
    foreach (array_filter($kids[0] ?? [], $isActive) as $r) $walk($r);
    $rank = ['error' => 0, 'warn' => 1, 'info' => 2]; usort($out, fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
    return $out;
}
// The sub-categories a "catalogue list" node shows, one per line (a list, or text with line breaks or | between them).
function menu_catalog_filter($v): string {
    $parts = is_array($v) ? $v : preg_split('/[\r\n|]+/', (string)$v);
    $out = [];
    foreach ($parts as $p) { $p = trim((string)$p); if ($p === '') continue; if (mb_strlen($p) > 80) throw new RuntimeException('A sub-category name is at most 80 characters.'); $out[$p] = $p; }
    if (count($out) > 12) throw new RuntimeException('At most 12 sub-categories per list.');
    return implode("\n", $out);
}
// Replaces the menu of one short code with the tree in $json (the same shape "Export JSON" produces: a list of nodes,
// each with prompt_text, node_type, offer_code, action_key, status, catalog_filter and children). Nothing is deleted:
// the old nodes are kept under "<short code> [archived …]", inactive, and no longer shown or served.
function import_menu_json(string $shortCode, string $json): array {
    $shortCode = trim($shortCode); if ($shortCode === '' || mb_strlen($shortCode) > 40) throw new RuntimeException('Enter the short code (up to 40 characters).');
    $d = json_decode($json, true); if (!is_array($d)) throw new RuntimeException('That is not valid JSON.');
    $tree = isset($d['menu']) && is_array($d['menu']) ? $d['menu'] : $d;
    if (!$tree || !array_is_list($tree)) throw new RuntimeException('Expected a list of menu nodes (or an export with a "menu" list).');
    $flat = []; $count = 0;
    $walk = function (array $nodes, ?int $parent, int $depth) use (&$walk, &$flat, &$count) {
        if ($depth > 5) throw new RuntimeException('Menus can be at most 5 levels deep.');
        foreach (array_values($nodes) as $i => $n) {
            if (!is_array($n)) throw new RuntimeException('Every node must be an object.');
            if (++$count > 150) throw new RuntimeException('At most 150 nodes per import.');
            $prompt = trim((string)($n['prompt_text'] ?? '')); if ($prompt === '' || mb_strlen($prompt) > 300) throw new RuntimeException('Every node needs prompt_text (up to 300 characters).');
            $type = (string)($n['node_type'] ?? 'menu'); if (!in_array($type, ['menu', 'offer', 'action', 'end', 'catalog', 'recipient', 'quiz', 'sharedbundle', 'flow'], true)) throw new RuntimeException('"'.$type.'" is not a node type.');
            $status = (string)($n['status'] ?? 'draft'); if (!in_array($status, ['active', 'inactive', 'draft'], true)) throw new RuntimeException('"'.$status.'" is not a status.');
            $filter = in_array($type, ['catalog', 'sharedbundle'], true) ? menu_catalog_filter($n['catalog_filter'] ?? '') : null;
            if ($type === 'sharedbundle' && $filter === '') $filter = 'Seddo';
            if ($type === 'catalog' && $filter === '') throw new RuntimeException('"'.$prompt.'": a catalogue list needs catalog_filter (its sub-categories).');
            $bodyTxt = in_array($type, ['menu', 'catalog', 'end'], true) ? trim((string)($n['body_text'] ?? '')) : '';
            $key = count($flat); $flat[$key] = ['parent' => $parent, 'order' => (int)($n['display_order'] ?? ($i + 1)), 'prompt' => $prompt, 'type' => $type, 'body' => $bodyTxt !== '' ? mb_substr($bodyTxt, 0, 400) : null,
                'offer' => mb_substr(trim((string)($n['offer_code'] ?? '')), 0, 80) ?: null, 'action' => mb_substr(trim((string)($n['action_key'] ?? '')), 0, 80) ?: null, 'status' => $status, 'filter' => $filter];
            if (!empty($n['children'])) { if (!is_array($n['children'])) throw new RuntimeException('children must be a list.'); $walk($n['children'], $key, $depth + 1); }
        }
    };
    $walk($tree, null, 1);
    flow_tables(); menu_versions_table();
    $db = portal_pdo(); $db->beginTransaction();
    try {
        $archived = $db->prepare("UPDATE ussd_menu_nodes SET short_code=CONCAT(short_code,' [archived ',DATE_FORMAT(NOW(),'%m-%d %H:%i'),']'), status='inactive' WHERE short_code=?");
        $archived->execute([$shortCode]); $old = $archived->rowCount();
        $ins = $db->prepare('INSERT INTO ussd_menu_nodes(parent_id,short_code,display_order,prompt_text,node_type,offer_code,action_key,status,catalog_filter,body_text,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $ids = [];
        foreach ($flat as $k => $n) {
            $ins->execute([$n['parent'] === null ? null : $ids[$n['parent']], $shortCode, $n['order'], $n['prompt'], $n['type'], $n['offer'], $n['action'], $n['status'], $n['filter'], $n['body'], user()['username'] ?? null]);
            $ids[$k] = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit('import_menu', null, 'ussd_menu_nodes', null, json_encode(['short_code' => $shortCode, 'nodes' => count($flat), 'archived' => $old]));
    menu_snapshot($shortCode, 'Imported '.count($flat).' items');
    return ['nodes' => count($flat), 'archived' => $old];
}
// Renders a simple text simulation of walking the menu from its root nodes — what a subscriber
// would actually see on their phone, useful for reviewing the flow without a live gateway.
function render_menu_preview(array $tree, int $depth = 0): string {
    $out = '';
    $i = 1;
    foreach ($tree as $node) {
        $indent = str_repeat('  ', $depth);
        $suffix = $node['node_type'] === 'offer' ? ' → purchase '.e($node['offer_code'])
            : ($node['node_type'] === 'action' ? ' → '.e($node['action_key'])
            : ($node['node_type'] === 'flow' ? ' → service flow '.e($node['offer_code'])
            : ($node['node_type'] === 'sharedbundle' ? ' → Shared Bundle service (offers: '.e(str_replace("\n", ', ', (string)($node['catalog_filter'] ?? ''))).')'
            : ($node['node_type'] === 'quiz' ? ' → quiz game '.e($node['offer_code'])
            : ($node['node_type'] === 'recipient' ? ' → asks for another number, then shows the main menu'
            : ($node['node_type'] === 'end' ? ' → END'
            : ($node['node_type'] === 'catalog' ? ' → live list: '.e(str_replace("\n", ', ', (string)($node['catalog_filter'] ?? ''))) : '')))))));
        $out .= $indent.($depth===0 ? $i.'. ' : '- ').e($node['prompt_text']).$suffix."\n";
        if ($node['children']) $out .= render_menu_preview($node['children'], $depth + 1);
        $i++;
    }
    return $out;
}
