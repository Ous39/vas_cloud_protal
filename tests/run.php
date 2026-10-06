<?php
declare(strict_types=1);
/**
 * Regression tests for the USSD side of VAS Cloud. No framework needed:   php tests/run.php
 *
 * Needs a MySQL the portal can use (the same DB_* / PORTAL_DB / ALLOWED_SCHEMAS settings the app reads). It creates its own
 * rows (short codes *ZT…#, offers ZT…, quiz zt_quiz, calls ZT-…) and removes them again, and puts back the USSD settings it
 * changed. Hera/Mobius are replaced by tests/fixtures/hera_stub.php, started on a local port.
 *
 * Exit code 0 = all passed, 1 = something failed (so CI can block a bad build).
 */
define('LEAN_BOOT', true);
$root = dirname(__DIR__);
foreach ([$root.'/app/lib/bootstrap.php', '/var/www/lib/bootstrap.php'] as $f) if (is_file($f)) { require $f; break; }
if (!function_exists('ussd_screen')) { fwrite(STDERR, "Could not load the portal code.\n"); exit(2); }

$pass = 0; $fail = 0; $failures = [];
function t(string $name, callable $fn): void {
    global $pass, $fail, $failures; $before = $fail;
    try { $fn(); } catch (Throwable $e) { $fail++; $failures[] = $name.' — threw '.get_class($e).': '.$e->getMessage(); echo "  FAIL  $name (threw: ".$e->getMessage().")\n"; return; }
    if ($fail === $before) { $pass++; echo "  ok    $name\n"; }
}
function ok($cond, string $msg): void { global $fail, $failures; if (!$cond) { $fail++; $failures[] = $msg; echo "        ✗ $msg\n"; } }
function eq($actual, $expected, string $msg): void { ok($actual === $expected, $msg.' — expected '.json_encode($expected).', got '.json_encode($actual)); }
function has(string $hay, string $needle, string $msg): void { ok(str_contains($hay, $needle), $msg.' — "'.$needle.'" not in '.json_encode($hay)); }
function lacks(string $hay, string $needle, string $msg): void { ok(!str_contains($hay, $needle), $msg.' — "'.$needle.'" should not be in '.json_encode($hay)); }
function thrown(callable $fn, string $contains, string $msg): void { try { $fn(); ok(false, $msg.' — expected an error'); } catch (Throwable $e) { has($e->getMessage(), $contains, $msg); } }
function screen(string $code, array $replies, array $hooks = []): array { return ussd_screen($code, $replies, ['active'], null, $hooks + ['seed' => 'ZT-seed', 'msisdn' => '220866000001', 'share' => 'ussd_share_sim']); }

echo "VAS Cloud tests\n";

// ------------------------------------------------------------------ setup
ensure_portal_runtime_schema();
$db = portal_pdo();
$savedCfg = $db->query('SELECT name,value FROM ussd_proxy_config')->fetchAll(PDO::FETCH_KEY_PAIR);
$offerDb = pdo(USSD_OFFER_SCHEMA);
$offerDb->exec("CREATE TABLE IF NOT EXISTS vas_offers (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, created_at VARCHAR(45), vendor VARCHAR(45), offer_code VARCHAR(45), offer_code_for_other VARCHAR(45), pcrf_offer_code VARCHAR(45),
    category VARCHAR(45) DEFAULT '0', sub_category VARCHAR(45), name VARCHAR(45), description VARCHAR(45), one_time_price INT, rental_price VARCHAR(45), discount VARCHAR(45), updated_at VARCHAR(45), deleted_at VARCHAR(45),
    status VARCHAR(45) DEFAULT '0', free_data INT DEFAULT 0, validity_amount INT, speed_limit VARCHAR(45) DEFAULT 'NA')");
$cleanup = function () use ($db, $offerDb, $savedCfg) {
    $offerDb->exec("DELETE FROM vas_offers WHERE offer_code LIKE 'ZT%'");
    $db->exec("DELETE FROM ussd_menu_nodes WHERE short_code LIKE '*ZT%'");
    $db->exec("DELETE FROM ussd_menu_versions WHERE short_code LIKE '*ZT%'");
    $db->exec("DELETE FROM ussd_purchases WHERE call_id LIKE 'ZT-%'");
    $db->exec("DELETE FROM ussd_flow_calls WHERE call_id LIKE 'ZT-%'"); $db->exec("DELETE FROM ussd_flow_versions WHERE flow_key LIKE 'zt_%'"); $db->exec("DELETE FROM ussd_flows WHERE flow_key LIKE 'zt_%'"); $db->exec("DELETE FROM ussd_connections WHERE conn_key LIKE 'zt_%'");
    $db->exec("DELETE FROM ussd_flow_calls WHERE flow_key='zt_seddo'"); $db->exec("DELETE FROM ussd_flows WHERE flow_key='zt_seddo'");
    $db->exec("DELETE FROM ussd_quiz_plays WHERE quiz_key='zt_quiz'"); $db->exec("DELETE FROM ussd_quiz_questions WHERE quiz_key='zt_quiz'"); $db->exec("DELETE FROM ussd_quizzes WHERE quiz_key='zt_quiz'");
    $db->exec('DELETE FROM ussd_proxy_config');
    $ins = $db->prepare('INSERT INTO ussd_proxy_config(name,value) VALUES(?,?)'); foreach ($savedCfg as $k => $v) $ins->execute([$k, $v]);
};
ussd_quiz_tables(); ussd_purchase_table(); menu_versions_table(); flow_tables(); $cleanup();
$addOffer = $offerDb->prepare('INSERT INTO vas_offers(vendor,offer_code,offer_code_for_other,sub_category,name,one_time_price,status,validity_amount) VALUES(?,?,?,?,?,?,?,?)');
foreach ([['huawei', 'ZT001', 'ZTO01', 'ZT Cat A', 'ZT Alpha', 10, '1', 7], ['huawei', 'ZT002', '', 'ZT Cat A', 'ZT Beta', 20, '1', 30], ['huawei', 'ZT003', '', 'ZT Cat A', 'ZT Gone', 5, '0', 1],
         ['huawei', 'ZTSED', '', 'ZT Seddo', '12GB Seddo', 600, '1', 30], ['huawei', 'ZTS1', '', 'ZT Cat C', 'ZT Same', 9, '1', 1], ['huawei', 'ZTS2', '', 'ZT Cat D', 'ZT Same', 9, '1', 1]] as $o) $addOffer->execute($o);
for ($i = 1; $i <= 12; $i++) $addOffer->execute(['huawei', sprintf('ZTB%02d', $i), '', 'ZT Cat B', 'ZT Item '.$i, $i * 10, '1', 7]);

// the stand-in Hera/Mobius
$port = 18080 + random_int(0, 500);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/fixtures/hera_stub.php'], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($proc, $cleanup) { if (is_resource($proc)) proc_terminate($proc); $cleanup(); });
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
$stub = 'http://127.0.0.1:'.$port;
$lastBody = function (string $path): array { $f = sys_get_temp_dir().'/zt_stub_last_'.md5($path).'.json'; $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return $j ? ['raw' => $j['body'], 'json' => json_decode($j['body'], true) ?: [], 'headers' => $j['headers']] : ['raw' => '', 'json' => [], 'headers' => []]; };

// ------------------------------------------------------------------ small pure helpers
echo "\nHelpers\n";
t('phone numbers are normalised', function () {
    eq(ussd_normalize_msisdn('866520934'), '220866520934', '9 digits get the country code');
    eq(ussd_normalize_msisdn('3123456'), '2203123456', '7 digits get the country code');
    eq(ussd_normalize_msisdn('+220 866 520 934'), '220866520934', 'spaces and + are dropped');
    eq(ussd_normalize_msisdn('12'), null, 'too short is refused'); eq(ussd_normalize_msisdn('abc'), null, 'letters are refused');
});
t('Hera replies become the text a customer sees', function () {
    $f = fn(string $raw) => ussd_share_text(json_decode($raw, true), $raw);
    eq($f('{"resultCode":"9990","resultDescription":"You don\'t have active Seddo Subscription"}'), "You don't have active Seddo Subscription", 'top-level reason');
    eq($f('{"resultCode":"000","resultDescription":"Success","result":{"resultCode":"0","resultDescription":"\nYour subscription is successful"}}'), 'Your subscription is successful', 'nested description');
    eq($f('{"resultCode":"000","resultDescription":"Success"}'), '', 'bare success has no text');
});
t('menu catalogue filters are tidied', function () {
    eq(menu_catalog_filter("A\n  B | A\n"), "A\nB", 'trimmed, split on | and newlines, de-duplicated');
    thrown(fn() => menu_catalog_filter(array_map(fn($i) => 'S'.$i, range(1, 13))), 'At most 12', 'more than 12 refused');
});
t('quiz questions are checked', function () {
    thrown(fn() => quiz_question_fields('A fine question here?', ['a'], 1), '2 to 4 answers', 'one answer refused');
    thrown(fn() => quiz_question_fields('A fine question here?', ['a', 'b'], 3), 'right answer', 'right answer must exist');
    thrown(fn() => quiz_question_fields(str_repeat('x', 120).'?', [str_repeat('a', 28), str_repeat('b', 28), str_repeat('c', 28)], 1), 'Too long for one phone screen', 'a question that cannot fit one screen is refused');
    eq(quiz_question_fields('Capital of The Gambia?', ['Dakar', 'Banjul'], 2)[2], 2, 'a good one passes');
});

// ------------------------------------------------------------------ the screen engine
echo "\nScreens (engine)\n";
$nodes = [];
$GLOBALS['mk'] = $mk = function (int $id, ?int $p, string $type, string $label, array $x = []) { return $x + ['id' => $id, 'parent_id' => $p, 'node_type' => $type, 'status' => 'active', 'prompt_text' => $label, 'offer_code' => '', 'action_key' => '', 'catalog_filter' => '', 'body_text' => '', 'display_order' => $id]; };
$nodes = [$mk(1, null, 'menu', 'Main'), $mk(2, 1, 'menu', 'Bundles'), $mk(3, 1, 'end', 'Help', ['body_text' => 'Call 123 for help']), $mk(4, 2, 'catalog', 'Cat B', ['catalog_filter' => 'ZT Cat B']),
          $mk(5, 2, 'offer', 'Alpha', ['offer_code' => 'ZT001']), $mk(6, 1, 'recipient', 'Buy for other'), $mk(7, 2, 'catalog', 'Same name', ['catalog_filter' => "ZT Cat C\nZT Cat D"]), $mk(8, 2, 'offer', 'Gone', ['offer_code' => 'ZT003']),
          $mk(9, 2, 'catalog', 'Cat A', ['catalog_filter' => 'ZT Cat A']), $mk(10, 2, 'offer', 'Beta', ['offer_code' => 'ZT002'])];
$eng = fn(array $r) => ussd_screen('*ZTE#', $r, ['active'], $nodes, ['seed' => 'ZT-seed', 'msisdn' => '220866000001', 'share' => 'ussd_share_sim']);
t('menus, back, invalid choices, * and #', function () use ($eng) {
    $s = $eng([]); eq($s['text'], "Main\n1. Bundles\n2. Help\n3. Buy for other", 'welcome screen'); eq($s['end'], false, 'welcome does not end');
    eq($eng(['1'])['kind'], 'menu', 'a submenu opens'); has($eng(['1', '0'])['text'], '1. Bundles', 'back returns to the parent');
    has($eng(['9'])['text'], 'Invalid choice.', 'an invalid number is refused'); eq($eng(['*1#'])['text'], $eng(['1'])['text'], '*1# means 1');
    eq($eng(['2'])['text'], 'Call 123 for help', "an end item shows its own screen text, not its label"); eq($eng(['2'])['end'], true, 'and ends the session');
});
t('catalogue lists: live, paged, cheapest first', function () use ($eng) {
    $s = $eng(['1', '1']); has($s['text'], '1. ZT Item 1 - D10', 'cheapest first'); has($s['text'], '5. ZT Item 5 - D50', 'five per screen'); has($s['text'], '6. More', 'More when there are more');
    $s2 = $eng(['1', '1', '6']); has($s2['text'], '1. ZT Item 6 - D60', 'page 2'); has($eng(['1', '1', '6', '6'])['text'], '2. ZT Item 12 - D120', 'last page'); lacks($eng(['1', '1', '6', '6'])['text'], 'More', 'no More on the last page');
    ok($s['chars'] <= USSD_MAX_CHARS, 'fits one phone screen');
    $same = $eng(['1', '3'])['text']; has($same, 'ZT Same ZT Cat C', 'same-name offers are told apart'); has($same, 'ZT Same ZT Cat D', 'by sub-category');
});
t('offers: confirm before buying, cancel, unavailable', function () use ($eng) {
    $c = $eng(['1', '2']); eq($c['kind'], 'confirm', 'asks to confirm'); has($c['text'], 'Buy ZT Alpha - D10 / 7 days?', 'shows name, price and validity');
    $p = $eng(['1', '2', '1']); eq($p['kind'], 'purchase', 'confirm leads to a purchase'); eq($p['purchase']['offer_code'], 'ZT001', 'for that offer'); eq($p['purchase']['other_offer_code'], 'ZTO01', 'with its other-number code'); eq($p['purchase']['vendor'], 'huawei', 'and vendor');
    eq($eng(['1', '2', '2'])['kind'], 'cancel', 'cancel buys nothing'); has($eng(['1', '2', '0'])['text'], 'Bundles', 'back from confirm returns to the list');
    eq($eng(['1', '4'])['kind'], 'offer_unavailable', 'an inactive offer is not for sale');
});
t('customers never see the 220 country code', function () {
    eq(ussd_local_number('220866520934'), '866520934', 'the country code is dropped'); eq(ussd_local_number('866520934'), '866520934', 'a local number is left alone'); eq(ussd_local_number('+220 866 520 934'), '866520934', 'whatever the format'); eq(ussd_quiz_mask('220866000001'), '866***001', 'the leaderboard shows local digits');
});
t('buy for another number', function () use ($eng, $nodes) {
    eq($eng(['3'])['kind'], 'recipient', 'asks for the number'); has($eng(['3', 'abc'])['text'], 'Invalid number.', 'refuses rubbish');
    $m = $eng(['3', '866520934']); has($m['text'], 'Buy for 866520934:', 'then the menu again (without the 220 country code)'); lacks($m['text'], 'Buy for other', 'without the buy-for-other item'); lacks($m['text'], 'Help', 'and without messages, which sell nothing'); has($m['text'], '1. Bundles', 'with the part that sells');
    // only what can really be bought for someone else is shown: offers that have an "other" code
    $l = $eng(['3', '866520934', '1'])['text']; has($l, '1. Alpha', 'an offer with an "other" code is listed'); has($l, '2. Cat A', 'a list holding at least one such offer is listed');
    lacks($l, 'Cat B', 'a list whose offers have no "other" code is not shown'); lacks($l, 'Same name', '(nor this one)'); lacks($l, 'Gone', 'an offer that is switched off is not shown'); lacks($l, 'Beta', 'an offer with no "other" code is not shown');
    $a = $eng(['3', '866520934', '1', '2'])['text']; has($a, 'ZT Alpha', 'inside a list: offers with an "other" code'); lacks($a, 'ZT Beta', 'and not the ones without');
    $p = $eng(['3', '866520934', '1', '1', '1']); eq($p['kind'], 'purchase', 'buying works'); eq($p['purchase']['recipient'], '220866520934', 'the purchase carries the other number'); eq($p['purchase']['recipient_raw'], '866520934', 'and the digits exactly as typed (Hera wants them that way)');
    has($eng(['3', '866520934', '1', '1'])['text'], 'for 866520934?', 'the confirm screen says who it is for, without the 220'); has($eng(['3', '866520934', '0'])['text'], '1. Bundles', 'back leaves it again');
    // for yourself nothing is hidden
    $self = $eng(['1'])['text']; foreach (['Cat B', 'Alpha', 'Same name', 'Cat A', 'Beta'] as $x) has($self, $x, 'for yourself "'.$x.'" is listed'); eq($eng(['1', '6'])['kind'], 'confirm', 'and the offer without an "other" code can be bought for yourself');
    // nothing sellable for others: say so instead of an empty screen
    $only = [$GLOBALS['mk'](1, null, 'menu', 'Main'), $GLOBALS['mk'](2, 1, 'recipient', 'Buy for other'), $GLOBALS['mk'](3, 1, 'offer', 'Beta', ['offer_code' => 'ZT002'])];
    $e = ussd_screen('*ZTX#', ['1', '866520934'], ['active'], $only, ['seed' => 's', 'msisdn' => '220866000001']); has($e['text'], 'Nothing here can be bought for another number.', 'an empty result is explained');
});

// ------------------------------------------------------------------ menu building tools (database)
echo "\nMenu tools\n";
$code = '*ZT1#';
t('import, export and the screens they give', function () use ($code) {
    $tree = [['prompt_text' => 'ZT Menu', 'node_type' => 'menu', 'status' => 'active', 'children' => [['prompt_text' => 'Pick', 'node_type' => 'catalog', 'status' => 'active', 'catalog_filter' => 'ZT Cat A', 'body_text' => 'Choose one:'], ['prompt_text' => 'Info', 'node_type' => 'end', 'status' => 'active', 'body_text' => 'All about it']]]];
    eq(import_menu_json($code, json_encode($tree))['nodes'], 3, 'three items imported');
    $s = screen($code, ['1']); has($s['text'], 'Choose one:', 'screen text is the header'); has($s['text'], 'ZT Alpha - D10', 'live offers listed'); lacks($s['text'], 'ZT Gone', 'an inactive offer is left out');
    eq(menu_export_tree($code)[0]['children'][1]['body_text'], 'All about it', 'export keeps screen text');
    thrown(fn() => import_menu_json($code, '[{"prompt_text":"x","node_type":"bogus"}]'), 'not a node type', 'a bad type is refused');
    thrown(fn() => import_menu_json($code, '[{"prompt_text":"x","node_type":"catalog"}]'), 'sub-categories', 'a catalogue list needs sub-categories');
});
t('quick add: types, rollback on a bad line, order', function () use ($code, $db) {
    $root = (int)$db->query("SELECT id FROM ussd_menu_nodes WHERE short_code='*ZT1#' AND parent_id IS NULL")->fetchColumn();
    eq(menu_quick_add($code, $root, "Three\nFour | end | Four text\nFive | catalog | ZT Cat A; ZT Cat B\nSix | offer | ZT001\nSeven | shared\nEight | other", 'draft'), 6, 'six lines added');
    $before = count(menu_nodes_flat($code));
    thrown(fn() => menu_quick_add($code, $root, "Fine\nBroken | catalog", 'draft'), 'Line 2', 'the bad line is named');
    eq(count(menu_nodes_flat($code)), $before, 'and nothing from that batch was added');
    thrown(fn() => menu_quick_add($code, $root, "X | nonsense", 'draft'), 'not a type', 'unknown type refused');
    $kids = array_values(array_filter(menu_nodes_flat($code), fn($n) => (int)$n['parent_id'] === $root)); eq(end($kids)['prompt_text'], 'Eight', 'new items go last');
});
t('move, duplicate, on/off, activate drafts', function () use ($code, $db) {
    $root = (int)$db->query("SELECT id FROM ussd_menu_nodes WHERE short_code='*ZT1#' AND parent_id IS NULL")->fetchColumn();
    $labels = fn() => array_column(array_values(array_filter(menu_nodes_flat($code), fn($n) => (int)$n['parent_id'] === $root)), 'prompt_text');
    $first = (int)$db->query("SELECT id FROM ussd_menu_nodes WHERE short_code='*ZT1#' AND prompt_text='Pick'")->fetchColumn();
    menu_move_node($first, 'down'); eq($labels()[1], 'Pick', 'moved down one place'); menu_move_node($first, 'up'); eq($labels()[0], 'Pick', 'and back up'); menu_move_node($first, 'up'); eq($labels()[0], 'Pick', 'moving the first one up changes nothing');
    $n = count(menu_nodes_flat($code)); menu_duplicate_node($first); eq(count(menu_nodes_flat($code)), $n + 1, 'a copy was added'); ok(in_array('Pick (copy)', $labels(), true), 'named as a copy'); eq($db->query("SELECT status FROM ussd_menu_nodes WHERE short_code='*ZT1#' AND prompt_text='Pick (copy)'")->fetchColumn(), 'draft', 'and it is a Draft');
    $drafts = (int)$db->query("SELECT COUNT(*) FROM ussd_menu_nodes WHERE short_code='*ZT1#' AND status='draft'")->fetchColumn(); eq(menu_activate_drafts($code), $drafts, 'every draft is turned on at once');
    menu_set_status($first, 'inactive'); eq($db->query("SELECT status FROM ussd_menu_nodes WHERE id=$first")->fetchColumn(), 'inactive', 'one item can be turned off'); menu_set_status($first, 'active');
});
t('history keeps every change and restores', function () use ($code, $db) {
    $v = (int)$db->query("SELECT MIN(id) FROM ussd_menu_versions WHERE short_code='*ZT1#'")->fetchColumn(); ok($v > 0, 'versions were kept');
    $latest = count(menu_nodes_flat($code)); eq(menu_restore_version($v), $code, 'restore names the short code'); ok(count(menu_nodes_flat($code)) < $latest, 'the earlier, smaller menu is back');
    ok((int)$db->query("SELECT COUNT(*) FROM ussd_menu_nodes WHERE short_code LIKE '*ZT1# [archived%'")->fetchColumn() > 0, 'and what it replaced is archived, not deleted');
    ok(!array_filter(menu_shortcodes(), fn($sc) => str_contains($sc, '[archived')), 'archives stay out of the short-code list');
});
t('copy a menu to another short code', function () use ($code) {
    $n = menu_copy_to($code, '*ZT2#', false); ok($n > 0, 'copied'); ok(!array_filter(menu_nodes_flat('*ZT2#'), fn($x) => $x['status'] === 'active'), 'as drafts only');
    thrown(fn() => menu_copy_to($code, '*ZT2#', false), 'already has a menu', 'will not replace without being told'); menu_copy_to($code, '*ZT2#', true);
});
t('the menu check finds real problems', function () use ($code, $db) {
    $h = fn(string $c) => implode(' || ', array_column(menu_health($c), 'msg'));
    $root = (int)$db->query("SELECT id FROM ussd_menu_nodes WHERE short_code='*ZT1#' AND parent_id IS NULL")->fetchColumn();
    menu_quick_add($code, $root, "Empty sub\nNo offers | catalog | ZT Nothing Here\nBad offer | offer | ZTNOPE", 'active');
    $db->prepare("INSERT INTO ussd_menu_nodes(parent_id,short_code,display_order,prompt_text,node_type,offer_code,status) VALUES(?,?,?,?,?,?,?)")->execute([$root, $code, 99, 'Quiz', 'quiz', 'zt_quiz_missing', 'active']);
    $m = $h($code); has($m, 'nothing Active inside', 'empty submenu'); has($m, 'none are Active', 'catalogue list with no active offers'); has($m, 'ZTNOPE', 'offer that is not for sale'); has($m, 'does not exist', 'quiz that is missing');
    ok(count(array_filter(menu_health($code), fn($x) => $x['level'] === 'error')) >= 4, 'these are errors, shown first'); eq(menu_health($code)[0]['level'], 'error', 'errors come before warnings');
    $long = '*ZT3#'; $items = ''; for ($i = 1; $i <= 12; $i++) $items .= "Item number $i with a long label here\n";
    menu_quick_add($long, null, 'Wrapper', 'active'); $w = (int)$db->query("SELECT id FROM ussd_menu_nodes WHERE short_code='*ZT3#'")->fetchColumn(); menu_quick_add($long, $w, $items, 'active');
    has($h($long), 'too long for one phone screen', 'a list that cannot fit a screen');
    menu_quick_add('*ZT6#', null, "Buy for other | other\nBeta | offer | ZT002", 'active'); has($h('*ZT6#'), 'nothing in this menu can be bought for another number', 'a Buy-for-other item with nothing to sell is flagged');
    eq(count(menu_health('*ZT-NONE#')), 1, 'an empty code just says so');
});

// ------------------------------------------------------------------ quiz
echo "\nQuiz\n";
t('quiz: set-up, deterministic games, scoring, replay, record', function () use ($db) {
    save_quiz(['quiz_key' => 'zt_quiz', 'title' => 'ZT Quiz', 'per_game' => 3, 'win_score' => 2, 'win_text' => 'You win!', 'status' => 'active']);
    $lines = ''; for ($i = 1; $i <= 8; $i++) $lines .= "Question number $i? | right $i | wrong $i | other $i | 1\n";
    eq(import_quiz_questions('zt_quiz', $lines), 8, 'questions imported'); thrown(fn() => import_quiz_questions('zt_quiz', "No separators here"), 'Line 1', 'a bad line is named');
    menu_quick_add('*ZT4#', null, "Games\nPlay | quiz | zt_quiz", 'active'); $hk = ['seed' => 'ZT-call-1'];
    has(screen('*ZT4#', ['2'], $hk)['text'], '1. Start', 'intro'); $q1 = screen('*ZT4#', ['2', '1'], $hk); has($q1['text'], 'Q1/3:', 'first question');
    eq(screen('*ZT4#', ['2', '1'], $hk)['text'], $q1['text'], 'the same call always rebuilds the same game');
    $g = ussd_quiz_questions('zt_quiz', 'ZT-call-1', 0, 3); eq(count($g), 3, 'three per game'); ok(array_column($g, 'id') !== array_column(ussd_quiz_questions('zt_quiz', 'ZT-call-2', 0, 3), 'id'), 'another call gets another mix');
    $win = screen('*ZT4#', ['2', '1', '1', '1', '1'], $hk); eq($win['kind'], 'quiz_done', 'a finished game'); has($win['text'], 'Score 3/3', 'all right'); has($win['text'], 'You win!', 'winner message'); has($win['text'], '1. Play again', 'offers another go');
    $lose = screen('*ZT4#', ['2', '1', '2', '2', '2'], $hk); has($lose['text'], 'Score 0/3', 'all wrong'); lacks($lose['text'], 'You win!', 'no winner message'); has($lose['text'], 'Wrong. Answer was 1.', 'says which was right');
    has(screen('*ZT4#', ['2', '1', '1', '1', '1', '1'], $hk)['text'], 'Q1/3:', 'play again starts a new round'); has(screen('*ZT4#', ['2', '1', '9'], $hk)['text'], 'Invalid choice.', 'an invalid answer is refused');
    ussd_quiz_record($win, '220866000001', 'ZT-call-1'); ussd_quiz_record($win, '220866000001', 'ZT-call-1'); eq((int)$db->query("SELECT COUNT(*) FROM ussd_quiz_plays WHERE call_id='ZT-call-1'")->fetchColumn(), 1, 'a round is recorded once however often it is repeated');
    has(screen('*ZT4#', ['2', '2'], $hk)['text'], 'Top players this week', 'leaderboard'); has(screen('*ZT4#', ['2', '2'], $hk)['text'], '866***001', 'with numbers masked and no 220');
});

// ------------------------------------------------------------------ purchases against the stand-in Hera
echo "\nPurchases\n";
$cfgFor = fn(array $o) => array_merge(ussd_proxy_config(), $o);
$p = ['offer_code' => 'ZT001', 'name' => 'ZT Alpha', 'price' => '10', 'vendor' => 'huawei', 'other_offer_code' => 'ZTO01', 'recipient' => ''];
$run = fn(array $cfg, array $purchase, string $call, array $ctx = []) => ussd_execute_purchase($cfg, ['purchase' => $purchase], '220866000001', $call, $ctx);
t('purchase modes: off, test, test (low balance)', function () use ($cfgFor, $p, $run, $db) {
    has($run($cfgFor(['purchase_mode' => 'off']), $p, 'ZT-p0')['text'], 'not switched on', 'off buys nothing');
    $t = $run($cfgFor(['purchase_mode' => 'test']), $p, 'ZT-p1'); has($t['text'], 'TEST:', 'test says so'); has($t['text'], 'You were not charged', 'and that nothing was charged');
    has($run($cfgFor(['purchase_mode' => 'test_low']), $p, 'ZT-p2')['text'], 'balance is too low', 'low-balance rehearsal');
    eq($db->query("SELECT status FROM ussd_purchases WHERE call_id='ZT-p1'")->fetchColumn(), 'test', 'recorded');
});
t('live purchases: success word, low balance, errors, once per call', function () use ($cfgFor, $p, $run, $stub, $lastBody, $db) {
    $live = fn(string $scn, array $x = []) => $cfgFor($x + ['purchase_mode' => 'live', 'purchase_url' => "$stub/purchase/$scn", 'purchase_auth' => encrypt_secret("X-API-KEY: k-123\nX-USERNAME: USSD"), 'purchase_body' => USSD_PURCHASE_BODY, 'purchase_ok_match' => '', 'purchase_lowbal' => 'insufficient,low balance']);
    $ctx = ['imsi' => '607036003160087', 'localDialogID' => 11, 'remoteDialogID' => 22, 'localAddress' => ['digits' => '220609899931'], 'remoteAddress' => ['digits' => '220609899901']];
    $r = $run($live('ok'), $p, 'ZT-p3', $ctx); has($r['text'], 'has been purchased', "no success word set: Hera's own result code 0 means success"); eq($db->query("SELECT status FROM ussd_purchases WHERE call_id='ZT-p3'")->fetchColumn(), 'ok', 'recorded as ok');
    has($run($live('bare'), $p, 'ZT-p3b', $ctx)['text'], 'has been sent', 'a reply with no result code and no success word is only "sent"'); eq($db->query("SELECT status FROM ussd_purchases WHERE call_id='ZT-p3b'")->fetchColumn(), 'sent', 'recorded as sent');
    $b = $lastBody('/purchase/ok'); eq($b['json']['offerCode'] ?? null, 'ZT001', 'offer code sent'); eq($b['json']['chargesWithCurrency'] ?? null, 'D10', 'price with the D'); eq($b['json']['imsi'] ?? null, '607036003160087', "the caller's IMSI is forwarded"); eq($b['json']['operation'] ?? null, 'purchaseOffer', 'operation'); eq($b['json']['otherOfferCode'] ?? null, 'ZTO01', 'other offer code'); eq($b['json']['localAddress']['digits'] ?? null, '220609899931', 'SS7 address forwarded'); eq($b['headers']['x-api-key'], 'k-123', 'headers sent'); eq($b['headers']['x-username'], 'USSD', 'both of them');
    has($run($live('ok', ['purchase_ok_match' => 'subscription is successful']), $p, 'ZT-p4', $ctx)['text'], 'has been purchased', 'with the success word it says purchased');
    has($run($live('ok', ['purchase_ok_match' => 'no such words']), $p, 'ZT-p5', $ctx)['text'], 'could not be completed', 'a reply without the success word is a failure');
    has($run($live('low'), $p, 'ZT-p6', $ctx)['text'], 'balance is too low', 'Hera low-balance reply is recognised'); eq($db->query("SELECT status FROM ussd_purchases WHERE call_id='ZT-p6'")->fetchColumn(), 'lowbal', 'and recorded');
    $d = $run($live('deduct'), $p, 'ZT-p7d', $ctx); has($d['text'], 'Deduction for Subscription failed', "when Hera refuses for its own reason, the customer is told that reason"); eq($db->query("SELECT status FROM ussd_purchases WHERE call_id='ZT-p7d'")->fetchColumn(), 'failed', 'recorded as failed');
    $req = (string)$db->query("SELECT request_body FROM ussd_purchases WHERE call_id='ZT-p7d'")->fetchColumn(); has($req, '"offerCode":"ZT001"', 'the request we sent is kept in the ledger'); has($req, '"imsi":"607***087"', 'with the IMSI shortened'); lacks($req, 'k-123', 'and never any key');
    $e = $run($live('err500'), $p, 'ZT-p7', $ctx); has($e['text'], 'could not be completed', 'a server error is a polite failure'); lacks($e['text'], 'Internal', 'with no server text shown');
    $before = file_get_contents(sys_get_temp_dir().'/zt_stub_last_'.md5('/purchase/ok').'.json'); unlink(sys_get_temp_dir().'/zt_stub_last_'.md5('/purchase/ok').'.json');
    $again = $run($live('ok'), $p, 'ZT-p3', $ctx); ok(!is_file(sys_get_temp_dir().'/zt_stub_last_'.md5('/purchase/ok').'.json'), 'the same call and offer is never sent twice'); has($again['note'], 'repeated', 'and says so');
    $o = $p; $o['recipient'] = '220866111999'; has($run($live('ok', ['purchase_body_other' => '']), $o, 'ZT-p8', $ctx)['text'], 'not switched on', 'buying for another number is blocked until its body is saved');
    $run($live('ok', ['purchase_body_other' => '{"msisdn":"{msisdn}","otherMsisdn":"{other_msisdn}","offerCode":"{other_offer_code}"}']), $o, 'ZT-p9', $ctx); $b2 = $lastBody('/purchase/ok'); eq($b2['json']['otherMsisdn'] ?? null, '220866111999', 'for another number: the other number goes in'); eq($b2['json']['offerCode'] ?? null, 'ZTO01', 'with its own offer code');
    // the standard body (copied from Mobius's own request) is what a never-edited setup uses
    $o['recipient_raw'] = '866111999'; $run($live('ok', ['purchase_body_other' => USSD_PURCHASE_BODY_OTHER]), $o, 'ZT-p10', $ctx); $b3 = $lastBody('/purchase/ok')['json'];
    eq($b3['otherMsisdn'] ?? null, '866111999', 'Mobius-style: otherMsisdn exactly as typed'); eq($b3['offerCode'] ?? null, 'ZT001', 'the base offer code stays'); eq($b3['otherOfferCode'] ?? null, 'ZTO01', 'plus the other-number code'); eq($b3['operation'] ?? null, 'purchaseOffer', 'same operation'); eq($b3['chargesWithCurrency'] ?? null, 'D10', 'and the price'); eq($b3['imsi'] ?? null, '607036003160087', 'and the caller context');
    ussd_proxy_set(['purchase_body_other' => '', 'purchase_other_seeded' => '0']); has(ussd_proxy_config()['purchase_body_other'], 'otherMsisdn', 'a never-saved blank uses the standard body'); ussd_proxy_set(['purchase_other_seeded' => '1']); eq(ussd_proxy_config()['purchase_body_other'], '', 'once the card has been saved, blank means off again');
});
t('saving purchase settings: headers are forgiving', function () use ($db) {
    $base = ['purchase_mode' => 'test', 'purchase_url' => '', 'purchase_body' => USSD_PURCHASE_BODY, 'purchase_timeout' => '8'];
    $h = function (string $in) use ($base) { save_purchase_config($base + ['purchase_auth' => $in]); return decrypt_secret(ussd_proxy_config()['purchase_auth']); };
    eq($h("X-API-KEY: abc\nX-USERNAME: USSD"), "X-API-KEY: abc\nX-USERNAME: USSD", 'plain'); eq($h("X-API-KEY: abc,\r\nX-USERNAME: 'USSD'"), "X-API-KEY: abc\nX-USERNAME: USSD", 'trailing comma, quotes and Windows line ends'); eq($h("X-API-KEY: abc\nX-HASHED-PASSWORD:"), 'X-API-KEY: abc', 'an empty header is skipped');
    thrown(fn() => $h('just-a-bare-key'), 'Line 1', 'a line with no name is named by number, not echoed'); thrown(fn() => $h("X-HASHED-PASSWORD:"), 'None of the header lines', 'all-empty refused');
    try { $h('secret-value-123'); } catch (Throwable $e) { lacks($e->getMessage(), 'secret-value-123', 'the error never repeats the value'); }
});

// ------------------------------------------------------------------ Shared Bundle
echo "\nShared Bundle\n";
$cfgS = fn(string $scn, array $x = []) => $cfgFor($x + ['share_mode' => 'live', 'share_base' => "$stub/sb/$scn/", 'purchase_auth' => encrypt_secret('X-USERNAME: USSD'), 'purchase_lowbal' => 'insufficient,low balance',
    'share_body_subscribe' => SHARE_BODY_SUBSCRIBE, 'share_body_add' => SHARE_BODY_ADD, 'share_body_balance' => SHARE_BODY_READ, 'share_body_numbers' => SHARE_BODY_NUMBERS, 'shortcode_proxy' => '*9606*9090#']);
$sctx = ['imsi' => '607036003160087', 'localDialogID' => 5, 'remoteDialogID' => 6, 'localAddress' => ['digits' => '220609899931'], 'remoteAddress' => ['digits' => '220609899901']];
t('the service flow (simulated answers)', function () {
    menu_quick_add('*ZT5#', null, "Special\nShared Bundle | shared | ZT Seddo", 'active'); $v = fn(array $r) => screen('*ZT5#', $r);
    eq($v(['2'])['text'], "Shared Bundle\n1. Buy Shared Bundle\n2. Add Sharing Number\n3. My Account\n0. Exit", 'service menu');
    has($v(['2', '1'])['text'], 'Press 1 to subscribe to Seddo 12GB Seddo for D600, valid for 30 days, or press 0 to return to the menu.', 'buy: the confirm text from the diagram');
    eq($v(['2', '1', '1'])['kind'], 'share_subscribe', 'confirming subscribes'); has($v(['2', '1', '0'])['text'], '3. My Account', '0 goes back one step'); has($v(['2', '1', '0', '0'])['text'], 'Special', 'and out of the service');
    has($v(['2', '2'])['text'], 'Enter the beneficiary Seddo number:', 'add number: asks for it'); has($v(['2', '2', 'abc'])['text'], 'Invalid number.', 'refuses rubbish'); has($v(['2', '2', '866671144'])['text'], 'Seddo number 866671144 is correct', 'asks to confirm'); $a = $v(['2', '2', '866671144', '1']); eq($a['kind'], 'share_add', 'confirming adds'); eq($a['share']['other_raw'], '866671144', 'keeping the digits as typed for Hera');
    has($v(['2', '3'])['text'], '1. Check Balance', 'account menu'); has($v(['2', '3', '1'])['text'], 'Your Seddo bundle balance is:', 'balance'); has($v(['2', '3', '2'])['text'], 'My Seddo numbers:', 'numbers');
});
t('balance and numbers read from Hera', function () use ($cfgS, $stub) {
    $b = ussd_share_http($cfgS('ok'), 'balance', ['msisdn' => '220866000001', 'txn' => 'ZT-b']); ok($b['ok'], 'balance succeeded'); eq($b['text'], "Your Seddo bundle balance is:\nPackage Free Data remains 482.20MB expires on 19/10/26\nPackage Free Data remains 56.97MB expires on 11/10/26", 'one line per bundle, empty ones left out'); ok(mb_strlen($b['text']) < 150, 'fits a screen');
    $n = ussd_share_http($cfgS('ok'), 'numbers', ['msisdn' => '220866000001', 'txn' => 'ZT-n']); eq($n['text'], "1. 866111222 - 3GB\n2. 866333444 - 2GB", 'numbers as "order number separator limit"'); eq(ussd_share_http($cfgS('empty'), 'numbers', ['msisdn' => '1', 'txn' => 't'])['text'], "You don't have Seddo number", 'no numbers says so');
    eq(ussd_share_http($cfgS('err500'), 'balance', ['msisdn' => '1', 'txn' => 't'])['text'], '', 'a server error gives no text for the customer');
});
t('subscribe and add number: real shapes, honest answers, once', function () use ($cfgS, $sctx, $lastBody, $db) {
    $sub = ['share' => ['op' => 'subscribe', 'offer_code' => 'ZTSED', 'name' => '12GB Seddo', 'price' => '600', 'vendor' => 'huawei', 'other_offer_code' => '']]; $add = ['share' => ['op' => 'add', 'other' => '220866671144', 'other_raw' => '866671144']];
    $ex = fn(array $cfg, array $scr, string $call) => ussd_share_execute($cfg, $scr, '220866000001', $call, $GLOBALS['sctx']);
    $r = $ex($cfgS('ok'), $sub, 'ZT-s1'); has($r['text'], 'successful', 'success shows Hera text'); $b = $lastBody('/sb/ok/subscribe')['json']; eq($b['offerCode'] ?? null, 'ZTSED', 'offer sent'); eq($b['chargesWithCurrency'] ?? null, 'D600', 'price sent'); eq($b['operation'] ?? null, 'purchaseOffer', 'operation sent'); eq($b['imsi'] ?? null, '607036003160087', 'IMSI forwarded'); eq($b['vendor'] ?? null, 'huawei', 'vendor');
    has($ex($cfgS('lowbal'), $sub, 'ZT-s2')['text'], 'balance is too low', 'low balance gets a plain message'); eq($db->query("SELECT status FROM ussd_purchases WHERE call_id='ZT-s2'")->fetchColumn(), 'lowbal', 'recorded');
    has($ex($cfgS('nosub'), $add, 'ZT-s3')['text'], "You don't have active Seddo Subscription", "Hera's own reason is shown"); $r2 = $ex($cfgS('ok'), $add, 'ZT-s4'); $b2 = $lastBody('/sb/ok/addNumber')['json']; eq($b2['otherMsisdn'] ?? null, '866671144', 'the other number is sent as typed'); eq($b2['vendor'] ?? null, 'huawei', 'with the vendor');
    $err = $ex($cfgS('err500'), $add, 'ZT-s5'); has($err['text'], 'could not be completed', 'a server error is polite'); lacks($err['text'], 'status', 'and shows no server text');
    has($ex($cfgS('ok', ['share_body_subscribe' => '']), $sub, 'ZT-s6')['text'], 'not switched on', 'a blank body blocks the call'); has($ex($cfgS('ok', ['share_mode' => 'off']), $sub, 'ZT-s7')['text'], 'not switched on', 'off is off');
    $t = $ex($cfgS('ok', ['share_mode' => 'test']), $sub, 'ZT-s8'); has($t['text'], 'TEST:', 'test mode is simulated'); has($ex($cfgS('ok'), $sub, 'ZT-s1')['note'], 'repeated', 'the same call is never repeated');
});
t('older stored bodies are upgraded, edited ones kept', function () {
    ussd_proxy_set(['share_body_add' => SHARE_OLD_DEFAULTS[2], 'share_body_subscribe' => SHARE_BODY_SUBSCRIBE_V2, 'share_body_numbers' => SHARE_BODY_READ, 'share_body_balance' => 'my own {msisdn}']); $c = ussd_proxy_config();
    has($c['share_body_add'], 'imsi', 'add upgraded'); has($c['share_body_subscribe'], 'purchaseOffer', 'subscribe upgraded'); has($c['share_body_numbers'], '"vendor":"huawei"', 'numbers upgraded'); eq($c['share_body_balance'], 'my own {msisdn}', 'a body somebody edited is left alone');
});

// ------------------------------------------------------------------ the endpoint serves whichever code was dialled
echo "\nEndpoint\n";
t('serving a dialled short code', function () {
    $cfg = ussd_proxy_config(); ok(in_array('*ZT5#', menu_shortcodes(), true), 'a code with a menu is known'); ok(!in_array('*ZT-NONE#', menu_shortcodes(), true), 'one without is not');
});

t('direct codes jump straight into the menu', function () {
    $known = ['*ZT5#', '*ZT5*9#'];
    eq(ussd_resolve_dialled('*ZT5#', $known), ['*ZT5#', []], 'the short code itself is unchanged');
    eq(ussd_resolve_dialled('*ZT5*2#', $known), ['*ZT5#', ['2']], 'one extra choice');
    eq(ussd_resolve_dialled('*ZT5*2*1#', $known), ['*ZT5#', ['2', '1']], 'two extra choices');
    eq(ussd_resolve_dialled('*ZT5*9#', $known), ['*ZT5*9#', []], 'a code that has its own menu wins');
    eq(ussd_resolve_dialled('*ZT5*9*3#', $known), ['*ZT5*9#', ['3']], 'the longest known code is used');
    eq(ussd_resolve_dialled('*ZT5*x#', $known), null, 'letters are not choices'); eq(ussd_resolve_dialled('*OTHER*1#', $known), null, 'an unknown code is not ours'); eq(ussd_resolve_dialled('', $known), null, 'nothing dialled');
    menu_quick_add('*ZT8#', null, "Alpha
Beta", 'active'); $d = menu_direct_codes('*ZT8#'); ok(in_array('*ZT8*1#', $d, true) && in_array('*ZT8*2#', $d, true), 'every item gets a direct code');
    [$sc, $x] = ussd_resolve_dialled('*ZT8*2#', ['*ZT8#']); eq(screen($sc, $x)['text'], screen('*ZT8#', ['2'])['text'], 'dialling *ZT8*2# shows what typing 2 shows');
});
// ------------------------------------------------------------------ service flows
echo "\nService flows\n";
$loanDef = ['start' => 'main', 'steps' => [
    'main' => ['type' => 'choices', 'text' => 'Welcome to menu', 'options' => [['label' => 'Purchase offer', 'next' => 'pick'], ['label' => 'Check balance', 'next' => 'bal']]],
    'pick' => ['type' => 'offers', 'text' => 'Choose:', 'sub_categories' => ['ZT Cat A'], 'next' => 'confirm', 'auto_single' => false],
    'confirm' => ['type' => 'confirm', 'text' => "You are purchasing {offer.name}\n1. Confirm\n2. Cancel", 'yes' => 'buy', 'no' => 'main'],
    'buy' => ['type' => 'call', 'connection' => 'zt_low', 'path' => 'buy', 'body' => '{"callID":"{txn}","msisdn":"{msisdn}","imsi":"{imsi}","offerCode":"{offer.code}","chargesWithCurrency":"{offer.price_d}"}', 'outcomes' => ['success' => 'ok', 'lowbal' => 'loan_offer', 'fail' => 'failed']],
    'ok' => ['type' => 'message', 'text' => 'Your subscription is successful'],
    'loan_offer' => ['type' => 'confirm', 'text' => "You don't have enough balance for {offer.name}. You are eligible to take a loan and subscribe.\n1. Confirm\n2. Cancel", 'yes' => 'loan_do', 'no' => 'main'],
    'loan_do' => ['type' => 'call', 'connection' => 'zt_ok', 'path' => 'loan', 'body' => '{"msisdn":"{msisdn}","offerCode":"{offer.code}"}', 'outcomes' => ['success' => 'loan_ok', 'lowbal' => 'failed', 'fail' => 'failed']],
    'loan_ok' => ['type' => 'message', 'text' => 'The loan has been taken and your subscription is successful'],
    'failed' => ['type' => 'message', 'text' => 'Subscription failed'],
    'bal' => ['type' => 'lookup', 'text' => 'Your balance is: {result.balance}', 'connection' => '', 'path' => '', 'body' => ''],
]];
$offerRows = [['offer_code' => 'ZT001', 'name' => 'ZT Alpha', 'one_time_price' => 10]];
$fh = fn(string $outcome) => ['call' => flow_sim_call_hook($outcome), 'lookup' => 'flow_sim_lookup', 'offers' => fn($step) => $offerRows, 'offer' => fn($c) => ['name' => 'ZT Alpha', 'one_time_price' => 10, 'validity_amount' => 7, 'vendor' => 'huawei', 'offer_code_for_other' => 'ZTO01'], 'msisdn' => '220866000001'];
$fs = fn(array $r, string $outcome = 'success') => flow_screen($GLOBALS['loanDef'], $r, $fh($outcome));
t('a flow is checked before it is used', function () use ($loanDef) {
    eq(array_values(array_filter(flow_validate($loanDef), fn($x) => $x['level'] === 'error')), [], 'the loan flow has nothing to fix');
    $bad = $loanDef; $bad['steps']['confirm']['yes'] = 'nowhere'; $bad['steps']['pick']['sub_categories'] = []; unset($bad['steps']['buy']['outcomes']['success']);
    $m = implode(' || ', array_column(flow_validate($bad), 'msg')); has($m, 'leads to "nowhere", which does not exist', 'a step that leads nowhere is named'); has($m, 'choose the sub-category', 'an offers step needs a sub-category'); has($m, 'say what happens on success', 'a call needs a success path');
    $orphan = $loanDef; $orphan['steps']['lost'] = ['type' => 'message', 'text' => 'x']; has(implode('|', array_column(flow_validate($orphan), 'msg')), 'cannot be reached', 'a step nobody can reach is mentioned');
    $unk = $loanDef; $unk['steps']['ok']['text'] = 'Hello {nobody}'; has(implode('|', array_column(flow_validate($unk), 'msg')), '{nobody}', 'a placeholder that will be empty is mentioned');
});
t('flow screens and the branches after a call', function () use ($fs) {
    eq($fs([])['text'], "Welcome to menu\n1. Purchase offer\n2. Check balance\n0. Back", 'first screen');
    has($fs(['1'])['text'], '1. ZT Alpha - D10', 'the offers list'); has($fs(['1', '1'])['text'], "You are purchasing ZT Alpha\n1. Confirm\n2. Cancel", 'the offer chosen fills the text');
    eq($fs(['1', '1', '1'], 'success')['text'], 'Your subscription is successful', 'success: the success message'); eq($fs(['1', '1', '1'], 'success')['end'], true, 'and the session closes');
    $low = $fs(['1', '1', '1'], 'lowbal'); has($low['text'], "You don't have enough balance for ZT Alpha", 'low balance: the loan offer'); eq($low['end'], false, 'which asks for an answer');
    eq($fs(['1', '1', '1', '1'], 'lowbal,success')['text'], 'The loan has been taken and your subscription is successful', 'accepting it takes the loan (the second call works)'); eq($fs(['1', '1', '1', '1'], 'lowbal,fail')['text'], 'Subscription failed', 'and if the loan call fails, the failure message');
    has($fs(['1', '1', '1', '2'], 'lowbal')['text'], 'Welcome to menu', 'declining it goes back to the start'); eq($fs(['1', '1', '1'], 'fail')['text'], 'Subscription failed', 'any other failure: the failure message');
    has($fs(['1', '1', '2'])['text'], 'Welcome to menu', 'cancel at the confirm screen'); has($fs(['1', '0'])['text'], 'Welcome to menu', '0 goes back one step'); eq($fs(['0']), ['exit_at' => 1], '0 at the very first screen leaves the flow');
    has($fs(['9'])['text'], 'Invalid choice.', 'an invalid choice is refused'); has($fs(['2'])['text'], 'Your balance is: D 100', 'a look-up fills {result.balance}');
    $p = $fs(['1', '1', '1'], 'success'); ok(!isset($p['pending']), 'with an outcome available there is nothing left to run');
});
t('a call nobody has run yet is handed back to be run once', function () use ($loanDef, $fh) {
    $h = $fh('success'); $h['call'] = fn($a, $b, $c, $d) => null; $r = flow_screen($loanDef, ['1', '1', '1'], $h);
    eq($r['pending']['step'], 'buy', 'the call to run is named'); eq($r['pending']['vars']['offer.code'], 'ZT001', 'with what the customer picked'); eq($r['pending']['key'], 'buy#1', 'and a key for this visit');
});
t('asking for a number, and the other blocks', function () {
    $def = ['start' => 'a', 'steps' => ['a' => ['type' => 'ask', 'text' => 'Enter the number:', 'kind' => 'phone', 'var' => 'other', 'next' => 'c'], 'c' => ['type' => 'confirm', 'text' => 'Is {other_local} right? ({other_raw}) 1 yes', 'yes' => 'm', 'no' => 'a'], 'm' => ['type' => 'message', 'text' => 'Done for {other}']]];
    $r = fn(array $x) => flow_screen($def, $x, []); has($r(['abc'])['text'], 'Invalid number.', 'phone check'); eq($r(['866520934'])['text'], 'Is 866520934 right? (866520934) 1 yes', 'local number shown, raw kept'); eq($r(['866520934', '1'])['text'], 'Done for 220866520934', 'the full number is available to calls'); has($r(['866520934', '2'])['text'], 'Enter the number:', 'no goes back to ask again');
    $d2 = ['start' => 'a', 'steps' => ['a' => ['type' => 'ask', 'text' => 'PIN?', 'kind' => 'digits', 'var' => 'pin', 'next' => 'm'], 'm' => ['type' => 'message', 'text' => 'PIN {pin}']]]; has(flow_screen($d2, ['12x'], [])['text'], 'Invalid entry.', 'digits check'); eq(flow_screen($d2, ['1234'], [])['text'], 'PIN 1234', 'digits kept');
    $d3 = ['start' => 'a', 'steps' => ['a' => ['type' => 'offers', 'text' => 'Pick', 'sub_categories' => ['x'], 'next' => 'm', 'auto_single' => true], 'm' => ['type' => 'message', 'text' => 'You chose {offer.name} {offer.price_d}']]];
    $h = ['offers' => fn($s) => [['offer_code' => 'A', 'name' => 'Only one', 'one_time_price' => 5]], 'offer' => fn($c) => ['name' => 'Only one', 'one_time_price' => 5]];
    eq(flow_screen($d3, [], $h)['text'], 'You chose Only one D5', 'a single offer is chosen for the customer'); $d3['steps']['a']['auto_single'] = false; has(flow_screen($d3, [], $h)['text'], '1. Only one - D5', 'unless the list is wanted');
    $many = array_map(fn($i) => ['offer_code' => "O$i", 'name' => "Offer $i", 'one_time_price' => $i], range(1, 8)); $h2 = ['offers' => fn($s) => $many, 'offer' => fn($c) => ['name' => 'x', 'one_time_price' => 1]];
    $d3['steps']['a']['auto_single'] = true; has(flow_screen($d3, [], $h2)['text'], '6. More', 'long lists are paged'); has(flow_screen($d3, ['6'], $h2)['text'], '1. Offer 6 - D6', 'to the next page');
});
t('a flow opened from a menu, and back out of it', function () use ($loanDef) {
    flow_tables(); save_flow(['flow_key' => 'zt_loan', 'title' => 'ZT Loan']); flow_save_def('zt_loan', $loanDef, 'test');
    menu_quick_add('*ZT7#', null, "Home\nLoan service | flow | zt_loan", 'active'); $sim = fn(array $r, string $o = 'success') => screen('*ZT7#', $r, ['flow_sim' => $o]);
    has($sim(['2'])['text'], 'Welcome to menu', 'the menu item opens the flow'); eq($sim(['2'])['kind'], 'flow', 'as a flow screen'); has($sim(['2', '1', '1', '1'], 'lowbal')['text'], "enough balance", 'and follows its branches');
    has($sim(['2', '0'])['text'], '2. Loan service', '0 at the flow\'s first screen returns to the menu'); has($sim(['2', '0', '2', '1'])['text'], 'ZT Alpha', 'and the menu carries on working after that');
    thrown(fn() => save_menu_node(['short_code' => '*ZT7#', 'prompt_text' => 'X', 'node_type' => 'flow', 'flow_key' => 'zt_nope']), 'service flow', 'an item cannot open a flow that does not exist');
    $h = implode(' || ', array_column(menu_health('*ZT7#'), 'msg')); has($h, 'TEST mode', 'the menu check says the flow is only rehearsing');
    db_exec_flow_status('zt_loan', 'inactive'); eq(screen('*ZT7#', ['2'], ['flow_sim' => 'success'])['text'], 'This service is not available right now.', 'a flow that is switched off says so'); has(implode('|', array_column(menu_health('*ZT7#'), 'msg')), 'switched off', 'and the menu check flags it'); db_exec_flow_status('zt_loan', 'active');
});
function db_exec_flow_status(string $k, string $st): void { portal_pdo()->prepare('UPDATE ussd_flows SET status=? WHERE flow_key=?')->execute([$st, $k]); }
t('connections keep their headers encrypted', function () use ($db, $stub) {
    foreach (['zt_low' => 'lowbal', 'zt_ok' => 'ok'] as $k => $scn) save_flow_connection(['conn_key' => $k, 'title' => "ZT $scn", 'base_url' => "$stub/sb/$scn", 'headers' => "X-API-KEY: zt-key-777,\nX-USERNAME: USSD\nX-EMPTY:", 'ok_code' => '0', 'lowbal' => 'insufficient,low balance', 'timeout' => 5]);
    $row = $db->query("SELECT headers_enc, base_url FROM ussd_connections WHERE conn_key='zt_low'")->fetch(); lacks((string)$row['headers_enc'], 'zt-key-777', 'the key is not stored in clear'); eq(flow_connection('zt_low')['headers'], "X-API-KEY: zt-key-777\nX-USERNAME: USSD", 'but reads back, tidied');
    eq($row['base_url'], "$stub/sb/lowbal/", 'the address always ends with a slash'); save_flow_connection(['conn_key' => 'zt_low', 'title' => 'ZT lowbal', 'base_url' => "$stub/sb/lowbal", 'headers' => '', 'ok_code' => '0', 'timeout' => 5]); eq(flow_connection('zt_low')['headers'], "X-API-KEY: zt-key-777\nX-USERNAME: USSD", 'saving again with the headers box empty keeps them');
    thrown(fn() => save_flow_connection(['conn_key' => 'zt_bad', 'title' => 'x', 'base_url' => 'not a url']), 'full http', 'a bad address is refused'); thrown(fn() => save_flow_connection(['conn_key' => 'zt_bad', 'title' => 'x', 'base_url' => 'http://x.y/', 'headers' => 'bare-key-value']), 'Line 1', 'a bad header line is named by number');
});
t('live: the call runs once, its outcome picks the next step', function () use ($db, $stub, $lastBody) {
    $ctx = ['imsi' => '607036003160087', 'localDialogID' => 1, 'remoteDialogID' => 2, 'localAddress' => ['digits' => '220609899931'], 'remoteAddress' => ['digits' => '220609899901']];
    $go = function (array $replies, string $call, string $mode = 'live') use ($ctx) { // what the endpoint does: build the screen, run any call that is waiting, build it again
        $hk = ['seed' => $call, 'msisdn' => '220866000001', 'ctx' => $ctx]; $mk = fn() => screen('*ZT7#', $replies, $hk); $sc = $mk();
        for ($i = 0; $i < 5 && $sc['kind'] === 'flow_call'; $i++) { flow_execute_call(flow_get($sc['flow_key']), $sc['flow_pending'], $call, '220866000001', $ctx, '*ZT7#'); $sc = $mk(); } return $sc;
    };
    portal_pdo()->prepare("UPDATE ussd_flows SET mode='live' WHERE flow_key='zt_loan'")->execute();
    $path = '/sb/lowbal/buy'; $file = sys_get_temp_dir().'/zt_stub_last_'.md5($path).'.json'; @unlink($file);
    $r = $go(['2', '1', '1', '1'], 'ZT-fl1'); has($r['text'], "You don't have enough balance", 'the real low-balance reply leads to the loan offer'); $b = $lastBody($path); eq($b['json']['offerCode'] ?? null, 'ZT001', 'the picked offer is in the request'); eq($b['json']['chargesWithCurrency'] ?? null, 'D10', 'with its price'); eq($b['json']['imsi'] ?? null, '607036003160087', "and the caller's IMSI"); eq($b['headers']['x-api-key'], 'zt-key-777', 'the connection headers went with it');
    @unlink($file); $again = $go(['2', '1', '1', '1'], 'ZT-fl1'); eq($again['text'], $r['text'], 'repeating the same replies shows the same screen'); ok(!is_file($file), 'and does not call again');
    $loan = $go(['2', '1', '1', '1', '1'], 'ZT-fl1'); eq($loan['text'], 'The loan has been taken and your subscription is successful', 'the second call (loan) succeeds'); eq((int)$db->query("SELECT COUNT(*) FROM ussd_flow_calls WHERE call_id='ZT-fl1'")->fetchColumn(), 2, 'both calls are recorded'); eq($db->query("SELECT outcome FROM ussd_flow_calls WHERE call_id='ZT-fl1' AND step_key='buy#1'")->fetchColumn(), 'lowbal', 'with their outcomes');
    $req = (string)$db->query("SELECT request_body FROM ussd_flow_calls WHERE call_id='ZT-fl1' AND step_key='buy#1'")->fetchColumn(); has($req, '"imsi":"607***087"', 'the request is kept, IMSI shortened'); lacks($req, 'zt-key', 'never a key');
    // an unconfigured call stays off in live mode
    $def = flow_get('zt_loan')['def']; $def['steps']['buy']['connection'] = ''; flow_save_def('zt_loan', $def, 'test: unconfigured'); has($go(['2', '1', '1', '1'], 'ZT-fl2')['text'], 'Subscription failed', 'an unconfigured call does not call out and takes the failure path');
    eq($db->query("SELECT outcome FROM ussd_flow_calls WHERE call_id='ZT-fl2'")->fetchColumn(), 'fail', 'recorded as a failure'); $def['steps']['buy']['connection'] = 'zt_low'; flow_save_def('zt_loan', $def, 'test: restored');
    // test mode never calls out: it plays the chosen outcome
    portal_pdo()->prepare("UPDATE ussd_flows SET mode='test', test_outcome='fail' WHERE flow_key='zt_loan'")->execute(); @unlink($file); eq($go(['2', '1', '1', '1'], 'ZT-fl3')['text'], 'Subscription failed', 'test mode plays the rehearsal outcome'); ok(!is_file($file), 'without calling anything');
    portal_pdo()->prepare("UPDATE ussd_flows SET mode='test', test_outcome='success' WHERE flow_key='zt_loan'")->execute();
});
t('replies are classified like the real ones', function () {
    $c = ['ok_code' => '0', 'lowbal' => 'insufficient,low balance'];
    $cl = fn(string $raw, int $code = 200) => flow_classify($c, ['raw' => $raw, 'code' => $code, 'error' => '']);
    eq($cl('{"resultCode":"000","resultDescription":"Success","result":{"resultCode":"0","resultDescription":"\nYour subscription is successful"}}')['outcome'], 'success', 'result code 0 is success');
    eq($cl('{"resultCode":"000","resultDescription":"Success","result":{"resultCode":"20000005","resultDescription":"\n Low balance"}}')['outcome'], 'lowbal', 'low balance words');
    eq($cl('{"resultCode":"000","resultDescription":"Success","result":{"resultCode":"-1","resultDescription":"\nDeduction for Subscription failed"}}')['outcome'], 'fail', 'any other refusal'); eq($cl('{"resultCode":"9990","resultDescription":"No subscription"}')['outcome'], 'fail', 'a top-level refusal');
    eq($cl('{"x":1}', 500)['outcome'], 'fail', 'a server error'); $v = $cl('{"resultCode":"000","result":{"resultCode":"0","package1":"10GB left"}}')['vars']; eq($v['result.package1'], '10GB left', 'values are available as {result.…}');
});
t('the Seddo starter flow', function () {
    save_flow(['flow_key' => 'zt_seddo', 'title' => 'ZT Seddo', 'template' => 'seddo']); ok(flow_connection('sharebundle') !== null, 'its connection is created from what the portal already knows');
    eq(array_values(array_filter(flow_validate(flow_get('zt_seddo')['def']), fn($x) => $x['level'] === 'error')), [], 'it has nothing to fix'); $d = flow_get('zt_seddo')['def'];
    $h = ['call' => flow_sim_call_hook('success'), 'lookup' => 'flow_sim_lookup', 'offers' => fn($s) => [['offer_code' => 'ZTSED', 'name' => '12GB Seddo', 'one_time_price' => 600]], 'offer' => fn($c) => ['name' => '12GB Seddo', 'one_time_price' => 600, 'validity_amount' => 30, 'vendor' => 'huawei', 'offer_code_for_other' => '']];
    eq(flow_screen($d, ['1'], $h)['text'], 'Press 1 to subscribe to Seddo 12GB Seddo for D600, valid for 30 days, or press 0 to return to the menu.', 'buy: same words as the Shared Bundle item'); has(flow_screen($d, ['2', '866671144'], $h)['text'], 'Seddo number 866671144 is correct', 'add number: local digits'); eq(flow_screen($d, ['2', '866671144', '1'], $h)['text'], 'Your subscription is successful', 'and the reply text of the call');
    portal_pdo()->exec("DELETE FROM ussd_connections WHERE conn_key='sharebundle'");
});
t('a Mobius log line becomes a request body', function () {
    $line = '22:25:37.962 [Thread-3] INFO NETWORK [] - Sending request :{"callID":"6ac2d23e562b1d1f048485aa","originalRequest":"*606#","remoteURL":"https://h.example/hera/prepaid/ShareBundle/subscribe","menuID":"x","virtualNetworkID":"y","localAddress":{"digits":"220609899931"},"remoteAddress":{"digits":"220609899901"},"msisdn":"220866365728","imsi":"607036004407569","localDialogID":33640635,"remoteDialogID":13287424,"headers":[{"propertyName":"X-API-KEY","defaultValue":"SECRET-KEY"}],"mappingType":"JSON","isMobileOriginated":true,"isInitial":false,"chargesWithCurrency":"D600","offerCode":"40015","vendor":"huawei","channel":"USSD","operation":"purchaseOffer"} to application:https://h.example/hera/prepaid/ShareBundle/subscribe';
    $r = flow_body_from_log($line); $b = $r['body']; lacks($b, 'SECRET-KEY', 'the headers (and their secrets) are left out'); lacks($b, '220866365728', "the caller's number is replaced"); lacks($b, '607036004407569', 'and the IMSI');
    has($b, '"msisdn":"{msisdn}"', 'msisdn becomes a placeholder'); has($b, '"localAddress":{local_address_json}', 'addresses go in as JSON'); has($b, '"offerCode":"{offer.code}"', 'the offer fields become the picked offer'); has($b, '"chargesWithCurrency":"{offer.price_d}"', 'including the price'); has($b, '"operation":"purchaseOffer"', 'fixed fields are kept'); has($b, '"callID":"{txn}"', 'the call id is the session');
    ok(json_decode(flow_render_body($b, ['msisdn' => '220866000001', 'offer.code' => 'X1', 'offer.price_d' => 'D5'], ['imsi' => '1'], 'C1', '*1#'), true) !== null, 'and the result is valid JSON once filled in'); eq($r['path'], 'subscribe', 'the path is taken from the address'); eq($r['address'], 'https://h.example/hera/prepaid/ShareBundle/', 'and the base address too');
    thrown(fn() => flow_body_from_log('no json here'), 'No JSON', 'a line with no JSON is refused');
});

// ------------------------------------------------------------------ security basics
echo "\nSecurity basics\n";
t('the caller address behind the ingress', function () {
    $save = [$_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null]; $set = function ($r, $x) { $_SERVER['REMOTE_ADDR'] = $r; if ($x === null) unset($_SERVER['HTTP_X_FORWARDED_FOR']); else $_SERVER['HTTP_X_FORWARDED_FOR'] = $x; };
    $set('10.42.0.7', '41.1.2.3'); eq(client_ip(), '41.1.2.3', 'from the ingress: the address it saw');
    $set('10.42.0.7', '6.6.6.6, 41.1.2.3'); eq(client_ip(), '41.1.2.3', 'a forged first entry is ignored: the last one is the proxy\'s');
    $set('10.42.0.7', '41.1.2.3, 127.0.0.1'); eq(client_ip(), '41.1.2.3', 'proxies in front of the ingress are skipped'); eq(client_ip(false), '127.0.0.1', 'the allow-list view keeps the last entry'); $set('10.42.0.7', '127.0.0.1'); eq(client_ip(), '127.0.0.1', 'only a proxy address: that is all there is');
    $set('41.9.9.9', '1.1.1.1'); eq(client_ip(), '41.9.9.9', 'straight from a public address the header is ignored');
    $set('10.42.0.7', 'not-an-ip'); eq(client_ip(), '10.42.0.7', 'rubbish in the header is ignored'); $set('10.42.0.7', null); eq(client_ip(), '10.42.0.7', 'no header: the connection address');
    ok(ip_is_internal('192.168.164.150') && ip_is_internal('127.0.0.1') && !ip_is_internal('8.8.8.8') && !ip_is_internal('x'), 'private and loopback addresses count as the proxy');
    foreach ([0 => 'REMOTE_ADDR', 1 => 'HTTP_X_FORWARDED_FOR'] as $i => $k) { if ($save[$i] === null) unset($_SERVER[$k]); else $_SERVER[$k] = $save[$i]; }
});
t('the SQL console stays in its own database', function () {
    $portal = app_config('portal_db');
    foreach (["SELECT * FROM $portal.portal_users", "SELECT * FROM `$portal`.`app_secrets`", "SELECT * FROM $portal /* x */ . portal_users", "SELECT * FROM mysql.user", "SELECT 1 FROM vas_offers WHERE 1=0 UNION SELECT username FROM $portal.portal_users"] as $q)
        thrown(fn() => safe_sql_kind($q), 'cannot read the portal', 'blocked: '.$q);
    foreach (['SELECT * FROM vas_offers', "SELECT '$portal' AS name", 'SELECT * FROM information_schema.tables', "UPDATE vas_offers SET vendor='Hera' WHERE id=1", 'SHOW TABLES'] as $q) { safe_sql_kind($q); ok(true, 'allowed'); }
    thrown(fn() => safe_sql_kind("UPDATE HeraProduction.vas_offers SET status='0'"), 'live database', 'a write cannot name a live database'); thrown(fn() => safe_sql_kind('UPDATE `Hera`.vas_offers SET status=0'), 'live database', 'not even quoted');
    safe_sql_kind('SELECT * FROM HeraProduction.vas_offers LIMIT 1'); ok(true, 'but reading across is fine');
    foreach (['CREATE USER x IDENTIFIED BY \'y\'', 'ALTER USER root IDENTIFIED BY \'y\'', 'CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET @a=1', 'CREATE EVENT e ON SCHEDULE EVERY 1 DAY DO SELECT 1', 'CREATE DEFINER=root@localhost PROCEDURE p() SELECT 1'] as $q) thrown(fn() => safe_sql_kind($q), 'cannot be created', 'blocked: '.$q);
});
t('a confirmed action is checked against the role again', function () {
    eq(confirmation_permission('sql'), 'run_sql', 'sql needs the SQL permission'); eq(confirmation_permission('sync_table'), 'copy_records', 'a table copy needs the copy permission'); eq(confirmation_permission('update'), 'edit_records', 'an edit needs the edit permission');
    thrown(fn() => confirmation_permission('format_disk'), 'Unknown action', 'an unknown action is refused');
});
t('old log rows are cleared out, recent ones kept', function () use ($db) {
    $db->exec("INSERT INTO login_attempts(username,ip_address,success,created_at) VALUES ('zt_old','1.1.1.1',0,NOW() - INTERVAL 40 DAY),('zt_new','1.1.1.1',0,NOW())");
    portal_housekeeping(); $n = fn($u) => (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE username='$u'")->fetchColumn();
    eq($n('zt_old'), 0, 'a 40-day-old attempt is removed'); eq($n('zt_new'), 1, 'a fresh one stays'); $db->exec("DELETE FROM login_attempts WHERE username LIKE 'zt_%'");
});

// ------------------------------------------------------------------ summary
$cleanup();
echo "\n".($fail === 0 ? "ALL PASSED" : "FAILED")."  —  $pass tests ok".($fail ? ", $fail check(s) failed:\n  - ".implode("\n  - ", array_slice($failures, 0, 40)) : '')."\n";
exit($fail === 0 ? 0 : 1);
