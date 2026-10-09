<?php
declare(strict_types=1);
// Page: ?page=monitoring — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_reports'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='check_all') {
        require_perm('edit_records');
        $r=check_all_integrations();
        flash($r['down']?'warning':'success', sprintf('Checked %d integration(s): %d up, %d down.%s', $r['up']+$r['down'], $r['up'], $r['down'], $r['skipped']?' '.$r['skipped'].' not checked (time limit) — run it again.':''));
        redirect('?page=monitoring');
    }
    $snap=monitoring_snapshot($schema); $today=date('Y-m-d');
    $prio=['down'=>0,'never'=>1,'stale'=>2,'up'=>3,'off'=>4];
    $ints=$snap['integrations']; usort($ints, fn($x,$y)=>($prio[integration_health($x)['state']]<=>$prio[integration_health($y)['state']]) ?: strcmp($x['name'],$y['name']));
    $activeInts=count(array_filter($ints, fn($i)=>$i['status']==='active'));
    layout_start('Monitoring');
    ?>
    <?php foreach($snap['alerts'] as $a):?><div class="alert alert-<?=e($a['level'])?> shadow-sm">⚠ <?=e($a['message'])?> <a class="alert-link" href="?page=alerts">View alerts</a></div><?php endforeach;?>
    <div class="metric-grid">
        <div class="metric"><span>Customer transactions today</span><strong><?=number_format($snap['tx_total'])?></strong><small class="text-muted"><?=$snap['tx_success_pct']===null?'no traffic yet':$snap['tx_success_pct'].'% succeeded'?> · customer traffic<?=$snap['lookups']['total']>0?', plus '.number_format($snap['lookups']['total']).' system lookups':''?></small></div>
        <div class="metric"><span>Failed today</span><strong><?=number_format($snap['tx_failed'])?></strong><small class="text-muted"><a href="?page=investigate&date_from=<?=$today?>&date_to=<?=$today?>">Investigate →</a></small></div>
        <div class="metric"><span>Integrations</span><strong style="font-size:1.35rem;text-transform:none;white-space:nowrap"><span class="text-success"><?=$snap['int_up']?> up</span><?php if($snap['int_down']):?> <span class="text-danger">· <?=$snap['int_down']?> down</span><?php endif;?></strong><small class="text-muted"><?=$snap['int_stale']?> not recently checked</small></div>
        <div class="metric"><span>Active alerts</span><strong class="<?=$snap['alerts']?'text-danger':'text-success'?>"><?=count($snap['alerts'])?></strong><small class="text-muted"><a href="?page=alerts">Open Alerts →</a></small></div>
    </div>
    <?php $slowLimit=(int)alert_config()['vendor_slow_ms']; $reasonsToday=failure_reasons_day($schema,$today,8); $reasonTotal=array_sum(array_column($reasonsToday,'c'));?>
    <?php foreach([['Channels','channels','channel'],['Vendors','vendors','vendor']] as [$cardTitle,$cardKey,$qParam]):?>
    <div class="cardx mt-3">
        <h3><?=$cardTitle?> today <small class="text-muted">(vs the same time yesterday)</small></h3>
        <?php if(!$snap[$cardKey]):?><p class="text-muted mb-0">No transactions yet today.</p><?php else:?>
        <div class="row g-3 mt-1"><?php foreach($snap[$cardKey] as $c):?>
            <div class="col-md-6 col-xl-3"><div class="border rounded p-3 h-100">
                <div class="d-flex justify-content-between align-items-baseline"><strong class="text-break"><?php if($qParam==='vendor' && $c['name']!=='(none)'):?><a class="text-reset" href="?page=vendor&name=<?=urlencode($c['name'])?>"><?=e($c['name'])?></a><?php else:?><?=e(activity_label($c['name'], $qParam))?><?php endif;?></strong><?php if($c['delta_pct']!==null):?><small class="<?=$c['delta_pct']<=-30?'text-danger fw-semibold':'text-muted'?>"><?=$c['delta_pct']>=0?'▲':'▼'?> <?=abs($c['delta_pct'])?>%</small><?php endif;?></div>
                <div class="fs-3 fw-bold"><?=number_format($c['total'])?></div>
                <div class="progress mb-1" style="height:8px"><div class="progress-bar bg-success" style="width:<?=min(100,$c['success_pct'])?>%"></div></div>
                <small class="text-muted"><?=$c['success_pct']?>% success · <?=number_format($c['failed'])?> failed</small>
                <?php if($c['avg_ms']!==null):?><div class="small <?=$c['avg_ms']>$slowLimit?'text-danger fw-semibold':'text-muted'?>"><i class="fa-regular fa-clock me-1"></i>avg <?=number_format($c['avg_ms'])?> ms<?=$c['slow_pct']>0?' · '.$c['slow_pct'].'% over '.number_format($slowLimit).' ms':''?></div><?php endif;?>
                <?php if($c['name']==='(none)' && $qParam==='channel'):?><div class="small text-muted mt-1">Background calls to vendors (e.g. balance / free-unit queries) — not customer purchases, so left out of the totals above.</div>
                <?php else:?><a class="d-block small mt-1" href="?page=investigate&date_from=<?=$today?>&date_to=<?=$today?><?=$c['name']==='(none)'?'':'&'.$qParam.'='.urlencode($c['name'])?>">Investigate →</a><?php endif;?>
            </div></div>
        <?php endforeach;?></div><?php endif;?>
    </div>
    <?php endforeach;?>
    <div class="cardx mt-3">
        <h3>Why transactions failed today</h3>
        <?php if(!$reasonsToday):?><p class="text-muted mb-0">No failures today.</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>Reason</th><th style="width:38%">Share of failures</th><th class="text-end">Count</th><th></th></tr></thead><tbody>
        <?php foreach($reasonsToday as $rs): $share=$reasonTotal>0?round(100*$rs['c']/$reasonTotal):0;?>
        <tr><td class="cell-full"><?=e($rs['reason'])?></td><td><div class="progress" style="height:8px"><div class="progress-bar bg-danger" style="width:<?=$share?>%"></div></div></td><td class="text-end"><?=number_format((int)$rs['c'])?> <small class="text-muted">(<?=$share?>%)</small></td>
        <td class="text-nowrap"><?php if($rs['reason']!=='(no reason given)'):?><a href="?page=investigate&date_from=<?=$today?>&date_to=<?=$today?>&result_desc=<?=urlencode($rs['reason'])?>">Investigate →</a><?php endif;?></td></tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <div class="cardx mt-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h3 class="mb-0">Integrations</h3>
            <div class="d-flex gap-2"><?php if(can('edit_records') && $activeInts):?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="check_all"><button class="btn btn-sm btn-primary"><i class="fa-solid fa-rotate me-1"></i>Check all now</button></form><?php endif;?><a class="btn btn-sm btn-outline-primary" href="?page=integrations">Manage</a></div></div>
        <p class="text-muted small mt-2 mb-2">Checks run only when you click <b>Check all now</b> (or Test on an integration) — nothing checks them in the background, so results older than <?=INTEGRATION_STALE_MINUTES?> minutes are shown as <i>Was UP / Was DOWN</i>, not as current.</p>
        <?php if(!$ints):?><p class="text-muted mb-0">No integrations registered yet. <a href="?page=integrations">Add one</a> (SMSC, USSD gateway, IVR…).</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>Type</th><th>Name</th><th>Health</th><th>Last check</th><th>Latency</th><th>Result</th></tr></thead><tbody>
        <?php foreach($ints as $i): $h=integration_health($i);?>
        <tr class="<?=$h['state']==='off'?'text-muted':''?>"><td><span class="badge bg-dark"><?=e($i['service_type'])?></span></td><td><?=e($i['name'])?></td>
            <td><span class="badge <?=e($h['class'])?>"><?=e($h['label'])?></span></td>
            <td class="text-nowrap" title="<?=e($i['last_check_at'])?>"><?=e(time_ago($i['last_check_at']))?></td>
            <td><?=$i['last_check_latency_ms']!==null?e($i['last_check_latency_ms']).' ms':'—'?></td>
            <td style="white-space:normal;overflow:visible;text-overflow:clip;max-width:none"><small class="text-muted"><?=e($i['last_check_message'])?></small></td></tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <a class="btn btn-outline-primary btn-sm" href="?page=tables"><i class="fa fa-database me-1"></i>Database Tables</a>
        <a class="btn btn-outline-primary btn-sm" href="?page=ussd_ivr"><i class="fa fa-mobile-screen-button me-1"></i>USSD &amp; IVR<?php foreach($snap['agent_queue_by_status'] as $q):?> <span class="badge bg-light text-dark border ms-1"><?=e($q['status'])?>: <?=e($q['c'])?></span><?php endforeach;?></a>
        <a class="btn btn-outline-primary btn-sm" href="?page=investigate&channel=SMS"><i class="fa fa-headset me-1"></i>Check an SMS/SMSC complaint</a>
    </div>
    <?php layout_end(); exit;
