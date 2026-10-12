<?php
declare(strict_types=1);
// Page: ?page=ussd_build — the Easy Builder. Included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.
// A phone on the left shows the screen being built; on the right: what is on that screen, and the blocks that can be added to it.

    require_perm('manage_ussd_menus'); menu_versions_table(); flow_tables();
    try { menu_apply_schedules(); } catch (Throwable $e) { error_log('menu schedule: '.$e->getMessage()); }
    $known = menu_shortcodes();
    $code = trim((string)($_GET['short_code'] ?? $_POST['short_code'] ?? '')); if ($code === '' && $known) $code = $known[0];
    $at = (int)($_GET['at'] ?? $_POST['at'] ?? 0);
    $url = fn(array $q = []) => '?page=ussd_build&short_code='.urlencode($code).(($q['at'] ?? $at) ? '&at='.(int)($q['at'] ?? $at) : '').(isset($q['add']) ? '&add='.urlencode((string)$q['add']) : '').(isset($q['edit']) ? '&edit='.(int)$q['edit'] : '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $do = (string)($_POST['do'] ?? ''); $parent = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
        if ($do === 'add_block') {
            [$newId, $what] = menu_build_block($code, $parent, (string)($_POST['block'] ?? ''), (array)($_POST['b'] ?? []));
            flash('success', 'Added a '.$what.' as a draft — customers will not see it until you publish.');
            redirect($url(['at' => ($_POST['block'] ?? '') === 'menu' ? $newId : $at]));
        }
        if ($do === 'edit_item') {
            $id = (int)($_POST['id'] ?? 0); $n = menu_node($id) ?: throw new RuntimeException('That item is gone.'); $f = (array)($_POST['f'] ?? []);
            $d = $n; foreach (['prompt_text', 'body_text', 'status', 'live_from', 'live_until'] as $k) if (array_key_exists($k, $f)) $d[$k] = $f[$k];
            if ($n['node_type'] === 'offer' && isset($f['offer_code'])) $d['offer_code'] = $f['offer_code'];
            if ($n['node_type'] === 'catalog') { $subs = array_values(array_filter(array_map('trim', (array)($f['subs'] ?? [])))); if (!$subs) throw new RuntimeException('Tick at least one group of offers.'); $d['catalog_filter'] = implode("\n", $subs); }
            if ($n['node_type'] === 'goto' && isset($f['target'])) $d['goto_target'] = $f['target'];
            if (!isset($f['live_from'])) { $d['live_from'] = $n['live_from']; $d['live_until'] = $n['live_until']; }
            save_menu_node($d, $id); flash('success', 'Saved.'); redirect($url([]));
        }
        if ($do === 'move') { menu_move_node((int)($_POST['id'] ?? 0), (string)($_POST['dir'] ?? '')); redirect($url([])); }
        if ($do === 'set_status') { menu_set_status((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? '')); redirect($url([])); }
        if ($do === 'publish') { $n = menu_activate_drafts($code); flash('success', $n.' item'.($n === 1 ? '' : 's').' published — customers can see '.($n === 1 ? 'it' : 'them').' now.'); redirect($url([])); }
        if ($do === 'publish_at') {
            $r = menu_schedule_drafts($code, (string)($_POST['live_from'] ?? ''), (string)($_POST['live_until'] ?? ''));
            flash($r['count'] === 0 ? 'info' : 'success', $r['count'] === 0 ? 'There is nothing to publish.' : ($r['status'] === 'scheduled' ? $r['count'].' item'.($r['count'] === 1 ? '' : 's').' will go live by themselves on '.$r['from'].' (portal time, UTC). Nothing to do on the day.' : 'That time has passed, so '.$r['count'].' item'.($r['count'] === 1 ? ' was' : 's were').' published now.'));
            redirect($url([]));
        }
        if ($do === 'flow_live') {
            $fk = (string)($_POST['flow_key'] ?? ''); $fl = flow_get($fk) ?: throw new RuntimeException('That service is gone.');
            save_flow(['flow_key' => $fk, 'title' => $fl['title'], 'mode' => 'live', 'test_outcome' => $fl['test_outcome'], 'status' => $fl['status']]);
            flash('success', 'The service is live — it now makes real calls.'); redirect($url([]));
        }
        throw new RuntimeException('Unknown action.');
    }

    // ---- what the page shows
    $flat = $code !== '' ? menu_nodes_flat($code) : [];
    $byId = []; $kids = []; foreach ($flat as $n) { $byId[(int)$n['id']] = $n; $kids[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n; }
    $vis = array_values(array_filter($flat, fn($n) => in_array($n['status'], ['active', 'draft', 'scheduled'], true)));
    $visKids = []; $visById = []; foreach ($vis as $n) { $visKids[$n['parent_id'] === null ? 0 : (int)$n['parent_id']][] = $n; $visById[(int)$n['id']] = $n; }
    $roots = $visKids[0] ?? []; $welcome = (count($roots) === 1 && $roots[0]['node_type'] === 'menu' && !empty($visKids[(int)$roots[0]['id']])) ? $roots[0] : null;
    if ($at && (!isset($byId[$at]) || $byId[$at]['node_type'] !== 'menu')) $at = 0;
    $cont = $at ?: ($welcome ? (int)$welcome['id'] : 0);               // where new blocks are added
    $digits = ($at && isset($visById[$at])) ? ussd_path_to($visById[$at], $visKids, $visById, $welcome) : [];
    $phone = $flat ? ussd_screen($code, $digits ?? [], ['active', 'draft', 'scheduled'], null, ['seed' => 'build']) : null;
    $options = $kids[$cont] ?? [];                                      // every item on this screen, whatever its state
    $shown = $visKids[$cont] ?? [];                                     // what the phone numbers 1, 2, 3 …
    $crumbs = []; for ($x = $cont ?: null, $g = 0; $x && $g < 8; $g++) { array_unshift($crumbs, $byId[$x]); $x = $byId[$x]['parent_id'] !== null ? (int)$byId[$x]['parent_id'] : null; }
    $drafts = count(array_filter($flat, fn($n) => $n['status'] === 'draft')); $sched = count(array_filter($flat, fn($n) => $n['status'] === 'scheduled'));
    $health = $flat ? menu_health($code) : []; $errs = array_values(array_filter($health, fn($h) => $h['level'] === 'error'));
    $edit = isset($_GET['edit']) ? menu_node((int)$_GET['edit']) : null; if ($edit && $edit['short_code'] !== $code) $edit = null;
    $add = $edit ? '' : (string)($_GET['add'] ?? '');
    $flows = []; foreach (flow_list() as $fo) $flows[$fo['flow_key']] = $fo;
    $offers = table_exists(current_schema(), 'vas_offers') ? pdo(current_schema())->query('SELECT offer_code, name FROM vas_offers WHERE '.OFFER_ACTIVE_SQL.' ORDER BY name')->fetchAll() : [];
    $subCats = []; try { if (table_exists(USSD_OFFER_SCHEMA, 'vas_offers')) $subCats = pdo(USSD_OFFER_SCHEMA)->query('SELECT sub_category, SUM('.OFFER_ACTIVE_SQL.') act FROM vas_offers WHERE sub_category IS NOT NULL AND sub_category<>\'\' AND (deleted_at IS NULL OR deleted_at=\'\') GROUP BY sub_category HAVING act>0 ORDER BY sub_category')->fetchAll(); } catch (Throwable $e) {}
    ussd_quiz_tables(); $quizzes = portal_pdo()->query('SELECT quiz_key,title FROM ussd_quizzes ORDER BY title')->fetchAll(); $conns = flow_connections();
    $pathLabel = function (int $id) use (&$pathLabel, $byId) { $n = $byId[$id] ?? null; if (!$n) return ''; $pp = $n['parent_id'] !== null ? $pathLabel((int)$n['parent_id']) : ''; return ($pp !== '' ? $pp.' › ' : '').$n['prompt_text']; };
    $names = ['menu' => 'menu', 'end' => 'message', 'catalog' => 'list of offers', 'offer' => 'offer', 'flow' => 'service', 'goto' => 'go to', 'recipient' => 'buy for other', 'sharedbundle' => 'shared bundle', 'quiz' => 'quiz', 'action' => 'action'];
    $badge = ['menu' => 'bg-primary', 'end' => 'bg-secondary', 'catalog' => 'bg-warning text-dark', 'offer' => 'bg-success', 'flow' => 'bg-info text-dark', 'goto' => 'bg-dark', 'recipient' => 'bg-dark', 'sharedbundle' => 'bg-success', 'quiz' => 'bg-danger', 'action' => 'bg-info text-dark'];
    $stBadge = ['draft' => 'Not live yet', 'scheduled' => 'Scheduled', 'inactive' => 'Off'];
    $post = function (string $do, array $hidden, string $icon, string $title, string $cls = 'btn-light border', string $confirm = '') use ($code, $at): string {
        $h = '<form method="post" class="d-inline"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="do" value="'.e($do).'"><input type="hidden" name="short_code" value="'.e($code).'"><input type="hidden" name="at" value="'.$at.'">';
        foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="'.e($k).'" value="'.e((string)$v).'">';
        return $h.'<button class="btn btn-sm '.$cls.' py-0 px-1" title="'.e($title).'"'.($confirm !== '' ? ' data-confirm="'.e($confirm).'"' : '').'><i class="fa-solid '.$icon.'"></i></button></form>';
    };
    $form = fn(string $do, string $extra = '') => '<form method="post" class="eb-form"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="do" value="'.e($do).'"><input type="hidden" name="short_code" value="'.e($code).'"><input type="hidden" name="at" value="'.$at.'">'.$extra;
    $blocks = [
        ['message', 'fa-message', 'Message', 'Shows some text and ends'],
        ['menu', 'fa-list-ul', 'Menu', 'A list of more options'],
        ['list', 'fa-layer-group', 'List of offers', 'Offers from the catalogue, kept up to date'],
        ['offer', 'fa-tag', 'Sell an offer', 'One bundle, with a confirm step'],
        ['service', 'fa-gears', 'Ask & call a service', 'Ask for a number, call a service, show the result'],
        ['goto', 'fa-arrow-turn-up', 'Go to', 'Jump to another screen or back to the start'],
    ];
    $more = [['balance', 'Check balance'], ['buyother', 'Buy for another number'], ['shared', 'Shared Bundle'], ['quiz', 'Quiz'], ['flow', 'A service already built']];
    $targets = function (?int $sel = null, bool $withTop = true) use ($flat, $pathLabel, $names) { $h = $withTop ? '<option value="@top">The main menu (start again)</option>' : ''; foreach ($flat as $n) { if ($n['node_type'] === 'goto') continue; $h .= '<option value="'.(int)$n['id'].'"'.($sel === (int)$n['id'] ? ' selected' : '').'>'.e($pathLabel((int)$n['id'])).' ('.e($names[$n['node_type']] ?? $n['node_type']).')</option>'; } return $h; };
    $subBoxes = function (array $on = []) use ($subCats) { $h = ''; foreach ($subCats as $s) $h .= '<label class="eb-chip"><input type="checkbox" name="'.'SUBS'.'[]" value="'.e($s['sub_category']).'"'.(in_array($s['sub_category'], $on, true) ? ' checked' : '').'> '.e($s['sub_category']).' <small class="text-muted">'.(int)$s['act'].'</small></label>'; return $h ?: '<span class="small text-muted">No offer groups found in the catalogue.</span>'; };

    layout_start('Easy Builder'); ussd_subnav('ussd_build');
    ?>
    <div class="cardx">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><h3 class="mb-0"><i class="fa-solid fa-wand-magic-sparkles me-2"></i>Easy Builder</h3>
                <div class="small text-muted">Click a line on the phone to go into it. Add blocks to the screen you are on. Nothing reaches customers until you <b>publish</b>.</div></div>
            <form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="ussd_build"><select class="form-select" name="short_code" data-autosubmit><?php foreach ($known as $sc):?><option value="<?=e($sc)?>" <?=$sc === $code ? 'selected' : ''?>><?=e($sc)?></option><?php endforeach;?></select>
                <a class="btn btn-outline-secondary text-nowrap" href="?page=ussd_menus&add=1">New menu</a></form>
        </div>
    </div>
    <?php if ($code === ''):?><div class="cardx mt-3"><p class="mb-0">There is no menu yet. <a href="?page=ussd_menus&add=1">Add a new menu</a> first, then come back here to build it.</p></div>
    <?php else:?>
    <div class="cardx mt-3 py-2 eb-publish">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <?php if ($drafts || $sched):?>
                <span><span class="badge bg-warning text-dark"><?=$drafts?> not live yet</span><?php if ($sched):?> <span class="badge status-scheduled"><?=$sched?> scheduled</span><?php endif;?></span>
                <?php if ($drafts):?><?=$form('publish')?><button class="btn btn-success btn-sm" data-confirm="Make <?=$drafts?> item<?=$drafts === 1 ? '' : 's'?> visible to customers now?"><i class="fa-solid fa-rocket me-1"></i>Publish now</button></form>
                <?=$form('publish_at')?><span class="d-inline-flex gap-1 align-items-center"><input type="datetime-local" class="form-control form-control-sm" name="live_from" value="<?=e(date('Y-m-d\T08:00', strtotime('+1 day')))?>" required><button class="btn btn-outline-primary btn-sm text-nowrap"><i class="fa-regular fa-clock me-1"></i>Publish at this time</button></span></form><?php endif;?>
            <?php else:?><span class="text-muted small"><i class="fa-solid fa-circle-check text-success me-1"></i>Everything you built is published.</span><?php endif;?>
            <span class="ms-auto small"><?php if ($errs):?><span class="badge bg-danger"><?=count($errs)?> to fix</span><?php elseif ($flat):?><span class="badge bg-success">check passed</span><?php endif;?>
                <a class="ms-2" href="?page=ussd_sim&sc=<?=urlencode($code)?>&draft=1">Try it in the Simulator</a> · <a href="?page=ussd_menu&short_code=<?=urlencode($code)?>">Advanced view</a></span>
        </div>
        <?php if ($errs):?><ul class="small mb-0 mt-2"><?php foreach (array_slice($errs, 0, 4) as $h):?><li><?=e($h['msg'])?><?php if ($h['node']):?> <a href="<?=e($url(['edit' => (int)$h['node'], 'at' => (int)($byId[(int)$h['node']]['parent_id'] ?? 0)]))?>">fix</a><?php endif;?></li><?php endforeach;?></ul><?php endif;?>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-4">
            <div class="eb-phone">
                <div class="eb-screen">
                    <?php if (!$phone):?><div class="eb-line text-muted">Nothing here yet. Add your first block →</div>
                    <?php else:
                        foreach (explode("\n", $phone['text']) as $line):
                            if (preg_match('/^(\d+)\. /', $line, $mm) && (int)$mm[1] >= 1 && isset($shown[(int)$mm[1] - 1]) && $phone['kind'] === 'menu'):
                                $tgt = $shown[(int)$mm[1] - 1]; $href = $tgt['node_type'] === 'menu' ? $url(['at' => (int)$tgt['id']]) : $url(['edit' => (int)$tgt['id']]);?>
                                <a class="eb-line eb-opt" href="<?=e($href)?>"><?=e($line)?></a>
                            <?php elseif (preg_match('/^0\. Back/', $line) && $at):?>
                                <a class="eb-line eb-opt" href="<?=e($url(['at' => (int)($byId[$at]['parent_id'] ?? 0)]))?>"><?=e($line)?></a>
                            <?php else:?><div class="eb-line"><?=e($line)?></div><?php endif;
                        endforeach;
                    endif;?>
                </div>
                <div class="eb-foot small text-muted">What a customer sees (drafts included, so you can check before publishing)</div>
            </div>
            <div class="small mt-2">
                <a href="<?=e($url(['at' => 0]))?>">Start</a><?php foreach ($crumbs as $c):?> › <?php if ((int)$c['id'] === $cont && (int)$c['id'] !== $at):?><b><?=e($c['prompt_text'])?></b><?php else:?><a href="<?=e($url(['at' => (int)$c['id']]))?>"><?=e($c['prompt_text'])?></a><?php endif;?><?php endforeach;?>
            </div>
        </div>
        <div class="col-lg-8">
            <?php if ($edit):
                $f = fn(string $k) => $edit[$k] ?? '';?>
            <div class="cardx" id="blockform"><div class="d-flex justify-content-between"><h3 class="mb-2">Edit — <?=e($names[$edit['node_type']] ?? $edit['node_type'])?></h3><a href="<?=e($url([]))?>" class="btn btn-sm btn-light border">Close</a></div>
                <?=$form('edit_item', '<input type="hidden" name="id" value="'.(int)$edit['id'].'">')?>
                <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="f[prompt_text]" value="<?=e($f('prompt_text'))?>" maxlength="120" required>
                <?php if (in_array($edit['node_type'], ['menu', 'catalog', 'end'], true)):?><label class="small text-muted mb-0"><?=$edit['node_type'] === 'end' ? 'The message' : 'Heading shown above the options'?></label><textarea class="form-control mb-2" rows="3" name="f[body_text]" maxlength="400"><?=e($f('body_text'))?></textarea><?php endif;?>
                <?php if ($edit['node_type'] === 'offer'):?><label class="small text-muted mb-0">Offer</label><select class="form-select mb-2" name="f[offer_code]"><?php foreach ($offers as $o):?><option value="<?=e($o['offer_code'])?>" <?=$o['offer_code'] === $edit['offer_code'] ? 'selected' : ''?>><?=e($o['name'])?> (<?=e($o['offer_code'])?>)</option><?php endforeach;?></select><?php endif;?>
                <?php if ($edit['node_type'] === 'catalog'): $on = array_filter(array_map('trim', explode("\n", (string)$edit['catalog_filter'])));?><label class="small text-muted mb-0">Groups of offers shown</label><div class="mb-2"><?=str_replace('SUBS', 'f[subs]', $subBoxes($on))?></div><?php endif;?>
                <?php if ($edit['node_type'] === 'goto'): $cur2 = null; foreach ($flat as $x) if (($x['ref'] ?? '') !== '' && $x['ref'] === $edit['offer_code']) $cur2 = (int)$x['id'];?><label class="small text-muted mb-0">Takes the customer to</label><select class="form-select mb-2" name="f[target]"><?=str_replace('<option value="@top">', '<option value="@top"'.($edit['offer_code'] === '@top' ? ' selected' : '').'>', $targets($cur2))?></select><?php endif;?>
                <?php if ($edit['node_type'] === 'flow'): $fo = $flows[$edit['offer_code']] ?? null;?><p class="small mb-2">Opens the service <b><?=e($fo['title'] ?? $edit['offer_code'])?></b><?php if ($fo && $fo['mode'] !== 'live'):?> — <span class="text-warning">TEST mode: answers are simulated</span><?php endif;?>. <a href="?page=ussd_flows&flow=<?=urlencode((string)$edit['offer_code'])?>">Change its questions and messages</a></p><?php endif;?>
                <div class="row g-2"><div class="col-sm-5"><label class="small text-muted mb-0">State</label><select class="form-select mb-2" name="f[status]"><?php foreach (['draft' => 'Not live yet (draft)', 'active' => 'Live (customers see it)', 'scheduled' => 'Scheduled (set the time below)', 'inactive' => 'Off'] as $v => $la):?><option value="<?=e($v)?>" <?=$edit['status'] === $v ? 'selected' : ''?>><?=e($la)?></option><?php endforeach;?></select></div>
                    <div class="col-sm-4"><label class="small text-muted mb-0">Goes live at</label><input type="datetime-local" class="form-control mb-2" name="f[live_from]" value="<?=e(!empty($edit['live_from']) ? date('Y-m-d\TH:i', strtotime((string)$edit['live_from'])) : '')?>"></div>
                    <div class="col-sm-3"><label class="small text-muted mb-0">Switches off at</label><input type="datetime-local" class="form-control mb-2" name="f[live_until]" value="<?=e(!empty($edit['live_until']) ? date('Y-m-d\TH:i', strtotime((string)$edit['live_until'])) : '')?>"></div></div>
                <?php if ($edit['status'] === 'active'):?><div class="small text-warning mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>This item is live — saving changes it for customers straight away.</div><?php endif;?>
                <button class="btn btn-primary">Save</button></form>
            </div>
            <?php elseif ($add !== ''):?>
            <div class="cardx" id="blockform"><div class="d-flex justify-content-between"><h3 class="mb-2">Add — <?=e(array_column(array_map(fn($b) => [$b[0], $b[2]], $blocks), 1, 0)[$add] ?? (array_column($more, 1, 0)[$add] ?? $add))?></h3><a href="<?=e($url([]))?>" class="btn btn-sm btn-light border">Cancel</a></div>
                <?=$form('add_block', '<input type="hidden" name="block" value="'.e($add).'"><input type="hidden" name="parent_id" value="'.($cont ?: '').'">')?>
                <?php if ($add === 'message'):?>
                    <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="b[label]" maxlength="120" placeholder="e.g. How it works" required>
                    <label class="small text-muted mb-0">The message customers read</label><textarea class="form-control mb-2" rows="4" name="b[body]" maxlength="400" required></textarea>
                <?php elseif ($add === 'menu'):?>
                    <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="b[label]" maxlength="120" placeholder="e.g. Data bundles" required>
                    <label class="small text-muted mb-0">Heading shown above its options <small>(optional)</small></label><input class="form-control mb-2" name="b[body]" maxlength="400" placeholder="e.g. Choose a bundle:">
                    <div class="small text-muted mb-2">After you add it you step inside, ready to put things in it.</div>
                <?php elseif ($add === 'list'):?>
                    <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="b[label]" maxlength="120" placeholder="e.g. Sakan bundles" required>
                    <label class="small text-muted mb-0">Heading <small>(optional)</small></label><input class="form-control mb-2" name="b[body]" maxlength="400" placeholder="e.g. Choose your Sakan bundle:">
                    <label class="small text-muted mb-1">Which offers? Tick the groups — the list follows the catalogue by itself</label><div class="mb-2"><?=str_replace('SUBS', 'b[subs]', $subBoxes())?></div>
                <?php elseif ($add === 'offer'):?>
                    <label class="small text-muted mb-0">Offer</label><select class="form-select mb-2" name="b[offer_code]" required><option value="">—</option><?php foreach ($offers as $o):?><option value="<?=e($o['offer_code'])?>"><?=e($o['name'])?> (<?=e($o['offer_code'])?>)</option><?php endforeach;?></select>
                    <label class="small text-muted mb-0">Line in the menu <small>(optional — the offer's name is used)</small></label><input class="form-control mb-2" name="b[label]" maxlength="120">
                    <div class="small text-muted mb-2">Customers see the name and price and press 1 to confirm before anything is bought.</div>
                <?php elseif ($add === 'service'):?>
                    <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="b[label]" maxlength="120" placeholder="e.g. Check balance" required>
                    <div class="row g-2"><div class="col-md-6"><label class="small text-muted mb-0">What should it do?</label><select class="form-select mb-2" name="b[what]"><option value="show">Show information (like a balance)</option><option value="act">Do something (subscribe, send, refund…) and say how it went</option></select></div>
                        <div class="col-md-6"><label class="small text-muted mb-0">First ask the customer for</label><select class="form-select mb-2" name="b[ask]"><option value="none">Nothing</option><option value="phone">A phone number</option><option value="digits">A number</option><option value="text">Some text</option></select></div></div>
                    <label class="small text-muted mb-0">The question <small>(if asking)</small></label><input class="form-control mb-2" name="b[ask_text]" maxlength="300" placeholder="Enter the phone number:">
                    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="b[confirm]" value="1" id="b_confirm"><label class="form-check-label small" for="b_confirm">Ask the customer to confirm first</label></div>
                    <input class="form-control mb-2" name="b[confirm_text]" maxlength="300" placeholder="Press 1 to confirm or 0 to go back.">
                    <div class="row g-2"><div class="col-md-6"><label class="small text-muted mb-0">Service to call <small>(<a href="?page=ussd_flows">add one</a>)</small></label><select class="form-select mb-2" name="b[connection]" required><option value="">—</option><?php foreach ($conns as $cn):?><option value="<?=e($cn['conn_key'])?>"><?=e($cn['title'])?> (<?=e($cn['conn_key'])?>)</option><?php endforeach;?></select></div>
                        <div class="col-md-6"><label class="small text-muted mb-0">Address after the service's base address</label><input class="form-control mb-2" name="b[path]" maxlength="200" placeholder="e.g. Balance"></div></div>
                    <label class="small text-muted mb-0">What to send <small>(leave empty to send the caller's number; you can use {msisdn_local}, {number_local}, {input})</small></label><textarea class="form-control font-monospace mb-2" rows="2" name="b[body]" maxlength="2000" placeholder='{"msisdn":"{msisdn_local}"}'></textarea>
                    <label class="small text-muted mb-0">Message when it works <small>(for information, {result} or {reply_text} shows the answer)</small></label><input class="form-control mb-2" name="b[ok_text]" maxlength="300" placeholder="Your balance is: D{result}">
                    <div class="row g-2"><div class="col-md-6"><label class="small text-muted mb-0">If the balance is too low <small>(only for “do something”)</small></label><input class="form-control mb-2" name="b[low_text]" maxlength="300"></div>
                        <div class="col-md-6"><label class="small text-muted mb-0">If it fails</label><input class="form-control mb-2" name="b[bad_text]" maxlength="300"></div></div>
                    <div class="small text-muted mb-2"><i class="fa-solid fa-shield-halved me-1"></i>It is created in <b>test mode</b>: the phone shows simulated answers and nothing real is sent until you press “Go live” on it.</div>
                <?php elseif ($add === 'goto'):?>
                    <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="b[label]" maxlength="120" placeholder="e.g. Back to main menu" required>
                    <label class="small text-muted mb-0">Take the customer to</label><select class="form-select mb-2" name="b[target]"><?=$targets()?></select>
                <?php elseif ($add === 'buyother'):?>
                    <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="b[label]" maxlength="120" value="Buy for another number">
                    <div class="small text-muted mb-2">Asks for the other person's number, then shows the main menu so they can buy for them.</div>
                <?php elseif ($add === 'shared'):?>
                    <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="b[label]" maxlength="120" value="Shared Bundle">
                <?php elseif ($add === 'balance'):?>
                    <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="b[label]" maxlength="120" value="Check balance">
                    <div class="small text-muted mb-2">Uses the “Check balance” service (it is created for you if it does not exist). Its connection and key are set under <a href="?page=ussd_flows">Service Flows</a>.</div>
                <?php elseif ($add === 'quiz'):?>
                    <label class="small text-muted mb-0">Quiz</label><select class="form-select mb-2" name="b[quiz_key]" required><option value="">—</option><?php foreach ($quizzes as $q):?><option value="<?=e($q['quiz_key'])?>"><?=e($q['title'])?></option><?php endforeach;?></select>
                    <label class="small text-muted mb-0">Line in the menu</label><input class="form-control mb-2" name="b[label]" maxlength="120" value="Play and win">
                <?php elseif ($add === 'flow'):?>
                    <label class="small text-muted mb-0">Service</label><select class="form-select mb-2" name="b[flow_key]" required><option value="">—</option><?php foreach ($flows as $fo):?><option value="<?=e($fo['flow_key'])?>"><?=e($fo['title'])?><?=$fo['mode'] !== 'live' ? ' — test mode' : ''?></option><?php endforeach;?></select>
                    <label class="small text-muted mb-0">Line in the menu <small>(optional)</small></label><input class="form-control mb-2" name="b[label]" maxlength="120">
                <?php endif;?>
                <button class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i>Add to this screen</button></form>
            </div>
            <?php endif;?>

            <div class="cardx mt-3">
                <h3 class="mb-2">On this screen<?=$crumbs ? ': '.e(end($crumbs)['prompt_text']) : ''?></h3>
                <?php if (!$options):?><p class="text-muted small mb-0">Nothing yet. Pick a block below to add the first thing.</p><?php endif;?>
                <?php foreach ($options as $i => $n): $id = (int)$n['id']; $live = $n['status'] === 'active'; $fo = $n['node_type'] === 'flow' ? ($flows[$n['offer_code']] ?? null) : null;?>
                <div class="eb-row d-flex align-items-center gap-2 py-1 <?=$live ? '' : 'text-muted'?>">
                    <span class="badge <?=$badge[$n['node_type']] ?? 'bg-secondary'?>" style="min-width:5.4rem"><?=e($names[$n['node_type']] ?? $n['node_type'])?></span>
                    <span class="flex-grow-1"><?=e($n['prompt_text'])?>
                        <?php if (!$live):?><span class="badge <?=$n['status'] === 'scheduled' ? 'status-scheduled' : ($n['status'] === 'draft' ? 'bg-warning text-dark' : 'status-inactive')?>"><?=e($stBadge[$n['status']] ?? $n['status'])?></span><?php if ($n['status'] === 'scheduled'):?> <small class="text-info"><i class="fa-regular fa-clock"></i> <?=e(substr((string)$n['live_from'], 0, 16))?></small><?php endif;?><?php endif;?>
                        <?php if ($fo && $fo['mode'] !== 'live'):?><span class="badge bg-warning text-dark" title="Answers are simulated; nothing real is sent">test mode</span> <?=$post('flow_live', ['flow_key' => $fo['flow_key']], 'fa-bolt', 'Go live: make real calls', 'btn-outline-success', 'Make “'.$fo['title'].'” real? It will call the service for real customers.')?><?php endif;?>
                    </span>
                    <span class="text-nowrap">
                        <?=$post('move', ['id' => $id, 'dir' => 'up'], 'fa-arrow-up', 'Move up')?><?=$post('move', ['id' => $id, 'dir' => 'down'], 'fa-arrow-down', 'Move down')?>
                        <?php if ($n['node_type'] === 'menu'):?><a class="btn btn-sm btn-light border py-0 px-1" title="Go inside" href="<?=e($url(['at' => $id]))?>"><i class="fa-solid fa-arrow-right"></i></a><?php endif;?>
                        <a class="btn btn-sm btn-light border py-0 px-1" title="Edit" href="<?=e($url(['edit' => $id]))?>"><i class="fa-solid fa-pen"></i></a>
                        <?=$post('set_status', ['id' => $id, 'status' => $live ? 'inactive' : 'active'], 'fa-power-off', $live ? 'Turn off' : 'Turn on now')?>
                    </span>
                </div>
                <?php endforeach;?>
            </div>

            <div class="cardx mt-3">
                <h3 class="mb-2">Add to this screen</h3>
                <div class="eb-palette">
                    <?php foreach ($blocks as [$key, $icon, $title, $hint]):?>
                    <a class="eb-block <?=$add === $key ? 'on' : ''?>" href="<?=e($url(['add' => $key]))?>#blockform"><i class="fa-solid <?=$icon?>"></i><b><?=e($title)?></b><small><?=e($hint)?></small></a>
                    <?php endforeach;?>
                </div>
                <details class="mt-2" <?=in_array($add, array_column($more, 0), true) ? 'open' : ''?>><summary class="small text-muted">More blocks</summary>
                    <div class="d-flex flex-wrap gap-2 mt-2"><?php foreach ($more as [$key, $title]):?><a class="btn btn-sm btn-outline-secondary" href="<?=e($url(['add' => $key]))?>#blockform"><?=e($title)?></a><?php endforeach;?></div></details>
            </div>
        </div>
    </div>
    <?php endif; layout_end(); exit;
