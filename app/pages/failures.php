<?php
declare(strict_types=1);
// Page: ?page=failures — Monitoring → Real failures: failed customer transactions that are not on the customer's side
// (the reasons ignored in Alert Settings, such as no balance, are left out), by reason, offer, vendor, channel and time.
// Counts only. Included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.
require_perm('view_reports'); $schema = current_schema();
$periods = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];
$period = array_key_exists((string)($_GET['p'] ?? ''), $periods) ? (string)$_GET['p'] : 'today';
[$from, $to] = match ($period) {
    'yesterday' => [date('Y-m-d', strtotime('yesterday')), date('Y-m-d')],
    '7d' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d', strtotime('tomorrow'))],
    '30d' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d', strtotime('tomorrow'))],
    default => [date('Y-m-d'), date('Y-m-d', strtotime('tomorrow'))],
};
$channel = preg_match('/^[A-Za-z0-9_]{1,20}$/', (string)($_GET['ch'] ?? '')) ? (string)$_GET['ch'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'send_now') {
    require_perm('manage_api_keys'); $err = send_failure_report($schema);
    flash($err ? 'danger' : 'success', $err ? 'Not sent: '.$err : 'Yesterday\'s real-failure report was sent to '.count(failure_report_recipients()).' address(es).'); redirect('?page=failures');
}
$r = real_failures($schema, $from.' 00:00:00', $to.' 00:00:00', $channel);
$ignored = alert_ignored_reasons();
$inv = function (array $extra = []) use ($from, $to, $channel): string {
    $q = ['page' => 'investigate', 'date_from' => $from, 'date_to' => date('Y-m-d', strtotime($to.' -1 day'))] + ($channel !== '' ? ['channel' => $channel] : []) + $extra;
    return '?'.http_build_query($q);
};
$pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b, 1) : 0;
layout_start('Real failures'); monitoring_subnav('failures');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
 <div><h2 class="h4 mb-1">Real failures</h2><p class="text-muted small mb-0">Failed customer transactions that are <b>not</b> on the customer's side: the reasons ignored in <a href="?page=alert_settings">Alert Settings</a><?= $ignored ? ' ('.e(mb_strimwidth(implode('; ', $ignored), 0, 140, '…')).')' : '' ?> are left out. These are the ones to fix with Hera, the OCS or the network. Counts only — no customer numbers.</p></div>
 <form method="get" class="d-flex gap-2 flex-wrap"><input type="hidden" name="page" value="failures">
  <select class="form-select form-select-sm" name="p" data-autosubmit><?php foreach ($periods as $k => $lb): ?><option value="<?=$k?>" <?=$k === $period ? 'selected' : ''?>><?=e($lb)?></option><?php endforeach; ?></select>
  <select class="form-select form-select-sm" name="ch" data-autosubmit><option value="">All channels</option><?php foreach (['USSD', 'IVR', 'SMS', 'WEB', 'APP', 'REF'] as $c): ?><option <?=$c === $channel ? 'selected' : ''?>><?=$c?></option><?php endforeach; ?></select>
 </form>
</div>
<div class="metric-grid">
 <div class="metric"><span>Customer transactions</span><strong><?=number_format($r['total'])?></strong><small class="text-muted"><?=e($periods[$period])?><?= $channel !== '' ? ' · '.e($channel) : '' ?></small></div>
 <div class="metric"><span>Failed</span><strong><?=number_format($r['failed'])?></strong><small class="text-muted"><?=$pct($r['failed'], $r['total'])?>% · <?=number_format($r['failed'] - $r['real'])?> on the customer's side</small></div>
 <div class="metric"><span>Real failures</span><strong class="<?=$r['real'] ? 'text-danger' : 'text-success'?>"><?=number_format($r['real'])?></strong><small class="text-muted"><?=$pct($r['real'], $r['total'])?>% of all transactions</small></div>
 <div class="metric"><span>Daily report</span><strong style="font-size:1.1rem;text-transform:none"><?php $cfgA = alert_config(); $rcp = failure_report_recipients(); ?><?= $cfgA['failure_report_enabled'] ? '<span class="text-success">On</span>' : '<span class="text-muted">Off</span>' ?></strong><small class="text-muted"><?= $cfgA['failure_report_enabled'] ? 'to '.count($rcp).' address(es) at '.sprintf('%02d:00', $cfgA['failure_report_hour']) : 'set it up in Alert Settings' ?></small></div>
</div>
<?php if (!$r['real']): ?><div class="cardx mt-3"><p class="mb-0 text-success"><i class="fa-solid fa-circle-check me-1"></i>No real failures in this period.</p></div><?php else: ?>
<div class="row g-3 mt-1">
 <div class="col-lg-6"><div class="cardx h-100"><h3>By reason</h3>
  <div class="table-scroll"><table class="table table-sm align-middle mb-0 no-pager"><tbody>
  <?php foreach ($r['reasons'] as $x): $sh = $pct((int)$x['rf'], $r['real']); ?><tr><td class="cell-full"><?=e($x['k'])?></td><td style="width:30%"><div class="progress" style="height:8px"><div class="progress-bar bg-danger" style="width:<?=$sh?>%"></div></div></td><td class="text-end text-nowrap"><?=number_format((int)$x['rf'])?> <small class="text-muted">(<?=$sh?>%)</small></td><td class="text-nowrap"><?php if ($x['k'] !== '(no reason given)'): ?><a href="<?=e($inv(['result_desc' => $x['k']]))?>">Investigate →</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
 <div class="col-lg-6"><div class="cardx h-100"><h3>By offer <small class="text-muted">(failed / attempts)</small></h3>
  <div class="table-scroll"><table class="table table-sm align-middle mb-0 no-pager"><thead><tr><th>Offer</th><th class="text-end">Failed</th><th class="text-end">Rate</th><th>Main reason</th></tr></thead><tbody>
  <?php foreach ($r['offers'] as $x): $code = (string)$x['k']; $rate = $pct((int)$x['rf'], (int)$x['n']); ?><tr><td><?= $code === '' ? '<span class="text-muted">(no offer recorded)</span>' : '<b>'.e($code).'</b> '.e($x['name'] ?? '') ?></td><td class="text-end"><?=number_format((int)$x['rf'])?> <small class="text-muted">/ <?=number_format((int)$x['n'])?></small></td><td class="text-end <?=$rate >= 10 ? 'text-danger fw-semibold' : ''?>"><?=$rate?>%</td><td class="small text-muted"><?=e(mb_strimwidth((string)($x['top_reason'] ?? ''), 0, 70, '…'))?></td></tr><?php endforeach; ?>
  </tbody></table></div><div class="small text-muted mt-1">The offer comes from the purchase's transaction id; balance and policy calls have none.</div></div></div>
 <div class="col-lg-6"><div class="cardx h-100"><h3>By vendor</h3>
  <div class="table-scroll"><table class="table table-sm align-middle mb-0 no-pager"><thead><tr><th>Vendor</th><th class="text-end">Failed</th><th class="text-end">Rate</th><th></th></tr></thead><tbody>
  <?php foreach ($r['vendors'] as $x): ?><tr><td><?= $x['k'] === '(none)' ? '<span class="text-muted">(none)</span>' : '<a href="?page=vendor&name='.urlencode((string)$x['k']).'">'.e($x['k']).'</a>' ?></td><td class="text-end"><?=number_format((int)$x['rf'])?> <small class="text-muted">/ <?=number_format((int)$x['n'])?></small></td><td class="text-end"><?=$pct((int)$x['rf'], (int)$x['n'])?>%</td><td class="text-nowrap"><?php if ($x['k'] !== '(none)'): ?><a href="<?=e($inv(['vendor' => $x['k']]))?>">Investigate →</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
 <div class="col-lg-6"><div class="cardx h-100"><h3>By channel</h3>
  <div class="table-scroll"><table class="table table-sm align-middle mb-0 no-pager"><thead><tr><th>Channel</th><th class="text-end">Failed</th><th class="text-end">Rate</th></tr></thead><tbody>
  <?php foreach ($r['channels'] as $x): ?><tr><td><a href="?page=failures&p=<?=e($period)?>&ch=<?=urlencode((string)$x['k'])?>"><?=e($x['k'])?></a></td><td class="text-end"><?=number_format((int)$x['rf'])?> <small class="text-muted">/ <?=number_format((int)$x['n'])?></small></td><td class="text-end"><?=$pct((int)$x['rf'], (int)$x['n'])?>%</td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
</div>
<div class="cardx mt-3"><h3>Over time <small class="text-muted">(real failures, and their share of all transactions)</small></h3><div style="height:240px"><canvas id="rfChart"></canvas></div></div>
<script nonce="<?=e(csp_nonce())?>">(()=>{const rows=<?=json_encode(array_map(fn($x) => ['k' => (string)$x['k'], 'n' => (int)$x['n'], 'rf' => (int)$x['rf']], $r['time']), JSON_HEX_TAG)?>, byDay=<?=json_encode($r['by'] === 'day')?>;
 const labels=rows.map(x=>byDay?x.k.slice(5):String(x.k).padStart(2,'0')+':00');
 new Chart(document.getElementById('rfChart'),{data:{labels,datasets:[{type:'bar',label:'Real failures',data:rows.map(x=>x.rf),backgroundColor:'rgba(220,53,69,.8)',yAxisID:'y'},{type:'line',label:'% of transactions',data:rows.map(x=>x.n?Math.round(1000*x.rf/x.n)/10:0),borderColor:'#212529',pointRadius:2,tension:.3,yAxisID:'y1'}]},
  options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{y:{beginAtZero:true,position:'left'},y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},ticks:{callback:v=>v+'%'}}}}});})();</script>
<?php endif; ?>
<?php if (can('manage_api_keys')): ?><div class="cardx mt-3"><h3>Daily report by email</h3>
 <p class="small text-muted mb-2">Each morning, yesterday's real failures by reason, offer, vendor and hour, to the addresses you choose (for example the Hera and OCS teams). Set it up in <a href="?page=alert_settings#failure-report">Alert Settings</a>.</p>
 <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="send_now"><button class="btn btn-sm btn-outline-primary" <?=failure_report_recipients() ? '' : 'disabled'?>><i class="fa-solid fa-paper-plane me-1"></i>Send yesterday's report now</button></form>
</div><?php endif; ?>
<?php layout_end(); exit;
