<?php
declare(strict_types=1);
// Page: ?page=monthly_report — Reports → Monthly Report. Each person writes, per month, what they did on each project
// they work on (what was done, problems, next steps), plus a short summary. Drafts can be changed; "Final" locks the
// report (its author or a manager can reopen it). A clean printable copy (Print / Save as PDF) is at &print=1.
// Included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.
require_perm('view_reports');
$db = portal_pdo(); $me = user(); $myName = (string)($me['username'] ?? ''); $isManager = can('view_audit');
if (!defined('MR_STATUS')) define('MR_STATUS', ['in_progress' => ['In progress', 'primary'], 'done' => ['Done', 'success'], 'blocked' => ['Blocked', 'danger'], 'planned' => ['Planned', 'secondary']]);
// Routine entries that say nothing about the work done (sign-ins, page views, automatic checks).
if (!defined('MR_ACTIVITY_SKIP')) define('MR_ACTIVITY_SKIP', ['login', 'logout', 'daily_summary', 'test_integration', 'integrations_check_all', 'mobius_env', 'monthly_report_save']);

$mrLoad = function (int $id) use ($db): ?array { $st = $db->prepare('SELECT * FROM monthly_reports WHERE id=?'); $st->execute([$id]); return $st->fetch() ?: null; };
$mrItems = function (int $id) use ($db): array { $st = $db->prepare('SELECT * FROM monthly_report_items WHERE report_id=? ORDER BY sort_order, id'); $st->execute([$id]); return $st->fetchAll(); };
$canSee = fn(array $r): bool => $isManager || $r['author_username'] === $myName;
$canEdit = fn(array $r): bool => $r['author_username'] === $myName && $r['status'] === 'draft';
$monthLabel = fn(string $m): string => date('F Y', strtotime($m.'-01'));
$prevMonth = fn(string $m): string => date('Y-m', strtotime($m.'-01 -1 month'));
$projects = []; try { $projects = $db->query('SELECT id, project_name, status FROM portal_projects ORDER BY project_name')->fetchAll(); } catch (Throwable $e) {}
$projectId = function (string $name) use ($projects): ?int { foreach ($projects as $p) if (strcasecmp(trim($p['project_name']), trim($name)) === 0) return (int)$p['id']; return null; };

// ---- actions (POST; the CSRF token was checked by index.php) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'start') {
        $month = (string)($_POST['month'] ?? ''); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new RuntimeException('Pick a month.');
        $st = $db->prepare('SELECT id FROM monthly_reports WHERE report_month=? AND author_username=?'); $st->execute([$month, $myName]);
        if ($id = $st->fetchColumn()) redirect('?page=monthly_report&id='.(int)$id);
        $db->prepare('INSERT INTO monthly_reports(report_month, author_user_id, author_username) VALUES(?,?,?)')->execute([$month, $me['id'] ?? null, $myName]);
        $id = (int)$db->lastInsertId(); $copied = 0;
        // Carry over the projects that were not finished in the previous report of this person (the text starts empty).
        $st = $db->prepare('SELECT id FROM monthly_reports WHERE author_username=? AND report_month<? ORDER BY report_month DESC LIMIT 1'); $st->execute([$myName, $month]);
        if ($prev = $st->fetchColumn()) {
            $ins = $db->prepare('INSERT INTO monthly_report_items(report_id, sort_order, project_id, project_name, status, progress) VALUES(?,?,?,?,?,?)');
            foreach ($mrItems((int)$prev) as $it) if ($it['status'] !== 'done') $ins->execute([$id, ++$copied, $it['project_id'], $it['project_name'], $it['status'] === 'planned' ? 'in_progress' : $it['status'], $it['progress']]);
        }
        audit('monthly_report_start', null, 'monthly_reports', (string)$id, $month);
        flash('success', 'Report for '.$monthLabel($month).' started.'.($copied ? " $copied unfinished project(s) carried over from your last report." : ''));
        redirect('?page=monthly_report&id='.$id);
    }
    $r = $mrLoad((int)($_POST['id'] ?? 0)); if (!$r || !$canSee($r)) throw new RuntimeException('Report not found.');
    if ($act === 'save' || $act === 'finalize') {
        if (!$canEdit($r)) throw new RuntimeException('This report is final. Reopen it first to change it.');
        $txt = fn(string $k, int $max = 20000) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE monthly_reports SET summary=?, challenges=?, next_month=?, updated_at=NOW() WHERE id=?')->execute([$txt('summary'), $txt('challenges'), $txt('next_month'), $r['id']]);
            $db->prepare('DELETE FROM monthly_report_items WHERE report_id=?')->execute([$r['id']]);
            $ins = $db->prepare('INSERT INTO monthly_report_items(report_id, sort_order, project_id, project_name, status, progress, work_done, issues, next_steps) VALUES(?,?,?,?,?,?,?,?,?)');
            $n = 0;
            foreach ((array)($_POST['items'] ?? []) as $it) {
                if (!is_array($it)) continue; $name = mb_substr(trim((string)($it['name'] ?? '')), 0, 180); if ($name === '') continue;
                $status = array_key_exists((string)($it['status'] ?? ''), MR_STATUS) ? (string)$it['status'] : 'in_progress';
                $pr = trim((string)($it['progress'] ?? '')); $pr = $pr === '' ? null : max(0, min(100, (int)$pr));
                $t = fn(string $k) => mb_substr(trim((string)($it[$k] ?? '')), 0, 20000);
                $ins->execute([$r['id'], ++$n, $projectId($name), $name, $status, $pr, $t('work_done'), $t('issues'), $t('next_steps')]);
            }
            if ($act === 'finalize') $db->prepare("UPDATE monthly_reports SET status='final', finalized_at=NOW() WHERE id=?")->execute([$r['id']]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        audit($act === 'finalize' ? 'monthly_report_final' : 'monthly_report_save', null, 'monthly_reports', (string)$r['id'], $r['report_month']." ($n project lines)");
        flash('success', $act === 'finalize' ? 'Report marked final. Use Print / Save as PDF to send it.' : 'Saved.');
        redirect('?page=monthly_report&id='.$r['id']);
    }
    if ($act === 'reopen') {
        if ($r['status'] !== 'final' || !($r['author_username'] === $myName || $isManager)) throw new RuntimeException('Only the author or a manager can reopen a final report.');
        $db->prepare("UPDATE monthly_reports SET status='draft', finalized_at=NULL WHERE id=?")->execute([$r['id']]);
        audit('monthly_report_reopen', null, 'monthly_reports', (string)$r['id'], $r['report_month']);
        flash('info', 'Report reopened as a draft.'); redirect('?page=monthly_report&id='.$r['id']);
    }
    throw new RuntimeException('Unknown action.');
}

// What this person did in the portal during the month (from the Audit Trail) — a reminder while writing, not part of the report.
$activity = function (array $r) use ($db): array {
    $from = $r['report_month'].'-01 00:00:00'; $to = date('Y-m-d 00:00:00', strtotime($r['report_month'].'-01 +1 month'));
    $skip = implode(',', array_map(fn($a) => $db->quote($a), MR_ACTIVITY_SKIP));
    $st = $db->prepare("SELECT action, COUNT(*) c, MAX(created_at) last FROM portal_audit_trail WHERE username=? AND created_at>=? AND created_at<? AND action NOT IN ($skip) AND action NOT LIKE '%\\_view' GROUP BY action ORDER BY c DESC LIMIT 25");
    $st->execute([$r['author_username'], $from, $to]); return $st->fetchAll();
};
$badge = fn(string $s) => '<span class="badge bg-'.(MR_STATUS[$s][1] ?? 'secondary').'">'.e(MR_STATUS[$s][0] ?? $s).'</span>';
$para = fn(?string $t) => trim((string)$t) === '' ? '<span class="text-muted">—</span>' : nl2br(e($t));

// ---- printable copy ----
if (isset($_GET['id'], $_GET['print'])) {
    $r = $mrLoad((int)$_GET['id']); if (!$r || !$canSee($r)) throw new RuntimeException('Report not found.');
    $items = $mrItems((int)$r['id']); $count = array_count_values(array_column($items, 'status'));
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Monthly Report <?=e($monthLabel($r['report_month']))?> - <?=e($r['author_username'])?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet">
<style>body{max-width:900px;margin:1.5rem auto;padding:0 1rem;font-size:14px;color:#111}h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:1.4rem;border-bottom:2px solid #0b6bcb;padding-bottom:.2rem}.proj{border:1px solid #ddd;border-radius:6px;padding:.7rem .9rem;margin:.6rem 0;break-inside:avoid}.proj h3{font-size:1rem;margin:0 0 .4rem}.lbl{font-weight:600;font-size:.8rem;text-transform:uppercase;color:#555;margin-top:.4rem}@media print{.noprint{display:none}body{margin:0}}</style></head><body>
<div class="noprint mb-3 d-flex gap-2"><button class="btn btn-primary btn-sm" id="pr">Print / Save as PDF</button><a class="btn btn-outline-secondary btn-sm" href="?page=monthly_report&id=<?=e($r['id'])?>">Back</a></div>
<div class="d-flex align-items-center gap-3"><img src="comium_logo.png" alt="Comium" style="height:40px"><div><h1 class="mb-0">Monthly Report — <?=e($monthLabel($r['report_month']))?></h1><div class="text-muted">VAS Team · <?=e($r['author_username'])?> · <?= $r['status'] === 'final' ? 'Final ('.e(date('j M Y', strtotime((string)$r['finalized_at']))).')' : 'Draft' ?></div></div></div>
<p class="mt-3 mb-0"><b><?=count($items)?></b> project(s): <?php $bits = []; foreach (MR_STATUS as $k => [$lb]) if (!empty($count[$k])) $bits[] = $count[$k].' '.strtolower($lb); echo e(implode(' · ', $bits) ?: 'none'); ?></p>
<h2>Summary</h2><div><?=$para($r['summary'])?></div>
<h2>Projects</h2><?php if (!$items): ?><p class="text-muted">No projects listed.</p><?php endif; foreach ($items as $it): ?>
<div class="proj"><h3><?=e($it['project_name'])?> <?=$badge($it['status'])?><?= $it['progress'] !== null ? ' <small class="text-muted">'.(int)$it['progress'].'%</small>' : '' ?></h3>
<div class="lbl">What was done</div><div><?=$para($it['work_done'])?></div>
<?php if (trim((string)$it['issues']) !== ''): ?><div class="lbl">Problems / blockers</div><div><?=$para($it['issues'])?></div><?php endif; ?>
<?php if (trim((string)$it['next_steps']) !== ''): ?><div class="lbl">Next steps</div><div><?=$para($it['next_steps'])?></div><?php endif; ?></div>
<?php endforeach; ?>
<h2>Challenges</h2><div><?=$para($r['challenges'])?></div>
<h2>Plan for next month</h2><div><?=$para($r['next_month'])?></div>
<script nonce="<?=e(csp_nonce())?>">document.getElementById('pr').addEventListener('click',()=>window.print());</script></body></html><?php
    exit;
}

// ---- one report: edit (own draft) or read ----
if (isset($_GET['id'])) {
    $r = $mrLoad((int)$_GET['id']); if (!$r || !$canSee($r)) throw new RuntimeException('Report not found.');
    $items = $mrItems((int)$r['id']); $edit = $canEdit($r);
    // last month's "next steps" per project, shown as a reminder under each line
    $hint = []; $st = $db->prepare('SELECT id FROM monthly_reports WHERE author_username=? AND report_month<? ORDER BY report_month DESC LIMIT 1'); $st->execute([$r['author_username'], $r['report_month']]);
    if ($prev = $st->fetchColumn()) foreach ($mrItems((int)$prev) as $it) if (trim((string)$it['next_steps']) !== '') $hint[mb_strtolower(trim($it['project_name']))] = $it['next_steps'];
    $acts = []; try { $acts = $activity($r); } catch (Throwable $e) {}
    layout_start('Monthly Report '.$monthLabel($r['report_month']));
    $row = function (int $i, array $it) use ($hint, $edit, $badge, $para): void {
        $h = $hint[mb_strtolower(trim((string)($it['project_name'] ?? '')))] ?? '';
        if (!$edit) { ?>
<div class="mr-item cardx mb-2"><h4 class="h6 mb-2"><?=e($it['project_name'])?> <?=$badge($it['status'])?><?= $it['progress'] !== null ? ' <small class="text-muted">'.(int)$it['progress'].'%</small>' : '' ?></h4>
<div class="small text-uppercase text-muted fw-semibold">What was done</div><div class="mb-2"><?=$para($it['work_done'])?></div>
<div class="small text-uppercase text-muted fw-semibold">Problems / blockers</div><div class="mb-2"><?=$para($it['issues'])?></div>
<div class="small text-uppercase text-muted fw-semibold">Next steps</div><div><?=$para($it['next_steps'])?></div></div>
<?php return; } ?>
<div class="mr-item cardx mb-2" data-mr-item>
 <div class="row g-2 align-items-end">
  <div class="col-md-6"><label class="form-label small mb-0">Project</label><input class="form-control" name="items[<?=$i?>][name]" list="mr-projects" value="<?=e($it['project_name'] ?? '')?>" placeholder="e.g. USSD testbed, KAA 1.5GB menu, YAS trunk"></div>
  <div class="col-6 col-md-3"><label class="form-label small mb-0">Status</label><select class="form-select" name="items[<?=$i?>][status]"><?php foreach (MR_STATUS as $k => [$lb]): ?><option value="<?=$k?>" <?=($it['status'] ?? 'in_progress') === $k ? 'selected' : ''?>><?=e($lb)?></option><?php endforeach; ?></select></div>
  <div class="col-4 col-md-2"><label class="form-label small mb-0">Progress %</label><input class="form-control" type="number" min="0" max="100" name="items[<?=$i?>][progress]" value="<?=e($it['progress'] ?? '')?>"></div>
  <div class="col-2 col-md-1 text-end"><button type="button" class="btn btn-outline-danger btn-sm" data-mr-remove title="Remove this project line"><i class="fa-solid fa-xmark"></i></button></div>
 </div>
 <?php if ($h !== ''): ?><div class="alert alert-light border small py-1 px-2 mt-2 mb-0"><b>Planned last month:</b> <?=nl2br(e($h))?></div><?php endif; ?>
 <label class="form-label small mb-0 mt-2">What was done this month</label><textarea class="form-control" rows="3" name="items[<?=$i?>][work_done]" placeholder="One line per task: built…, tested…, fixed…, met…"><?=e($it['work_done'] ?? '')?></textarea>
 <div class="row g-2 mt-0"><div class="col-md-6"><label class="form-label small mb-0">Problems / blockers</label><textarea class="form-control" rows="2" name="items[<?=$i?>][issues]" placeholder="What slowed it down, who it waits on"><?=e($it['issues'] ?? '')?></textarea></div>
 <div class="col-md-6"><label class="form-label small mb-0">Next steps</label><textarea class="form-control" rows="2" name="items[<?=$i?>][next_steps]" placeholder="What comes next on this project"><?=e($it['next_steps'] ?? '')?></textarea></div></div>
</div>
<?php }; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
 <div><a href="?page=monthly_report" class="small">&larr; All reports</a><h2 class="h4 mb-0">Monthly Report — <?=e($monthLabel($r['report_month']))?></h2><div class="text-muted small"><?=e($r['author_username'])?> · <?= $r['status'] === 'final' ? '<span class="badge bg-success">Final</span> '.e(date('j M Y H:i', strtotime((string)$r['finalized_at']))) : '<span class="badge bg-warning text-dark">Draft</span>' ?></div></div>
 <div class="d-flex gap-2"><a class="btn btn-outline-primary btn-sm" href="?page=monthly_report&id=<?=e($r['id'])?>&print=1"><i class="fa-solid fa-print me-1"></i>Print / Save as PDF</a>
 <?php if ($r['status'] === 'final' && ($r['author_username'] === $myName || $isManager)): ?><form method="post" data-confirm="Reopen this report as a draft so it can be changed?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-outline-secondary btn-sm" name="act" value="reopen"><i class="fa-solid fa-lock-open me-1"></i>Reopen</button></form><?php endif; ?></div>
</div>
<div class="row g-3"><div class="col-lg-8">
<?php if ($edit): ?><form method="post" id="mr-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($r['id'])?>"><?php endif; ?>
<div class="cardx mb-3"><h3>Summary</h3><?php if ($edit): ?><textarea class="form-control" rows="4" name="summary" placeholder="Two or three sentences: the main things done this month."><?=e($r['summary'])?></textarea><?php else: ?><div><?=$para($r['summary'])?></div><?php endif; ?></div>
<div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h5 mb-0">Projects (<?=count($items)?>)</h3><?php if ($edit): ?><button type="button" class="btn btn-sm btn-outline-primary" id="mr-add"><i class="fa-solid fa-plus me-1"></i>Add a project</button><?php endif; ?></div>
<div id="mr-items"><?php foreach ($items as $i => $it) $row($i, $it); if ($edit && !$items) $row(0, ['project_name' => '', 'status' => 'in_progress', 'progress' => null]); if (!$edit && !$items): ?><p class="text-muted">No projects listed.</p><?php endif; ?></div>
<div class="cardx mb-3 mt-3"><h3>Challenges</h3><?php if ($edit): ?><textarea class="form-control" rows="3" name="challenges" placeholder="Anything that needs the manager's help: access, other teams, tools…"><?=e($r['challenges'])?></textarea><?php else: ?><div><?=$para($r['challenges'])?></div><?php endif; ?></div>
<div class="cardx mb-3"><h3>Plan for next month</h3><?php if ($edit): ?><textarea class="form-control" rows="3" name="next_month"><?=e($r['next_month'])?></textarea><?php else: ?><div><?=$para($r['next_month'])?></div><?php endif; ?></div>
<?php if ($edit): ?>
<div class="d-flex gap-2 mb-4 mr-actions"><button class="btn btn-primary" name="act" value="save"><i class="fa-solid fa-floppy-disk me-1"></i>Save draft</button><button class="btn btn-success" name="act" value="finalize" id="mr-final"><i class="fa-solid fa-lock me-1"></i>Save and mark final</button></div>
<datalist id="mr-projects"><?php foreach ($projects as $p): ?><option value="<?=e($p['project_name'])?>"><?php endforeach; ?></datalist>
<template id="mr-tpl"><?php $row(999999, ['project_name' => '', 'status' => 'in_progress', 'progress' => null]); ?></template>
</form>
<script nonce="<?=e(csp_nonce())?>">(()=>{let n=<?=max(1, count($items))?>;const box=document.getElementById('mr-items'),tpl=document.getElementById('mr-tpl').innerHTML;
const wire=el=>el.querySelectorAll('[data-mr-remove]').forEach(b=>b.addEventListener('click',()=>{const it=b.closest('[data-mr-item]');if(document.querySelectorAll('#mr-items [data-mr-item]').length>1||confirm('Remove this project line?'))it.remove();}));
wire(box);document.getElementById('mr-add').addEventListener('click',()=>{box.insertAdjacentHTML('beforeend',tpl.replaceAll('999999',String(n++)));const last=box.lastElementChild;wire(last);last.querySelector('input').focus();});
document.getElementById('mr-final').addEventListener('click',e=>{if(!confirm('Mark this report final? It can still be reopened later.'))e.preventDefault();});
let dirty=false;document.getElementById('mr-form').addEventListener('input',()=>dirty=true);document.getElementById('mr-form').addEventListener('submit',()=>dirty=false);
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});})();</script>
<?php endif; ?>
</div><div class="col-lg-4">
<div class="cardx mb-3"><h3>Your portal activity in <?=e($monthLabel($r['report_month']))?></h3><p class="text-muted small">From the Audit Trail — a reminder of what <?=e($r['author_username'])?> changed in the portal this month. It is not printed in the report.</p>
<?php if (!$acts): ?><p class="text-muted small mb-0">Nothing recorded.</p><?php else: ?><table class="table table-sm mb-0 no-pager"><tbody><?php foreach ($acts as $a): ?><tr><td><?=e(ucfirst(str_replace('_', ' ', (string)$a['action'])))?></td><td class="text-end"><?=number_format((int)$a['c'])?>×</td><td class="text-muted small text-nowrap"><?=e(date('j M', strtotime((string)$a['last'])))?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<div class="cardx"><h3>Tips</h3><ul class="small mb-0"><li>One line per project you worked on, even small ones (a test, a meeting, a fix).</li><li>Write results, not effort: <i>“KAA 1.5GB menu live on *606*6*5#, 33 sales in the first days”</i>.</li><li>Put what you wait on under <b>Problems / blockers</b>, with the team’s name.</li><li>Unfinished projects move to next month’s report automatically.</li></ul></div>
</div></div>
<?php layout_end(); exit;
}

// ---- list ----
layout_start('Monthly Report');
$months = []; for ($k = 0; $k < 12; $k++) $months[] = date('Y-m', strtotime(date('Y-m-01')." -$k month"));
$default = (int)date('j') <= 7 ? $months[1] : $months[0]; // early in a month you usually write the one that just ended
$who = $isManager ? (string)($_GET['who'] ?? '') : $myName;
$sql = 'SELECT r.*, (SELECT COUNT(*) FROM monthly_report_items i WHERE i.report_id=r.id) items, (SELECT COUNT(*) FROM monthly_report_items i WHERE i.report_id=r.id AND i.status=\'done\') done FROM monthly_reports r'.($who !== '' ? ' WHERE r.author_username=?' : '').' ORDER BY r.report_month DESC, r.author_username LIMIT 500';
$st = $db->prepare($sql); $st->execute($who !== '' ? [$who] : []); $rows = $st->fetchAll();
$authors = $isManager ? $db->query('SELECT DISTINCT author_username FROM monthly_reports ORDER BY author_username')->fetchAll(PDO::FETCH_COLUMN) : [];
?>
<div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3>Write a monthly report</h3><p class="text-muted small">One report per month: the projects you worked on, what was done, problems and next steps. Projects you did not finish last month are carried over.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="act" value="start"><label class="form-label">Month</label><select class="form-select mb-3" name="month"><?php foreach ($months as $m): ?><option value="<?=$m?>" <?=$m === $default ? 'selected' : ''?>><?=e($monthLabel($m))?></option><?php endforeach; ?></select><button class="btn btn-primary w-100"><i class="fa-solid fa-file-pen me-1"></i>Open the report</button></form>
<?php if (can('manage_projects')): ?><p class="small text-muted mt-3 mb-0">Project names come from the <a href="?page=projects">Project Register</a>; you can also type any other name.</p><?php endif; ?></div></div>
<div class="col-lg-8"><div class="cardx"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h3 class="mb-0"><?= $isManager ? 'Reports' : 'Your reports' ?></h3>
<?php if ($isManager): ?><form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="monthly_report"><select class="form-select form-select-sm" name="who" data-autosubmit><option value="">Everyone</option><?php foreach ($authors as $a): ?><option <?=$a === $who ? 'selected' : ''?>><?=e($a)?></option><?php endforeach; ?></select></form><?php endif; ?></div>
<?php if (!$rows): ?><p class="text-muted mt-3 mb-0">No reports yet. Pick a month and press <b>Open the report</b>.</p><?php else: ?>
<div class="table-scroll mt-2"><table class="table table-hover"><thead><tr><th>Month</th><?php if ($isManager): ?><th>Author</th><?php endif; ?><th>Projects</th><th>Status</th><th>Updated</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><a href="?page=monthly_report&id=<?=e($r['id'])?>"><b><?=e($monthLabel($r['report_month']))?></b></a></td><?php if ($isManager): ?><td><?=e($r['author_username'])?></td><?php endif; ?><td><?=(int)$r['items']?><?= (int)$r['done'] ? ' <small class="text-muted">('.(int)$r['done'].' done)</small>' : '' ?></td><td><?= $r['status'] === 'final' ? '<span class="badge bg-success">Final</span>' : '<span class="badge bg-warning text-dark">Draft</span>' ?></td><td class="small text-muted"><?=e(date('j M Y', strtotime((string)($r['updated_at'] ?: $r['created_at']))))?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=monthly_report&id=<?=e($r['id'])?>&print=1" title="Print / Save as PDF"><i class="fa-solid fa-print"></i></a></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></div></div>
<?php layout_end(); exit;
