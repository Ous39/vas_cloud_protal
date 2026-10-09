<?php
declare(strict_types=1);
// Page: ?page=alerts — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_reports'); $schema=current_schema();
    $alerts=compute_alerts($schema);
    if ($alerts) dispatch_pending_alert_notifications($schema);
    $cfg=alert_config(); $ignoredReasons=alert_ignored_reasons();
    $hrs=(int)($_GET['hours']??1); if (!in_array($hrs,[1,6,24],true)) $hrs=1;
    $stats=alert_window_stats($schema,1);
    $win=alert_window_stats($schema,$hrs);
    $failureReasons=failure_reasons_breakdown($schema, $hrs, 12);
    $trend=hourly_transaction_trend($schema, 24);
    $alertHistory=recent_alert_history($schema, 15);
    $pct=fn($n,$d)=>$d>0?round(100*$n/$d,1):0;
    $allOff = !$cfg['failure_rate_enabled'] && !$cfg['vendor_silent_enabled'] && !$cfg['vendor_slow_enabled'];
    layout_start('Alerts & Monitoring');
    ?>
    <div class="metric-grid">
        <div class="metric"><span>Customer transactions (last hour)</span><strong><?=number_format($stats['total'])?></strong></div>
        <div class="metric"><span>Failed (last hour)</span><strong><?=number_format($stats['failed'])?></strong><small class="text-muted"><?=$pct($stats['failed'],$stats['total'])?>% of transactions</small></div>
        <div class="metric"><span>Counted toward the alert</span><strong><?=number_format($stats['counted'])?></strong><small class="text-muted"><?=$pct($stats['counted'],$stats['total'])?>%<?=$cfg['failure_rate_enabled']?' — alerts above '.(int)$cfg['failure_rate_pct'].'%':' — failure alert is off'?></small></div>
        <div class="metric"><span>Active alerts</span><strong class="<?=$alerts?'text-danger':'text-success'?>"><?=count($alerts)?></strong></div>
    </div>
    <div class="cardx mt-3">
        <h3><i class="fa-solid fa-triangle-exclamation me-2"></i>Alerts</h3>
        <p class="text-muted">Checked whenever this page or the Dashboard loads, and every few minutes by the scheduled check. Emailed / sent to Slack at most every <?=ALERT_RENOTIFY_MINUTES?> minutes per alert.<?php if(can('manage_api_keys')):?> Choose what triggers them, and who receives them, under <a href="?page=alert_settings">Admin &rarr; Alert Settings</a>.<?php endif;?></p>
        <?php if($allOff):?><div class="alert alert-secondary">Both alert types are switched off in Alert Settings, so nothing is being checked.</div>
        <?php elseif(!$alerts):?><div class="alert alert-success mb-0">No active alerts.</div>
        <?php else: foreach($alerts as $a):?><div class="alert alert-<?=e($a['level'])?> mb-2"><?=e($a['message'])?></div><?php endforeach; endif;?>
    </div>
    <div class="cardx mt-3">
        <h3>Failure Rate by Hour <small class="text-muted">(last 24 hours)</small></h3>
        <?php if(!$trend):?><p class="text-muted mb-0">No transactions in the last 24 hours.</p><?php else:?>
        <p class="text-muted small mb-2">Bars are every failure. The dark line is what counts toward the alert<?=$ignoredReasons?' (failure reasons you chose to ignore are left out)':''?>; the dashed line is your alert limit.</p>
        <div style="height:280px"><canvas id="failureTrendChart"></canvas></div><?php endif;?>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-lg-7"><div class="cardx h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h3 class="mb-0">Why transactions failed</h3>
                <div class="btn-group btn-group-sm"><?php foreach([1=>'Last hour',6=>'6 hours',24=>'24 hours'] as $h=>$lbl):?><a class="btn btn-outline-primary <?=$hrs===$h?'active':''?>" href="?page=alerts&hours=<?=$h?>"><?=$lbl?></a><?php endforeach;?></div></div>
            <p class="text-muted small mt-2 mb-2"><?=number_format($win['failed'])?> failed of <?=number_format($win['total'])?> transactions in this window.</p>
            <?php if(!$failureReasons):?><p class="text-muted mb-0">No failures in this window.</p><?php else:?>
            <div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>Reason</th><th class="text-end">Failures</th><th style="min-width:110px">Share</th><th>For alerts</th></tr></thead><tbody>
            <?php foreach($failureReasons as $fr): $share=$pct((int)$fr['c'],$win['failed']); $isIgn=in_array($fr['reason'],$ignoredReasons,true);?>
            <tr><td style="white-space:normal;overflow:visible;text-overflow:clip;max-width:none"><?=e($fr['reason'])?></td><td class="text-end"><?=number_format((int)$fr['c'])?></td>
                <td><div class="progress" style="height:8px"><div class="progress-bar <?=$isIgn?'bg-secondary':'bg-danger'?>" style="width:<?=min(100,$share)?>%"></div></div><small class="text-muted"><?=$share?>%</small></td>
                <td><?=$isIgn?'<span class="badge bg-secondary">ignored</span>':'<span class="badge bg-danger-subtle text-danger-emphasis">counts</span>'?></td></tr>
            <?php endforeach;?></tbody></table></div>
            <?php endif;?>
        </div></div>
        <div class="col-lg-5"><div class="cardx h-100">
            <h3>Alert History <small class="text-muted">(last 15)</small></h3>
            <?php if(!$alertHistory):?><p class="text-muted mb-0">No alerts have fired yet.</p><?php else:?>
            <div class="table-scroll" style="max-height:380px;overflow-y:auto"><table class="table table-sm mb-0"><tbody><?php foreach($alertHistory as $h):?><tr><td class="text-nowrap" style="width:1%"><small class="text-muted"><?=e(date('M j H:i',strtotime($h['created_at'])))?></small></td><td style="white-space:normal;overflow:visible;text-overflow:clip;max-width:none"><?=e($h['details'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
        </div></div>
    </div>
    <?php if($trend):
        $labels=array_map(fn($t)=>substr($t['hr'],11,5), $trend);
        $allRate=array_map(fn($t)=>$t['total']>0?round($t['failed']/$t['total']*100,1):0, $trend);
        $cntRate=array_map(fn($t)=>$t['total']>0?round($t['counted_failed']/$t['total']*100,1):0, $trend);
        $limit=(int)$cfg['failure_rate_pct'];
        $sugMax=(int)min(100,max(10,ceil(max(array_merge([$limit*1.5],$allRate))*1.15)));
    ?><script nonce="<?=e(csp_nonce())?>">
    (function(){
        const totals=<?=json_encode(array_map(fn($t)=>(int)$t['total'],$trend),JSON_HEX_TAG)?>, fails=<?=json_encode(array_map(fn($t)=>(int)$t['failed'],$trend),JSON_HEX_TAG)?>, counted=<?=json_encode(array_map(fn($t)=>(int)$t['counted_failed'],$trend),JSON_HEX_TAG)?>;
        const labels=<?=json_encode($labels,JSON_HEX_TAG)?>;
        const datasets=[
            { type:'bar', label:'All failures %', data:<?=json_encode($allRate,JSON_HEX_TAG)?>, backgroundColor:'rgba(220,53,69,.35)', order:3 },
            { type:'line', label:'Counted toward alert %', data:<?=json_encode($cntRate,JSON_HEX_TAG)?>, borderColor:'#212529', backgroundColor:'#212529', tension:.3, pointRadius:2, order:1 }
        ];
        <?php if($cfg['failure_rate_enabled']):?>datasets.push({ type:'line', label:'Alert limit (<?=$limit?>%)', data:labels.map(()=><?=$limit?>), borderColor:'#fd7e14', borderDash:[6,4], pointRadius:0, order:2 });<?php endif;?>
        new Chart(document.getElementById('failureTrendChart'), {
            data:{ labels, datasets },
            options:{ responsive:true, maintainAspectRatio:false,
                scales:{ y:{ beginAtZero:true, suggestedMax:<?=$sugMax?>, title:{ display:true, text:'% of transactions' } } },
                plugins:{ tooltip:{ callbacks:{ footer:items=>{ const i=items[0].dataIndex; return fails[i]+' of '+totals[i]+' failed ('+counted[i]+' count toward the alert)'; } } } } }
        });
    })();
    </script><?php endif;?>
    <?php layout_end(); exit;
