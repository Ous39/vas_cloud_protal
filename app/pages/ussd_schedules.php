<?php
declare(strict_types=1);
// Page: ?page=ussd_schedules — USSD → Schedules: every USSD schedule in one place.
//  * Mobius menus put live on a short code at a time (and taken off at another) — app/lib/mobius.php mobius_sched_*;
//  * portal menu items that go live / switch off at a time — app/lib/ussd_menus.php menu_apply_schedules.
// Add, cancel, end now and try again here; each start / end / failure is also sent to Slack and email (Alert Settings → USSD schedules).
// Included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.
if (!can('manage_ussd_menus') && !can('manage_api_keys')) throw new RuntimeException('You do not have permission to perform this action.');
$canMobius = can('manage_api_keys');
$cfg = $canMobius ? mobius_prod_cfg(ussd_proxy_config()) : null; // schedules are production menus, whatever the Production / Testbed switch says
$mobiusOn = $cfg !== null && $cfg['mobius_user'] !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$mobiusOn) throw new RuntimeException('The Mobius connection is not set up (USSD → Settings → Proxy).');
    $do = (string)($_POST['do'] ?? ''); $back = '?page=ussd_schedules';
    try {
        if ($do === 'add') {
            $id = mobius_sched_add($cfg, (string)($_POST['menu_id'] ?? ''), (string)($_POST['live_code'] ?? ''), (string)($_POST['start'] ?? ''), (string)($_POST['end'] ?? ''));
            $r = mobius_sched_apply($cfg); $s = mobius_sched_get($id);
            flash($s && $s['state'] === 'failed' ? 'danger' : 'success', $s && $s['state'] === 'live' ? 'Saved: the menu is live on '.$s['live_code'].' now.' : ($s && $s['state'] === 'failed' ? 'Saved, but it could not go live: '.$s['note'] : 'Saved: it goes live at '.substr((string)$s['start_at'], 0, 16).'.'));
        } elseif ($do === 'stop') {
            $how = mobius_sched_stop($cfg, (int)($_POST['id'] ?? 0));
            flash('success', $how === 'cancelled' ? 'Schedule cancelled: nothing was changed in Mobius.' : 'Ended now: the short codes are back as they were before.');
        } elseif ($do === 'retry') {
            $r = mobius_sched_retry($cfg, (int)($_POST['id'] ?? 0)); $s = mobius_sched_get((int)($_POST['id'] ?? 0));
            flash($r['failed'] ? 'danger' : 'success', $r['failed'] ? 'It failed again: '.($s['note'] ?? '') : 'Done: '.($s['note'] ?? 'it will run at its time.'));
        } else throw new RuntimeException('Unknown action.');
    } catch (RuntimeException $e) { flash('danger', $e->getMessage()); }
    redirect($back);
}

// what is due starts / ends now (the 5-minute job does it too)
if ($mobiusOn) mobius_sched_tick($cfg);
try { menu_apply_schedules_tick(); } catch (Throwable $e) {}

$now = date('Y-m-d H:i:s');
$ago = function (?string $t) use ($now): string {
    if (!$t) return ''; $d = strtotime($t) - strtotime($now); $a = abs($d);
    $s = $a < 3600 ? max(1, intdiv($a, 60)).' min' : ($a < 86400 ? intdiv($a, 3600).' h'.(($m = intdiv($a % 3600, 60)) ? ' '.$m.' min' : '') : intdiv($a, 86400).' d'.(($h = intdiv($a % 86400, 3600)) ? ' '.$h.' h' : ''));
    return $d >= 0 ? 'in '.$s : $s.' ago';
};
$when = fn(?string $t) => $t ? date('D j M, H:i', strtotime($t)) : '';

// Mobius menu schedules
$mob = []; if ($canMobius) { try { mobius_sched_ready(); $mob = portal_pdo()->query("SELECT * FROM mobius_schedules ORDER BY (state IN ('waiting','live','failed')) DESC, COALESCE(start_at, created_at) DESC, id DESC LIMIT 300")->fetchAll(); } catch (Throwable $e) { flash('warning', 'Could not read the Mobius schedules: '.$e->getMessage()); } }
// Portal menu items with a time (scheduled to go live, or live with a switch-off time)
$items = []; try { menu_schema_ready(); $items = portal_pdo()->query("SELECT id, short_code, prompt_text, status, live_from, live_until, created_by FROM ussd_menu_nodes WHERE short_code NOT LIKE '% [archived%' AND ((status='scheduled' AND live_from IS NOT NULL) OR (status='active' AND live_until IS NOT NULL)) ORDER BY COALESCE(live_from, live_until), short_code LIMIT 300")->fetchAll(); } catch (Throwable $e) {}

$open = array_values(array_filter($mob, fn($s) => in_array($s['state'], ['waiting', 'live'], true)));
$failed = array_values(array_filter($mob, fn($s) => $s['state'] === 'failed'));
$hist = array_values(array_filter($mob, fn($s) => in_array($s['state'], ['ended', 'cancelled'], true)));
$count = fn(string $st) => count(array_filter($mob, fn($s) => $s['state'] === $st));
$ended30 = count(array_filter($hist, fn($s) => $s['state'] === 'ended' && strtotime((string)($s['end_at'] ?: $s['created_at'])) > time() - 30 * 86400));

// The next 7 days, day by day: every start and end
$events = []; $limit = date('Y-m-d H:i:s', time() + 7 * 86400);
foreach ($open as $s) {
    if ($s['state'] === 'waiting' && $s['start_at'] <= $limit) $events[] = [$s['start_at'], 'start', 'Mobius menu', $s['menu_name'], $s['live_code'], '?page=ussd_schedules#s'.$s['id']];
    if ($s['end_at'] && $s['end_at'] <= $limit) $events[] = [$s['end_at'], 'end', 'Mobius menu', $s['menu_name'], $s['live_code'], '?page=ussd_schedules#s'.$s['id']];
}
foreach ($items as $i) {
    $link = '?page=ussd_build&short_code='.urlencode((string)$i['short_code']);
    if ($i['status'] === 'scheduled' && $i['live_from'] <= $limit) $events[] = [$i['live_from'], 'start', 'Portal item', $i['prompt_text'], $i['short_code'], $link];
    if ($i['live_until'] && $i['live_until'] <= $limit) $events[] = [$i['live_until'], 'end', 'Portal item', $i['prompt_text'], $i['short_code'], $link];
}
usort($events, fn($a, $b) => strcmp($a[0], $b[0]));
$byDay = []; foreach ($events as $ev) $byDay[substr($ev[0], 0, 10)][] = $ev;

$menus = []; $menusErr = '';
if ($mobiusOn) { $l = mobius_list_menus_cached($cfg); if ($l['ok']) { $menus = $l['menus']; usort($menus, fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''))); } else $menusErr = (string)$l['error']; }
$preMenu = (string)($_GET['menu'] ?? '');

layout_start('USSD Schedules'); ussd_subnav('ussd_schedules');
$stateBadge = function (string $st): string { [$l, $c] = MOBIUS_SCHED_STATES[$st] ?? [$st, 'secondary']; return '<span class="badge bg-'.$c.($c === 'light' ? ' text-dark border' : '').'">'.e($l).'</span>'; };
$post = function (string $do, int $id, string $label, string $cls, string $confirm): string {
    return '<form method="post" class="d-inline" data-confirm="'.e($confirm).'"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="do" value="'.e($do).'"><input type="hidden" name="id" value="'.$id.'"><button class="btn btn-sm '.$cls.'">'.e($label).'</button></form>';
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
 <div><h2 class="h4 mb-1">Schedules</h2><p class="text-muted small mb-0">Every USSD schedule in one place: Mobius menus put live on a short code at a time, and portal menu items that go live or switch off at a time. Due changes run by themselves within about 5 minutes.</p></div>
 <a class="btn btn-sm btn-outline-secondary" href="?page=alert_settings"><i class="fa-solid fa-bell me-1"></i>Who gets the alerts</a>
</div>

<div class="row g-2 mb-3">
 <?php foreach ([['Live now', $count('live'), 'success', 'fa-tower-broadcast'], ['Waiting', $count('waiting'), 'secondary', 'fa-hourglass-half'], ['Failed', count($failed), count($failed) ? 'danger' : 'secondary', 'fa-circle-exclamation'], ['Ended (30 days)', $ended30, 'dark', 'fa-flag-checkered'], ['Portal items with a time', count($items), 'primary', 'fa-list-check']] as [$lb, $n, $c, $ic]): ?>
 <div class="col-6 col-md"><div class="cardx h-100 py-2"><div class="text-muted small text-uppercase"><i class="fa-solid <?=$ic?> me-1"></i><?=e($lb)?></div><div class="fs-3 fw-bold text-<?=$c?>"><?=number_format($n)?></div></div></div>
 <?php endforeach; ?>
</div>

<?php if ($failed): ?>
<div class="alert alert-danger"><b><i class="fa-solid fa-circle-exclamation me-1"></i><?=count($failed)?> schedule(s) failed.</b> Customers may be getting the wrong menu. Fix the cause (often Mobius could not be reached) and press <b>Try again</b>, or end it by hand in Mobius tools.
 <ul class="mb-0 mt-2"><?php foreach ($failed as $s): ?><li class="mb-1" id="s<?=(int)$s['id']?>"><b><?=e($s['menu_name'])?></b> → <code><?=e($s['live_code'])?></code>: <?=e($s['note'])?> <?php if ($mobiusOn) echo $post('retry', (int)$s['id'], 'Try again', 'btn-light ms-1', 'Try this schedule again now?'); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-8">
 <div class="cardx mb-3"><h3>Live now and coming up</h3>
 <?php if (!$open && !$items): ?><p class="text-muted mb-0">Nothing scheduled.<?= $mobiusOn ? ' Use <b>Schedule a Mobius menu</b> to add one.' : '' ?></p><?php else: ?>
 <div class="table-scroll"><table class="table table-hover align-middle"><thead><tr><th>What</th><th>Short code</th><th>Starts</th><th>Ends</th><th>Status</th><th></th></tr></thead><tbody>
 <?php foreach ($open as $s): ?><tr id="s<?=(int)$s['id']?>">
  <td><span class="badge bg-info text-dark me-1">Mobius menu</span><a href="?page=mobius_menus&schedule=<?=urlencode($s['menu_id'])?>"><?=e($s['menu_name'])?></a><?php if ($s['prev_name']): ?><div class="small text-muted">“<?=e($s['prev_name'])?>” parked on <?=e($s['parked_code'])?></div><?php endif; ?><?php if ($s['created_by']): ?><div class="small text-muted">by <?=e($s['created_by'])?></div><?php endif; ?></td>
  <td><code><?=e($s['live_code'])?></code><?php if ($s['own_code'] && mobius_bare_code($s['own_code']) !== mobius_bare_code($s['live_code'])): ?><div class="small text-muted">own code <?=e($s['own_code'])?></div><?php endif; ?></td>
  <td class="small"><?=e($when($s['start_at']))?><div class="text-muted"><?=e($ago($s['start_at']))?></div></td>
  <td class="small"><?= $s['end_at'] ? e($when($s['end_at'])).'<div class="text-muted">'.e($ago($s['end_at'])).'</div>' : '<span class="text-muted">no end</span>' ?></td>
  <td><?=$stateBadge($s['state'])?></td>
  <td class="text-end text-nowrap"><?php if ($mobiusOn) echo $s['state'] === 'live' ? $post('stop', (int)$s['id'], 'End now', 'btn-outline-danger', 'End it now and put the short codes back as they were?') : $post('stop', (int)$s['id'], 'Cancel', 'btn-outline-secondary', 'Cancel this schedule? Nothing changes in Mobius.'); ?></td></tr>
 <?php endforeach; foreach ($items as $i): ?><tr>
  <td><span class="badge bg-primary me-1">Portal item</span><a href="?page=ussd_build&short_code=<?=urlencode((string)$i['short_code'])?>"><?=e(mb_strimwidth((string)$i['prompt_text'], 0, 70, '…'))?></a><?php if ($i['created_by']): ?><div class="small text-muted">by <?=e($i['created_by'])?></div><?php endif; ?></td>
  <td><code><?=e($i['short_code'])?></code></td>
  <td class="small"><?= $i['status'] === 'scheduled' ? e($when($i['live_from'])).'<div class="text-muted">'.e($ago($i['live_from'])).'</div>' : '<span class="text-muted">already live</span>' ?></td>
  <td class="small"><?= $i['live_until'] ? e($when($i['live_until'])).'<div class="text-muted">'.e($ago($i['live_until'])).'</div>' : '<span class="text-muted">no end</span>' ?></td>
  <td><?= $i['status'] === 'scheduled' ? '<span class="badge bg-secondary">Waiting</span>' : '<span class="badge bg-success">Live</span>' ?></td>
  <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="?page=ussd_build&short_code=<?=urlencode((string)$i['short_code'])?>">Change</a></td></tr>
 <?php endforeach; ?></tbody></table></div><?php endif; ?></div>

 <div class="cardx mb-3"><h3>The next 7 days</h3>
 <?php if (!$byDay): ?><p class="text-muted mb-0">No start or end in the next 7 days.</p><?php else: foreach ($byDay as $d => $evs): ?>
  <div class="mb-2"><div class="fw-semibold"><?=e(date('l j F', strtotime($d)))?><?= $d === date('Y-m-d') ? ' <span class="badge bg-warning text-dark">today</span>' : '' ?></div>
  <ul class="list-unstyled mb-0 ms-2"><?php foreach ($evs as [$t, $kind, $src, $name, $code, $link]): ?><li class="small py-1 border-bottom"><span class="text-muted me-2"><?=e(substr($t, 11, 5))?></span><?= $kind === 'start' ? '<span class="badge bg-success">goes live</span>' : '<span class="badge bg-dark">ends</span>' ?> <a href="<?=e($link)?>"><?=e(mb_strimwidth((string)$name, 0, 60, '…'))?></a> on <code><?=e($code)?></code> <span class="text-muted">(<?=e($src)?>)</span></li><?php endforeach; ?></ul></div>
 <?php endforeach; endif; ?></div>

 <?php if ($canMobius): ?><div class="cardx"><h3>History</h3>
 <?php if (!$hist): ?><p class="text-muted mb-0">No finished schedules yet.</p><?php else: ?>
 <div class="table-scroll"><table class="table table-sm"><thead><tr><th>Menu</th><th>Short code</th><th>From</th><th>Until</th><th>Status</th><th>What happened</th></tr></thead><tbody>
 <?php foreach (array_slice($hist, 0, 100) as $s): ?><tr><td><?=e($s['menu_name'])?><?php if ($s['created_by']): ?><div class="small text-muted">by <?=e($s['created_by'])?></div><?php endif; ?></td><td><code><?=e($s['live_code'])?></code></td><td class="small"><?=e($when($s['start_at']))?></td><td class="small"><?=e($when($s['end_at']))?></td><td><?=$stateBadge($s['state'])?></td><td class="small text-muted"><?=e($s['note'])?></td></tr><?php endforeach; ?>
 </tbody></table></div><?php endif; ?></div><?php endif; ?>
</div>

<div class="col-lg-4">
 <?php if ($canMobius): ?><div class="cardx mb-3"><h3>Schedule a Mobius menu</h3>
 <?php if (!$mobiusOn): ?><p class="text-muted mb-0">Set up the Mobius connection first (USSD → Settings → Proxy).</p>
 <?php elseif ($menusErr !== ''): ?><div class="alert alert-warning small mb-0">Could not read Mobius: <?=e($menusErr)?></div>
 <?php else: ?>
 <p class="small text-muted">At the start the menu gets the live short code; a menu that has that code now is parked on a spare code. At the end both go back. Production only.</p>
 <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="add">
  <label class="form-label small mb-0">Menu</label><input class="form-control form-control-sm mb-1" placeholder="Search a name or code…" data-filter-select="sched-menu">
  <select class="form-select mb-2" name="menu_id" id="sched-menu" required size="6"><?php foreach ($menus as $m): $mid = (string)($m['menuID'] ?? ''); if ($mid === '') continue; ?><option value="<?=e($mid)?>" <?=$mid === $preMenu ? 'selected' : ''?>><?=e(($m['name'] ?? '').' — '.($m['shortcode'] ?? ''))?></option><?php endforeach; ?></select>
  <label class="form-label small mb-0">Live short code</label><input class="form-control mb-2" name="live_code" placeholder="*606#" required>
  <label class="form-label small mb-0">Start <span class="text-muted">(empty = now)</span></label><input class="form-control mb-2" type="datetime-local" name="start">
  <label class="form-label small mb-0">End <span class="text-muted">(optional)</span></label><input class="form-control mb-3" type="datetime-local" name="end">
  <button class="btn btn-primary w-100"><i class="fa-solid fa-clock me-1"></i>Save schedule</button>
 </form>
 <div class="small text-muted mt-2">Example: build the new version on a test code, then schedule it onto <code>*606#</code> for the launch time. To only take a menu off, choose it, type its own code and fill <b>End</b>.</div>
 <?php endif; ?></div><?php endif; ?>
 <div class="cardx"><h3>Alerts</h3><p class="small mb-2">When a schedule <b>goes live</b>, <b>ends</b> or <b>fails</b>, the portal sends a message:</p>
 <ul class="small mb-2"><li><b>Email</b> to the recipients with <b>USSD schedules</b> ticked in Alert Settings.</li><li><b>Slack</b> through an active <b>Monitoring</b> integration whose URL is a Slack webhook.</li></ul>
 <a class="small" href="?page=alert_settings">Alert Settings →</a></div>
</div>
</div>
<?php layout_end(); exit;
