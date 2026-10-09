<?php
declare(strict_types=1);
// Page: ?page=vendor — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_reports'); $schema=current_schema();
    $vendor=trim((string)($_GET['name']??'')); if($vendor==='') throw new RuntimeException('Pick a vendor from the Monitoring page.');
    $date=valid_day((string)($_GET['date']??date('Y-m-d')));
    $v=vendor_detail($schema,$vendor,$date); $slowLimit=(int)alert_config()['vendor_slow_ms'];
    $pct=$v['total']>0?round(100*$v['ok']/$v['total'],1):0; $maxH=max(1,...array_merge([1],array_map('intval',array_column($v['hourly'],'total'))));
    $byHour=[]; foreach($v['hourly'] as $h) $byHour[(int)$h['h']]=$h;
    layout_start('Vendor: '.$vendor);
    ?>
    <div class="cardx d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div><a href="?page=monitoring">&larr; Monitoring</a></div>
        <form method="get" class="d-flex gap-2 align-items-center"><input type="hidden" name="page" value="vendor"><input type="hidden" name="name" value="<?=e($vendor)?>">
            <label class="small text-muted mb-0">Day</label><input type="date" class="form-control form-control-sm" name="date" value="<?=e($date)?>" max="<?=date('Y-m-d')?>" min="<?=date('Y-m-d',strtotime('-31 days'))?>">
            <button class="btn btn-sm btn-outline-primary">Show</button></form>
    </div>
    <div class="metric-grid mt-3">
        <div class="metric"><span>Transactions</span><strong><?=number_format($v['total'])?></strong><small class="text-muted"><?=$v['prev_total']>0?'Day before: '.number_format($v['prev_total']):'—'?></small></div>
        <div class="metric"><span>Success rate</span><strong><?=$v['total']>0?$pct.'%':'—'?></strong><small class="text-muted"><?=number_format($v['failed'])?> failed</small></div>
        <div class="metric"><span>Avg response</span><strong class="<?=$v['avg_ms']!==null && $v['avg_ms']>$slowLimit?'text-danger':''?>"><?=$v['avg_ms']!==null?number_format($v['avg_ms']).' ms':'—'?></strong><small class="text-muted">slowest <?=$v['max_ms']!==null?number_format($v['max_ms']).' ms':'—'?></small></div>
        <div class="metric"><span>Slow transactions</span><strong><?=number_format($v['slow'])?></strong><small class="text-muted">over <?=number_format($slowLimit)?> ms</small></div>
    </div>
    <div class="cardx mt-3"><h3>By hour</h3>
        <?php if(!$v['hourly']):?><p class="text-muted mb-0">No transactions from this vendor on <?=e($date)?>.</p><?php else:?>
        <div class="hour-bars"><?php for($h=0;$h<24;$h++): $r=$byHour[$h]??null; $t=$r?(int)$r['total']:0; $f=$r?(int)$r['failed']:0;?>
            <div class="hb" title="<?=sprintf('%02d:00 — %d transactions, %d failed%s',$h,$t,$f,$r&&$r['avg_ms']!==null?', avg '.round((float)$r['avg_ms']).' ms':'')?>"><div class="hb-col"><div class="hb-ok" style="height:<?=round(100*($t-$f)/$maxH)?>%"></div><div class="hb-bad" style="height:<?=round(100*$f/$maxH)?>%"></div></div><small><?=sprintf('%02d',$h)?></small></div>
        <?php endfor;?></div>
        <small class="text-muted"><span class="dot-ok"></span> succeeded &nbsp; <span class="dot-bad"></span> failed — hover a bar for the exact numbers.</small><?php endif;?>
    </div>
    <div class="row g-3 mt-0">
        <div class="col-lg-6"><div class="cardx h-100"><h3>By channel</h3>
            <?php if(!$v['channels']):?><p class="text-muted mb-0">—</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>Channel</th><th class="text-end">Transactions</th><th class="text-end">Success</th><th class="text-end">Avg ms</th></tr></thead><tbody>
            <?php foreach($v['channels'] as $c):?><tr><td><?=e($c['channel'])?></td><td class="text-end"><?=number_format((int)$c['total'])?></td><td class="text-end"><?=round(100*$c['ok']/max(1,$c['total']),1)?>%</td><td class="text-end"><?=$c['avg_ms']!==null?number_format((float)$c['avg_ms']):'—'?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
        <div class="col-lg-6"><div class="cardx h-100"><h3>Failure reasons</h3>
            <?php if(!$v['reasons']):?><p class="text-muted mb-0">No failures.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><tbody>
            <?php foreach($v['reasons'] as $rs):?><tr><td class="cell-full"><?=e($rs['reason'])?></td><td class="text-end text-nowrap"><?=number_format((int)$rs['c'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
    </div>
    <div class="cardx table-card mt-3"><h3 class="px-3 pt-3">Latest failures <small class="text-muted">(newest 15)</small></h3>
        <?php if(!$v['recent_failures']):?><p class="text-muted px-3 pb-3 mb-0">None.</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th>Details</th><th>Time</th><th>MSISDN</th><th>Channel</th><th>Reason</th><th class="text-end">ms</th></tr></thead><tbody>
        <?php foreach($v['recent_failures'] as $r):?><tr>
            <td><button type="button" class="btn btn-sm btn-outline-primary" data-tx-view data-id="<?=e($r['id'])?>" data-date="<?=e($r['create_date'])?>"><i class="fa-solid fa-eye me-1"></i>View</button></td>
            <td class="text-nowrap"><?=e($r['create_date'])?></td><td><?=e($r['msisdn'])?></td><td><?=e($r['channel'])?></td><td class="cell-full"><?=e($r['result_description'])?></td><td class="text-end"><?=e($r['response_time'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
        <p class="px-3 pb-3 mb-0"><a href="?page=investigate&date_from=<?=e($date)?>&date_to=<?=e($date)?>&vendor=<?=urlencode($vendor)?>">All transactions from this vendor on <?=e($date)?> →</a></p>
    </div>
    <?php include_once __DIR__.'/../lib/txviewer_modal.php'; ?>
    <script src="txviewer.js?v=<?=e((string)(@filemtime(($_SERVER['DOCUMENT_ROOT'] ?? __DIR__).'/txviewer.js') ?: time()))?>"></script>
    <?php layout_end(); exit;
