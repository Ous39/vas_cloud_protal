<?php
declare(strict_types=1);
// Page: ?page=dashboard — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_dashboard'); $schema=current_schema();
    $kpis=dashboard_kpis($schema); $topVendors=top_vendors_today($schema);
    $trend=hourly_transaction_trend($schema, 24);
    $recentActivity=recent_activity(8);
    $health=integrations_health_summary($schema);
    $alerts = can('view_reports') ? compute_alerts($schema) : [];
    if ($alerts) dispatch_pending_alert_notifications($schema);
    layout_start('Executive Dashboard');
    ?>
    <?php if ($alerts): foreach($alerts as $a):?><div class="alert alert-<?=e($a['level'])?> shadow-sm">⚠ <?=e($a['message'])?> <a class="alert-link" href="?page=alerts">View alerts</a></div><?php endforeach; endif;?>
    <div class="metric-grid">
        <div class="metric"><span>Customer Transactions Today</span><strong><?=number_format($kpis['tx_today'])?></strong><?php if(($kpis['lookups_today']??0)>0):?><small class="text-muted">+ <?=number_format($kpis['lookups_today']??0)?> system lookups</small><?php endif;?></div>
        <div class="metric"><span>Success Today</span><strong><?=number_format($kpis['tx_today_success'])?></strong></div>
        <div class="metric"><span>Failed Today</span><strong><?=number_format($kpis['tx_today_failed'])?></strong></div>
        <div class="metric"><span>Active Offers</span><strong><?=number_format($kpis['offers_active'])?></strong></div>
        <div class="metric"><span>Environment</span><strong><?=e(schema_short($schema))?></strong></div>
        <div class="metric"><span>Subscription Rows (est.)</span><strong><?=number_format($kpis['subscription_rows_est'])?></strong></div>
    </div>
    <div class="cardx mt-3">
        <h3>Transaction Trend <small class="text-muted">(last 24 hours, hourly)</small></h3>
        <?php if(!$trend):?><p class="text-muted mb-0">No transactions in the last 24 hours.</p><?php else:?><div style="height:260px"><canvas id="trendChart"></canvas></div><?php endif;?>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-lg-4"><div class="cardx"><h3>Top Vendors Today</h3><?php if(!$topVendors):?><p class="text-muted mb-0">No transactions yet today.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>Vendor</th><th>Total</th><th>Failed</th></tr></thead><tbody><?php foreach($topVendors as $v):?><tr><td><?=e($v['vendor_entity_name'])?></td><td><?=number_format((int)$v['total'])?></td><td><?=number_format((int)$v['failed'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
        <div class="col-lg-4"><div class="cardx"><h3>Recent Activity</h3><?php if(!$recentActivity):?><p class="text-muted mb-0">No activity recorded yet.</p><?php else:?><div class="table-scroll" style="max-height:260px;overflow-y:auto"><table class="table table-sm mb-0"><tbody><?php foreach($recentActivity as $a):?><tr><td><small class="text-muted"><?=e(date('M j H:i',strtotime($a['created_at'])))?></small></td><td><?=e($a['username']??'system')?></td><td><span class="badge bg-secondary"><?=e($a['action'])?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
        <div class="col-lg-4"><div class="cardx"><h3>Quick Links</h3><div class="quick-links"><?php foreach([['investigate','fa-headset','Complaint Investigation'],['subscriptions','fa-user-check','Subscriptions'],['offers','fa-tags','Offer Management'],['offer_report','fa-bullhorn','Offer Performance'],['alerts','fa-triangle-exclamation','Alerts'],['tables','fa-database','Database Tables']] as [$qp,$qi,$ql]): if(!nav_can_see($qp)) continue;?><a href="?page=<?=$qp?>"><span class="ql-icon"><i class="fa-solid <?=$qi?>"></i></span><span class="ql-label"><?=e($ql)?></span><i class="fa-solid fa-chevron-right ql-go"></i></a><?php endforeach;?></div></div></div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-lg-6"><div class="cardx"><h3>System Health</h3><div class="d-flex gap-4 flex-wrap"><div><div class="text-muted small text-uppercase">Integrations Active</div><div class="fs-3 fw-bold text-success"><?=number_format($health['active'])?></div></div><div><div class="text-muted small text-uppercase">Passing Checks</div><div class="fs-3 fw-bold text-success"><?=number_format($health['last_check_ok'])?></div></div><div><div class="text-muted small text-uppercase">Failing Checks</div><div class="fs-3 fw-bold <?=$health['last_check_failed']>0?'text-danger':'text-muted'?>"><?=number_format($health['last_check_failed'])?></div></div><div><div class="text-muted small text-uppercase">Never Checked</div><div class="fs-3 fw-bold text-muted"><?=number_format($health['never_checked'])?></div></div></div><a class="btn btn-sm btn-outline-primary mt-3" href="?page=integrations">View Integrations</a></div></div>
        <div class="col-lg-6"><div class="cardx"><h3>Safety Rules</h3><p class="text-muted mb-1">Delete is disabled everywhere. HeraProduction is read-only in the SQL Console and cannot be full-table-synced. All writes require confirmation and are audited.</p></div></div>
    </div>
    <?php if($trend):?><script nonce="<?=e(csp_nonce())?>">
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?=json_encode(array_map(fn($t)=>substr($t['hr'],11,5), $trend), JSON_HEX_TAG)?>,
            datasets: [
                { label: 'Total', data: <?=json_encode(array_map(fn($t)=>(int)$t['total'], $trend), JSON_HEX_TAG)?>, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.1)', tension: 0.3, fill: true },
                { label: 'Failed', data: <?=json_encode(array_map(fn($t)=>(int)$t['failed'], $trend), JSON_HEX_TAG)?>, borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.1)', tension: 0.3, fill: true }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });
    </script><?php endif;?>
    <?php layout_end(); exit;
