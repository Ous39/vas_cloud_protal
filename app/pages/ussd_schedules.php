<?php
declare(strict_types=1);
// Page: ?page=ussd_schedules — USSD → Schedules: every USSD schedule in one place.
//  * Mobius menus put live on a short code at a time (and taken off at another) — app/lib/mobius.php mobius_sched_*;
//  * portal menu items that go live / switch off at a time — app/lib/ussd_menus.php menu_apply_schedules.
// Add, cancel, end now and try again here; each start / end / failure is also sent to Slack and email (Alert Settings → USSD schedules).
// Included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.
if (!can('manage_ussd_menus') && !can('manage_api_keys') && !can('edit_records')) throw new RuntimeException('You do not have permission to perform this action.');
$canMobius = can('manage_api_keys');
// Offer schedules (vas_offers of the database chosen at the top of the page): seen with view_tables, made / stopped with edit_records.
$schema = current_schema(); $canOffers = can('view_tables'); $canOfferEdit = can('edit_records');
$offersHere = false; try { $offersHere = $canOffers && table_exists($schema, 'vas_offers'); } catch (Throwable $e) {}
$cfg = $canMobius ? mobius_prod_cfg(ussd_proxy_config()) : null; // schedules are production menus, whatever the Production / Testbed switch says
$mobiusOn = $cfg !== null && $cfg['mobius_user'] !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)($_POST['do'] ?? ''), 'offer_')) {
    require_perm('edit_records'); $do = (string)$_POST['do']; $back = '?page=ussd_schedules';
    try {
        if ($do === 'offer_preview') {
            if (!$offersHere) throw new RuntimeException('There is no offer table in '.$schema.'.');
            $oid = (int)($_POST['offer_id'] ?? 0); $offer = fetch_record($schema, 'vas_offers', ['id' => $oid]) ?? throw new RuntimeException('Offer not found.');
            $ch = offer_sched_clean_changes($schema, $offer, (array)($_POST['c'] ?? []));
            $from = menu_parse_when((string)($_POST['start'] ?? '')); $until = menu_parse_when((string)($_POST['end'] ?? ''));
            $_SESSION['offer_sched_form'] = ['offer_id' => $oid, 'c' => (array)($_POST['c'] ?? []), 'start' => (string)($_POST['start'] ?? ''), 'end' => (string)($_POST['end'] ?? '')];
            if (!$ch) throw new RuntimeException('Nothing to change: fill in at least one new value that is different from the offer now.');
            if ($until !== null && $until <= ($from ?? date('Y-m-d H:i:s'))) throw new RuntimeException('The end time must be after the start time.');
            $_SESSION['offer_sched_pending'] = ['schema' => $schema, 'offer_id' => $oid, 'changes' => $ch, 'start' => (string)($_POST['start'] ?? ''), 'end' => (string)($_POST['end'] ?? '')];
            redirect('?page=ussd_schedules&offer_id='.$oid.'&preview=1#offer-form');
        } elseif ($do === 'offer_add') {
            $p = $_SESSION['offer_sched_pending'] ?? throw new RuntimeException('The preview expired. Fill in the form again.');
            if ($p['schema'] !== $schema) throw new RuntimeException('The database was switched since the preview. Fill in the form again.');
            $id = offer_sched_add($p['schema'], (int)$p['offer_id'], $p['changes'], $p['start'], $p['end']); unset($_SESSION['offer_sched_pending'], $_SESSION['offer_sched_form']);
            offer_sched_apply(); $x = offer_sched_get($id);
            flash($x && $x['state'] === 'failed' ? 'danger' : 'success', match ($x['state'] ?? '') { 'live', 'applied' => 'Saved and changed now: '.offer_sched_describe($x).'.', 'failed' => 'Saved, but the change failed: '.$x['note'], default => 'Saved: the offer changes at '.substr((string)$x['start_at'], 0, 16).($x['end_at'] ? ' and goes back at '.substr((string)$x['end_at'], 0, 16) : '').'.' });
        } elseif ($do === 'offer_stop') {
            $how = offer_sched_stop((int)($_POST['id'] ?? 0)); flash('success', $how === 'cancelled' ? 'Offer schedule cancelled: nothing was changed.' : 'Ended now: '.(offer_sched_get((int)$_POST['id'])['note'] ?? 'the old values are back.'));
        } elseif ($do === 'offer_retry') {
            $r = offer_sched_retry((int)($_POST['id'] ?? 0)); $x = offer_sched_get((int)($_POST['id'] ?? 0)); flash($r['failed'] ? 'danger' : 'success', ($r['failed'] ? 'It failed again: ' : 'Done: ').($x['note'] ?? ''));
        } elseif ($do === 'offer_back') { unset($_SESSION['offer_sched_pending']); redirect('?page=ussd_schedules&offer_id='.(int)($_POST['offer_id'] ?? 0).'#offer-form'); }
        else throw new RuntimeException('Unknown action.');
    } catch (RuntimeException $e) { flash('danger', $e->getMessage()); if ($do === 'offer_preview') redirect('?page=ussd_schedules&offer_id='.(int)($_POST['offer_id'] ?? 0).'#offer-form'); }
    redirect($back);
}
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
if ($canOffers) offer_sched_tick();

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

$offs = []; if ($canOffers) { try { $offs = offer_sched_list(); } catch (Throwable $e) { flash('warning', 'Could not read the offer schedules: '.$e->getMessage()); } }
$offOpen = array_values(array_filter($offs, fn($o) => in_array($o['state'], ['waiting', 'live'], true)));
$offFailed = array_values(array_filter($offs, fn($o) => $o['state'] === 'failed'));
$offHist = array_values(array_filter($offs, fn($o) => in_array($o['state'], ['ended', 'cancelled', 'applied'], true)));
$open = array_values(array_filter($mob, fn($s) => in_array($s['state'], ['waiting', 'live'], true)));
$failed = array_values(array_filter($mob, fn($s) => $s['state'] === 'failed'));
$hist = array_values(array_filter($mob, fn($s) => in_array($s['state'], ['ended', 'cancelled'], true)));
$count = fn(string $st) => count(array_filter($mob, fn($s) => $s['state'] === $st));
$offEnded30 = count(array_filter($offHist, fn($o) => $o['state'] !== 'cancelled' && strtotime((string)($o['end_at'] ?: $o['start_at'])) > time() - 30 * 86400));
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
foreach ($offOpen as $o) {
    $link = '?page=ussd_schedules#o'.$o['id'];
    if ($o['state'] === 'waiting' && $o['start_at'] <= $limit) $events[] = [$o['start_at'], 'start', 'Offer change', $o['offer_name'].' — '.offer_sched_describe($o), $o['offer_code'], $link];
    if ($o['end_at'] && $o['end_at'] <= $limit) $events[] = [$o['end_at'], 'end', 'Offer change', $o['offer_name'], $o['offer_code'], $link];
}
usort($events, fn($a, $b) => strcmp($a[0], $b[0]));
$byDay = []; foreach ($events as $ev) $byDay[substr($ev[0], 0, 10)][] = $ev;

// Is the every-minute job (deploy/k8s/06-schedule-cronjob.yaml) running? Without it, times can be up to 5 minutes late.
$cronLast = 0; try { $st = portal_pdo()->query("SELECT value FROM metric_cache WHERE cache_key='schedule_cron_last'"); $cronLast = (int)($st->fetchColumn() ?: 0); } catch (Throwable $e) {}
$cronOk = $cronLast > time() - 180;
$menus = []; $menusErr = '';
if ($mobiusOn) { $l = mobius_list_menus_cached($cfg); if ($l['ok']) { $menus = $l['menus']; usort($menus, fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''))); } else $menusErr = (string)$l['error']; }
$preMenu = (string)($_GET['menu'] ?? '');
// the offer form: find an offer by code or name, then fill in what changes
$offerQ = trim((string)($_GET['offer'] ?? '')); $offerPick = []; $offerSel = null; $pending = null; $form = $_SESSION['offer_sched_form'] ?? null;
if ($offersHere) {
    if (isset($_GET['offer_id'])) $offerSel = fetch_record($schema, 'vas_offers', ['id' => (int)$_GET['offer_id']]);
    elseif ($offerQ !== '') { $st = pdo($schema)->prepare('SELECT * FROM vas_offers WHERE offer_code=? OR offer_code_for_other=? OR name LIKE ? ORDER BY (status IN (\'1\',\'active\')) DESC, id DESC LIMIT 30'); $st->execute([$offerQ, $offerQ, '%'.$offerQ.'%']); $offerPick = $st->fetchAll(); if (count($offerPick) === 1) $offerSel = $offerPick[0]; }
    if ($offerSel && isset($_GET['preview']) && ($p = $_SESSION['offer_sched_pending'] ?? null) && (int)$p['offer_id'] === (int)$offerSel['id'] && $p['schema'] === $schema) $pending = $p;
    if ($form && (int)$form['offer_id'] !== (int)($offerSel['id'] ?? 0)) $form = null;
}
$offFields = $offersHere ? offer_sched_fields($schema) : [];

layout_start('USSD Schedules'); ussd_subnav('ussd_schedules');
$stateBadge = function (string $st): string { [$l, $c] = MOBIUS_SCHED_STATES[$st] ?? [$st, 'secondary']; return '<span class="badge bg-'.$c.($c === 'light' ? ' text-dark border' : '').'">'.e($l).'</span>'; };
$post = function (string $do, int $id, string $label, string $cls, string $confirm): string {
    return '<form method="post" class="d-inline" data-confirm="'.e($confirm).'"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="do" value="'.e($do).'"><input type="hidden" name="id" value="'.$id.'"><button class="btn btn-sm '.$cls.'">'.e($label).'</button></form>';
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
 <div><h2 class="h4 mb-1">Schedules</h2><p class="text-muted small mb-0">Every schedule in one place: Mobius menus put live on a short code at a time, portal menu items that go live or switch off at a time, and offer changes (price, data, validity, on/off…) that start at a time and go back at the end. Due changes run by themselves<?= $cronOk ? ' within a minute' : ' within about 5 minutes' ?>.</p>
 <div class="small mt-1"><?php if ($cronOk): ?><span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>Every-minute check is running</span> <span class="text-muted">(last <?=max(0, time() - $cronLast)?> s ago) — a launch at 00:00 happens by 00:01.</span><?php else: ?><span class="text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>The every-minute check is not running<?= $cronLast ? ' (last ran '.e(date('j M H:i', $cronLast)).')' : '' ?></span> <span class="text-muted">— schedules still run every 5 minutes, so 00:00 can become 00:05. Ask for <code>deploy/k8s/06-schedule-cronjob.yaml</code> to be applied.</span><?php endif; ?></div></div>
 <a class="btn btn-sm btn-outline-secondary" href="?page=alert_settings"><i class="fa-solid fa-bell me-1"></i>Who gets the alerts</a>
</div>

<div class="row g-2 mb-3">
 <?php $offLive = count(array_filter($offOpen, fn($o) => $o['state'] === 'live')); $offWait = count($offOpen) - $offLive;
 foreach ([['Live now', $count('live') + $offLive, 'success', 'fa-tower-broadcast'], ['Waiting', $count('waiting') + $offWait, 'secondary', 'fa-hourglass-half'], ['Failed', count($failed) + count($offFailed), count($failed) + count($offFailed) ? 'danger' : 'secondary', 'fa-circle-exclamation'], ['Ended (30 days)', $ended30 + $offEnded30, 'dark', 'fa-flag-checkered'], ['Portal items with a time', count($items), 'primary', 'fa-list-check']] as [$lb, $n, $c, $ic]): ?>
 <div class="col-6 col-md"><div class="cardx h-100 py-2"><div class="text-muted small text-uppercase"><i class="fa-solid <?=$ic?> me-1"></i><?=e($lb)?></div><div class="fs-3 fw-bold text-<?=$c?>"><?=number_format($n)?></div></div></div>
 <?php endforeach; ?>
</div>

<?php if ($failed): ?>
<div class="alert alert-danger"><b><i class="fa-solid fa-circle-exclamation me-1"></i><?=count($failed)?> schedule(s) failed.</b> Customers may be getting the wrong menu. Fix the cause (often Mobius could not be reached) and press <b>Try again</b>, or end it by hand in Mobius tools.
 <ul class="mb-0 mt-2"><?php foreach ($failed as $s): ?><li class="mb-1" id="s<?=(int)$s['id']?>"><b><?=e($s['menu_name'])?></b> → <code><?=e($s['live_code'])?></code>: <?=e($s['note'])?> <?php if ($mobiusOn) echo $post('retry', (int)$s['id'], 'Try again', 'btn-light ms-1', 'Try this schedule again now?'); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($offFailed): ?>
<div class="alert alert-danger"><b><i class="fa-solid fa-circle-exclamation me-1"></i><?=count($offFailed)?> offer schedule(s) failed.</b> The offer may have the wrong values now. Fix the cause and press <b>Try again</b>, or change the offer by hand in Offer Management.
 <ul class="mb-0 mt-2"><?php foreach ($offFailed as $o): ?><li class="mb-1" id="o<?=(int)$o['id']?>"><b><?=e($o['offer_code'].' '.$o['offer_name'])?></b> <span class="badge bg-light text-dark border"><?=e($o['schema_name'])?></span>: <?=e($o['note'])?> <?php if ($canOfferEdit) echo $post('offer_retry', (int)$o['id'], 'Try again', 'btn-light ms-1', 'Try this offer schedule again now?'); ?></li><?php endforeach; ?></ul></div>
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

 <?php if ($canOffers): ?><div class="cardx mb-3"><h3>Offer changes</h3>
 <?php if (!$offOpen): ?><p class="text-muted mb-0">No offer change waiting or live.<?= $canOfferEdit && $offersHere ? ' Use <b>Schedule an offer change</b> to add one.' : '' ?></p><?php else: ?>
 <div class="table-scroll"><table class="table table-hover align-middle"><thead><tr><th>Offer</th><th>What changes</th><th>Starts</th><th>Goes back</th><th>Status</th><th></th></tr></thead><tbody>
 <?php foreach ($offOpen as $o): [$sl, $sc] = OFFER_SCHED_STATES[$o['state']] ?? [$o['state'], 'secondary']; ?><tr id="o<?=(int)$o['id']?>">
  <td><b><?=e($o['offer_code'])?></b> <?=e($o['offer_name'])?><div class="small"><span class="badge bg-light text-dark border"><?=e($o['schema_name'])?></span><?= $o['created_by'] ? ' <span class="text-muted">by '.e($o['created_by']).'</span>' : '' ?> · <a href="?page=offer_history&id=<?=(int)$o['offer_id']?>">History</a></div></td>
  <td class="small"><?php $cur = null; if ($o['state'] === 'waiting') { try { $cur = fetch_record($o['schema_name'], 'vas_offers', ['id' => (int)$o['offer_id']]); } catch (Throwable $e) {} } ?><?=e(offer_sched_describe($o, $cur))?></td>
  <td class="small"><?=e($when($o['start_at']))?><div class="text-muted"><?=e($ago($o['start_at']))?></div></td>
  <td class="small"><?= $o['end_at'] ? e($when($o['end_at'])).'<div class="text-muted">'.e($ago($o['end_at'])).'</div>' : '<span class="text-muted">stays (no end)</span>' ?></td>
  <td><span class="badge bg-<?=$sc?>"><?=e($sl)?></span></td>
  <td class="text-end text-nowrap"><?php if ($canOfferEdit) echo $o['state'] === 'live' ? $post('offer_stop', (int)$o['id'], 'End now', 'btn-outline-danger', 'End it now and put the old values back?') : $post('offer_stop', (int)$o['id'], 'Cancel', 'btn-outline-secondary', 'Cancel this offer schedule? Nothing changes.'); ?></td></tr>
 <?php endforeach; ?></tbody></table></div><?php endif; ?></div><?php endif; ?>

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
 <?php if ($canOffers && $offHist): ?><div class="cardx mt-3"><h3>Offer changes — history</h3>
 <div class="table-scroll"><table class="table table-sm"><thead><tr><th>Offer</th><th>What changed</th><th>From</th><th>Until</th><th>Status</th><th>What happened</th></tr></thead><tbody>
 <?php foreach (array_slice($offHist, 0, 100) as $o): [$sl, $sc] = OFFER_SCHED_STATES[$o['state']] ?? [$o['state'], 'secondary']; ?><tr><td><b><?=e($o['offer_code'])?></b> <?=e($o['offer_name'])?><div class="small text-muted"><?=e($o['schema_name'])?><?= $o['created_by'] ? ' · by '.e($o['created_by']) : '' ?></div></td><td class="small"><?=e(offer_sched_describe($o))?></td><td class="small"><?=e($when($o['start_at']))?></td><td class="small"><?=e($when($o['end_at']))?></td><td><span class="badge bg-<?=$sc?><?=$sc === 'light' ? ' text-dark border' : ''?>"><?=e($sl)?></span></td><td class="small text-muted"><?=e($o['note'])?></td></tr><?php endforeach; ?>
 </tbody></table></div></div><?php endif; ?>
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
 <?php if ($canOfferEdit): ?><div class="cardx mb-3" id="offer-form"><h3>Schedule an offer change</h3>
 <?php if (!$offersHere): ?><p class="text-muted mb-0">There is no offer table in <?=e($schema)?>.</p>
 <?php else: ?>
 <p class="small text-muted">For offers in <b><?=e($schema)?></b> (switch the database at the top to change). At the start the new values are written; at the end the old ones go back — unless someone changed that field by hand meanwhile.</p>
 <form method="get" class="d-flex gap-1 mb-2"><input type="hidden" name="page" value="ussd_schedules"><input class="form-control form-control-sm" name="offer" value="<?=e($offerSel['offer_code'] ?? $offerQ)?>" placeholder="Offer code or name, e.g. 40165"><button class="btn btn-sm btn-outline-primary">Find</button></form>
 <?php if (!$offerSel && $offerQ !== ''): ?><?php if (!$offerPick): ?><p class="small text-muted mb-0">No offer found for “<?=e($offerQ)?>”.</p><?php else: ?><div class="list-group small mb-2"><?php foreach ($offerPick as $op): ?><a class="list-group-item list-group-item-action py-1" href="?page=ussd_schedules&offer_id=<?=(int)$op['id']?>#offer-form"><b><?=e($op['offer_code'])?></b> <?=e($op['name'])?> <span class="text-muted">· <?=e($op['vendor'] ?? '')?> · <?=offer_is_active($op['status'] ?? '') ? 'on' : 'off'?></span></a><?php endforeach; ?></div><?php endif; ?><?php endif; ?>
 <?php if ($offerSel && $pending): $pv = ['changes' => json_encode($pending['changes']), 'before_vals' => null]; ?>
  <div class="border rounded p-2 bg-light"><div class="fw-semibold mb-1">Check before saving</div>
   <div class="small mb-1"><b><?=e($offerSel['offer_code'])?></b> <?=e($offerSel['name'])?> (<?=e($schema)?>)</div>
   <table class="table table-sm small mb-2 no-pager"><thead><tr><th></th><th>Now</th><th>From the start</th><?php if (trim($pending['end']) !== ''): ?><th>After the end</th><?php endif; ?></tr></thead><tbody>
   <?php foreach ($pending['changes'] as $f => $v): ?><tr><td><?=e(OFFER_SCHED_FIELDS[$f] ?? $f)?></td><td><?=e(offer_sched_show($f, $offerSel[$f] ?? null))?></td><td class="fw-semibold text-success"><?=e(offer_sched_show($f, $v))?></td><?php if (trim($pending['end']) !== ''): ?><td><?=e(offer_sched_show($f, $offerSel[$f] ?? null))?></td><?php endif; ?></tr><?php endforeach; ?>
   </tbody></table>
   <div class="small mb-2">Starts: <b><?= trim($pending['start']) === '' ? 'now' : e($when(str_replace('T', ' ', $pending['start']))) ?></b> · Goes back: <b><?= trim($pending['end']) === '' ? 'never (it stays)' : e($when(str_replace('T', ' ', $pending['end']))) ?></b></div>
   <div class="d-flex gap-2"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="offer_add"><button class="btn btn-success btn-sm"><i class="fa-solid fa-check me-1"></i>Yes, save the schedule</button></form>
   <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="offer_back"><input type="hidden" name="offer_id" value="<?=(int)$offerSel['id']?>"><button class="btn btn-outline-secondary btn-sm">Change it</button></form></div>
  </div>
 <?php elseif ($offerSel): $fc = (array)($form['c'] ?? []); ?>
  <div class="small mb-2"><b><?=e($offerSel['offer_code'])?></b> <?=e($offerSel['name'])?> <span class="text-muted">· <?=e($offerSel['vendor'] ?? '')?> · <?=offer_is_active($offerSel['status'] ?? '') ? 'on' : 'off'?></span> · <a href="?page=offer_history&id=<?=(int)$offerSel['id']?>">History</a></div>
  <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="offer_preview"><input type="hidden" name="offer_id" value="<?=(int)$offerSel['id']?>">
   <div class="small text-muted mb-1">Fill in only what changes; empty = stays as it is.</div>
   <?php foreach ($offFields as $f => $label): $cur = $offerSel[$f] ?? null; ?>
    <label class="form-label small mb-0"><?=e($label)?> <span class="text-muted">— now: <?=e(mb_strimwidth(offer_sched_show($f, $cur), 0, 60, '…'))?></span></label>
    <?php if ($f === 'status'): ?><select class="form-select form-select-sm mb-1" name="c[status]"><option value="">no change</option><option value="1" <?=($fc['status'] ?? '') === '1' ? 'selected' : ''?>>On</option><option value="0" <?=($fc['status'] ?? '') === '0' ? 'selected' : ''?>>Off</option></select>
    <?php else: ?><input class="form-control form-control-sm mb-1" name="c[<?=e($f)?>]" value="<?=e($fc[$f] ?? '')?>"<?= $f === 'free_data' ? ' placeholder="e.g. 2048 or 2GB"' : '' ?>><?php endif; ?>
   <?php endforeach; ?>
   <label class="form-label small mb-0 mt-1">Start <span class="text-muted">(empty = now)</span></label><input class="form-control form-control-sm" type="datetime-local" name="start" value="<?=e($form['start'] ?? '')?>">
   <label class="form-label small mb-0">Put it back at <span class="text-muted">(empty = the change stays)</span></label><input class="form-control form-control-sm" type="datetime-local" name="end" value="<?=e($form['end'] ?? '')?>">
   <button class="btn btn-primary w-100 mt-2"><i class="fa-solid fa-eye me-1"></i>Check before saving</button>
  </form>
 <?php endif; ?>
 <?php endif; ?></div><?php endif; ?>
 <div class="cardx"><h3>Alerts</h3><p class="small mb-2">About <b>an hour before</b> a schedule starts (after checking the menu or offer is still there), and when it <b>goes live</b>, <b>ends</b> or <b>fails</b>, the portal sends a message:</p>
 <ul class="small mb-2"><li><b>Email</b> to the recipients with <b>Schedules</b> ticked in Alert Settings.</li><li><b>Slack</b> through an active <b>Monitoring</b> integration whose URL is a Slack webhook.</li></ul>
 <a class="small" href="?page=alert_settings">Alert Settings →</a></div>
</div>
</div>
<?php layout_end(); exit;
