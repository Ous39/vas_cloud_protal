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
    $db->exec("DELETE FROM ussd_quiz_plays WHERE quiz_key='zt_quiz'"); $db->exec("DELETE FROM ussd_quiz_questions WHERE quiz_key='zt_quiz'"); $db->exec("DELETE FROM ussd_quizzes WHERE quiz_key='zt_quiz'");
    $db->exec('DELETE FROM ussd_proxy_config');
    $ins = $db->prepare('INSERT INTO ussd_proxy_config(name,value) VALUES(?,?)'); foreach ($savedCfg as $k => $v) $ins->execute([$k, $v]);
};
ussd_quiz_tables(); ussd_purchase_table(); menu_versions_table(); $cleanup();
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
$mk = function (int $id, ?int $p, string $type, string $label, array $x = []) { return $x + ['id' => $id, 'parent_id' => $p, 'node_type' => $type, 'status' => 'active', 'prompt_text' => $label, 'offer_code' => '', 'action_key' => '', 'catalog_filter' => '', 'body_text' => '', 'display_order' => $id]; };
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
t('buy for another number', function () use ($eng) {
    eq($eng(['3'])['kind'], 'recipient', 'asks for the number'); has($eng(['3', 'abc'])['text'], 'Invalid number.', 'refuses rubbish');
    $m = $eng(['3', '866520934']); has($m['text'], 'Buy for 220866520934:', 'then the main menu again'); lacks($m['text'], 'Buy for other', 'without the buy-for-other item');
    $p = $eng(['3', '866520934', '1', '2', '1']); eq($p['purchase']['recipient'], '220866520934', 'the purchase carries the other number'); has($eng(['3', '866520934', '1', '2'])['text'], 'for 220866520934?', 'and the confirm screen says who for');
    has($eng(['3', '866520934', '0'])['text'], '1. Bundles', 'back leaves it again');
    eq($p['purchase']['recipient_raw'], '866520934', 'the digits are also kept exactly as typed (Hera wants them that way)');
    $self = $eng(['1', '5'])['text']; has($self, 'ZT Alpha', 'for yourself: every offer is listed'); has($self, 'ZT Beta', '(including one with no "other" code)');
    $other = $eng(['3', '866520934', '1', '5'])['text']; has($other, 'ZT Alpha', 'for someone else: offers with an "other" code are listed'); lacks($other, 'ZT Beta', 'and offers without one are left out');
    $b = $eng(['3', '866520934', '1', '6']); eq($b['kind'], 'offer_unavailable', 'picking such an offer directly is refused'); has($b['text'], 'cannot be bought for another number', 'with a clear reason'); eq($eng(['1', '6'])['kind'], 'confirm', 'while buying it for yourself is fine');
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
    has(screen('*ZT4#', ['2', '2'], $hk)['text'], 'Top players this week', 'leaderboard'); has(screen('*ZT4#', ['2', '2'], $hk)['text'], '220***001', 'with numbers masked');
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
    $r = $run($live('ok'), $p, 'ZT-p3', $ctx); has($r['text'], 'has been sent', 'no success word set: only "sent"'); eq($db->query("SELECT status FROM ussd_purchases WHERE call_id='ZT-p3'")->fetchColumn(), 'sent', 'recorded as sent');
    $b = $lastBody('/purchase/ok'); eq($b['json']['offerCode'] ?? null, 'ZT001', 'offer code sent'); eq($b['json']['chargesWithCurrency'] ?? null, 'D10', 'price with the D'); eq($b['json']['imsi'] ?? null, '607036003160087', "the caller's IMSI is forwarded"); eq($b['json']['operation'] ?? null, 'purchaseOffer', 'operation'); eq($b['json']['otherOfferCode'] ?? null, 'ZTO01', 'other offer code'); eq($b['json']['localAddress']['digits'] ?? null, '220609899931', 'SS7 address forwarded'); eq($b['headers']['x-api-key'], 'k-123', 'headers sent'); eq($b['headers']['x-username'], 'USSD', 'both of them');
    has($run($live('ok', ['purchase_ok_match' => 'subscription is successful']), $p, 'ZT-p4', $ctx)['text'], 'has been purchased', 'with the success word it says purchased');
    has($run($live('ok', ['purchase_ok_match' => 'no such words']), $p, 'ZT-p5', $ctx)['text'], 'could not be completed', 'a reply without the success word is a failure');
    has($run($live('low'), $p, 'ZT-p6', $ctx)['text'], 'balance is too low', 'Hera low-balance reply is recognised'); eq($db->query("SELECT status FROM ussd_purchases WHERE call_id='ZT-p6'")->fetchColumn(), 'lowbal', 'and recorded');
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
    has($v(['2', '2'])['text'], 'Enter the beneficiary Seddo number:', 'add number: asks for it'); has($v(['2', '2', 'abc'])['text'], 'Invalid number.', 'refuses rubbish'); has($v(['2', '2', '866671144'])['text'], 'Seddo number 220866671144 is correct', 'asks to confirm'); $a = $v(['2', '2', '866671144', '1']); eq($a['kind'], 'share_add', 'confirming adds'); eq($a['share']['other_raw'], '866671144', 'keeping the digits as typed for Hera');
    has($v(['2', '3'])['text'], '1. Check Balance', 'account menu'); has($v(['2', '3', '1'])['text'], 'Your Seddo bundle balance is:', 'balance'); has($v(['2', '3', '2'])['text'], 'My Seddo numbers:', 'numbers');
});
t('balance and numbers read from Hera', function () use ($cfgS, $stub) {
    $b = ussd_share_http($cfgS('ok'), 'balance', ['msisdn' => '220866000001', 'txn' => 'ZT-b']); ok($b['ok'], 'balance succeeded'); eq($b['text'], "Your Seddo bundle balance is:\nPackage Free Data remains 482.20MB expires on 19/10/26\nPackage Free Data remains 56.97MB expires on 11/10/26", 'one line per bundle, empty ones left out'); ok(mb_strlen($b['text']) < 150, 'fits a screen');
    $n = ussd_share_http($cfgS('ok'), 'numbers', ['msisdn' => '220866000001', 'txn' => 'ZT-n']); eq($n['text'], "1. 220866111222 - 3GB\n2. 220866333444 - 2GB", 'numbers as "order number separator limit"'); eq(ussd_share_http($cfgS('empty'), 'numbers', ['msisdn' => '1', 'txn' => 't'])['text'], "You don't have Seddo number", 'no numbers says so');
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

// ------------------------------------------------------------------ summary
$cleanup();
echo "\n".($fail === 0 ? "ALL PASSED" : "FAILED")."  —  $pass tests ok".($fail ? ", $fail check(s) failed:\n  - ".implode("\n  - ", array_slice($failures, 0, 40)) : '')."\n";
exit($fail === 0 ? 0 : 1);
