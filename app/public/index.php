<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
function asset_version(): string { static $v=null; if($v===null) $v=(string)(@filemtime(__DIR__.'/style.css') ?: time()); return $v; }
try { verify_csrf(); } catch(Throwable $e){ flash('danger',$e->getMessage()); redirect('?'); }
$page=$_GET['page'] ?? 'dashboard';
if ($page==='switch_schema' && isset($_GET['schema'])) { set_current_schema($_GET['schema']); redirect($_SERVER['HTTP_REFERER'] ?? '?'); }
if ($page==='alert_cron') {
    if (!hash_equals(alert_cron_token(), (string)($_GET['token']??''))) { http_response_code(403); header('Content-Type: text/plain'); exit('forbidden'); }
    foreach (['HeraProduction','HeraTesting'] as $s) dispatch_pending_alert_notifications($s);
    header('Content-Type: text/plain'); exit('OK');
}
if ($page==='logout') { logout(); redirect('?page=login'); }
if ($page==='login') {
    if ($_SERVER['REQUEST_METHOD']==='POST') { if(login_attempt($_POST['username']??'', $_POST['password']??'')) redirect('?page=dashboard'); flash('danger','Invalid username or password.'); }
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login - VAS Cloud</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet"><link href="style.css?v=<?=e(asset_version())?>" rel="stylesheet"></head><body class="login-page"><main><form method="post" class="login-card"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="login-card-header"><img src="comium_logo.png" alt="Comium"><h2>VAS Cloud</h2><p class="mb-0">Sign in to manage VAS operations</p></div><div class="login-card-body"><?php foreach(flashes() as $f):?><div class="alert alert-<?=e($f['type'])?>"><?=e($f['msg'])?></div><?php endforeach;?><label class="form-label">Username</label><input class="form-control mb-3" name="username" autofocus autocomplete="username"><label class="form-label">Password</label><input class="form-control mb-3" name="password" type="password" autocomplete="current-password"><button class="btn btn-custom w-100">Sign in</button></div></form></main></body></html><?php exit;
}
require_login();

function nav_can_see(string $page): bool {
    if ($page==='sql') return can('run_sql');
    if ($page==='users') return can('manage_users');
    if ($page==='audit') return can('view_audit');
    if ($page==='api_keys') return can('manage_api_keys');
    if ($page==='promotions') return can('manage_promotions');
    if ($page==='alert_settings') return can('manage_api_keys');
    if (in_array($page,['investigate','reports','alerts','monitoring','offer_report'],true)) return can('view_reports');
    if (in_array($page,['subscriptions','offers','esim','sales','friends_family','voting','ussd_ivr','ussd_menu','integrations','tables','projects','shortcodes'],true)) return can('view_tables');
    return true;
}
function layout_start(string $title): void {
    $u=user(); $schema=current_schema(); $current=$_GET['page']??'dashboard';
    $nav = [
        ['dashboard','fa-gauge','Dashboard'],
        ['monitoring','fa-heart-pulse','Monitoring'],
        ['group','fa-layer-group','Operations',[['subscriptions','fa-user-check','Subscriptions'],['offers','fa-tags','Offer Management'],['esim','fa-sim-card','eSIM Profiles'],['sales','fa-file-invoice-dollar','Sales & Invoices'],['friends_family','fa-user-group','Friends & Family'],['voting','fa-square-poll-vertical','Voting Service']]],
        ['group','fa-tower-broadcast','Infrastructure',[['ussd_ivr','fa-mobile-screen-button','USSD & IVR'],['ussd_menu','fa-sitemap','USSD Menu Builder'],['integrations','fa-plug-circle-check','Integrations']]],
        ['group','fa-chart-line','Reports',[['investigate','fa-headset','Complaint Investigation'],['alerts','fa-triangle-exclamation','Alerts'],['offer_report','fa-bullhorn','Offer Performance'],['reports','fa-chart-line','Reports'],['sql','fa-code','SQL Console']]],
        ['group','fa-gears','Admin',[['tables','fa-database','Database Tables'],['promotions','fa-bullhorn','Promotions'],['projects','fa-diagram-project','Projects'],['shortcodes','fa-hashtag','Short Codes'],['api_keys','fa-key','Partner API Keys'],['alert_settings','fa-bell','Alert Settings'],['audit','fa-shield-halved','Audit Trail'],['users','fa-users-gear','Users']]],
    ];
    ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> - VAS Cloud</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous"><link href="style.css?v=<?=e(asset_version())?>" rel="stylesheet"><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt" crossorigin="anonymous"></script></head><body>
<nav class="topnav"><div class="topnav-inner">
    <a class="topnav-brand" href="?page=dashboard"><img src="comium_logo.png" alt="Comium">VAS Cloud</a>
    <button type="button" id="topnav-toggle" class="topnav-toggle" aria-label="Menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
    <div class="topnav-collapse" id="topnav-collapse">
    <div class="topnav-links">
    <?php foreach($nav as $item):
        if ($item[0]==='group') {
            [, $icon, $label, $children] = $item;
            $children = array_values(array_filter($children, fn($c)=>nav_can_see($c[0])));
            if (!$children) continue;
            $groupActive = in_array($current, array_column($children,0), true);
    ?>
        <details class="topnav-group<?=$groupActive?' has-active':''?>"><summary><i class="fa-solid <?=$icon?>"></i><?=e($label)?> <i class="fa-solid fa-chevron-down" style="font-size:.7rem;"></i></summary>
            <div class="topnav-menu"><?php foreach($children as $c):?><a class="<?=$current===$c[0]?'active':''?>" href="?page=<?=$c[0]?>"><i class="fa-solid <?=$c[1]?>"></i><?=e($c[2])?></a><?php endforeach;?></div>
        </details>
    <?php } else {
            if (!nav_can_see($item[0])) continue;
    ?>
        <a class="<?=$current===$item[0]?'active':''?>" href="?page=<?=$item[0]?>"><i class="fa-solid <?=$item[1]?>"></i><?=e($item[2])?></a>
    <?php } endforeach; ?>
    </div>
    <div class="topnav-side">
        <details class="env-menu env-<?=schema_kind($schema)?>"><summary title="Database in use"><i class="fa-solid fa-database"></i><?=e(schema_label($schema))?> <i class="fa-solid fa-chevron-down"></i></summary><div class="env-list"><?php foreach(allowed_schemas() as $s):?><a class="env-<?=schema_kind($s)?><?=$s===$schema?' on':''?>" href="?page=switch_schema&schema=<?=urlencode($s)?>"><span class="dot"></span><strong><?=e(schema_label($s))?></strong><small><?=e($s)?></small></a><?php endforeach;?></div></details>
        <div class="user-chip"><strong><?=e($u['full_name'])?></strong><small><?=e($u['role'])?> • <?=e($u['username'])?></small><a href="?page=account" class="btn btn-sm btn-outline-light" title="Change my password"><i class="fa-solid fa-key"></i></a> <a href="?page=logout" class="btn btn-sm btn-light">Logout</a></div>
    </div>
    </div>
</div></nav>
<main><div class="topbar"><div><h1><?=e($title)?></h1><p><?=e($schema)?> • Request <?=e(request_id())?></p></div><span class="pill <?=schema_kind($schema)?>"><?=e($schema)?></span></div><?php foreach(flashes() as $f):?><div class="alert alert-<?=e($f['type'])?> shadow-sm"><?=e($f['msg'])?></div><?php endforeach; ?>
<?php }
function layout_end(): void { ?></main><footer>&copy; <?=date('Y')?> Comium VAS Cloud. All rights reserved.</footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script><script nonce="<?=e(csp_nonce())?>">
function addFilter(){const box=document.getElementById('filters'); const tpl=document.getElementById('filter-template').innerHTML; box.insertAdjacentHTML('beforeend',tpl);}
function confirmAction(msg){return confirm(msg||'Please confirm before saving this operation.');}
// CSP blocks inline onclick/onsubmit attributes even with a script nonce (nonces only cover <script>
// tags), so every interactive hook is wired here instead of inline in the markup.
document.getElementById('add-filter-btn')?.addEventListener('click', addFilter);
document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => { if (!confirmAction(f.dataset.confirm)) e.preventDefault(); }));
document.querySelectorAll('[data-autosubmit]').forEach(el => el.addEventListener('change', () => el.form.submit()));
document.querySelectorAll('[data-filter-select]').forEach(input => {
    const sel = document.getElementById(input.dataset.filterSelect);
    if (!sel) return;
    input.addEventListener('input', () => {
        const q = input.value.toLowerCase();
        Array.from(sel.options).forEach(opt => { opt.hidden = q !== '' && !opt.textContent.toLowerCase().includes(q); });
    });
});
// Close an open nav dropdown when clicking outside it.
document.addEventListener('click', e => { document.querySelectorAll('.topnav-group[open]').forEach(d => { if (!d.contains(e.target)) d.removeAttribute('open'); }); });
// Mobile nav toggle.
const topnavToggle = document.getElementById('topnav-toggle'), topnavCollapse = document.getElementById('topnav-collapse');
topnavToggle?.addEventListener('click', () => { const open = topnavCollapse.classList.toggle('open'); topnavToggle.setAttribute('aria-expanded', open ? 'true' : 'false'); });
</script></body></html><?php }
function render_form(string $schema,string $table,array $values=[],string $mode='add',array $keys=[]): void { $cols=editable_columns($schema,$table,$mode==='duplicate'); ?><div class="form-grid"><?php foreach($cols as $c): $name=$c['name']; if($mode==='duplicate' && is_auto_col($c)) continue; ?><div class="field"><label><?=e($name)?> <small><?=e($c['type'])?></small></label><?php $val=$values[$name]??''; if(str_contains(strtolower($c['type']),'text') || str_contains(strtolower($c['type']),'blob')): ?><textarea name="data[<?=e($name)?>]" class="form-control" rows="3"><?=e($val)?></textarea><?php else: ?><input name="data[<?=e($name)?>]" class="form-control" value="<?=e($val)?>"><?php endif;?></div><?php endforeach;?></div><?php foreach($keys as $k=>$v):?><input type="hidden" name="keys[<?=e($k)?>]" value="<?=e($v)?>"><?php endforeach; }

try {
if ($page==='dashboard') {
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
        <div class="metric"><span>Transactions Today</span><strong><?=number_format($kpis['tx_today'])?></strong></div>
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
}

if ($page==='tables') { require_perm('view_tables'); layout_start('Database Tables'); $schema=current_schema(); ?><div class="cardx"><div class="d-flex justify-content-between align-items-center"><h3>Tables in <?=e($schema)?></h3><a class="btn btn-outline-primary" href="?page=reports">View Reports</a></div><div class="module-grid mt-3"><?php foreach(table_names($schema) as $t):?><a class="module-card" href="?page=table&table=<?=urlencode($t)?>"><i class="fa-solid fa-table"></i><strong><?=e($t)?></strong><span><?=number_format(approx_table_count($schema,$t))?> records (est.)</span></a><?php endforeach;?></div></div><?php layout_end(); exit; }

if ($page==='table') { require_perm('view_tables'); $schema=current_schema(); $table=$_GET['table']??''; if(!table_exists($schema,$table)) throw new RuntimeException('Table not found'); $pageNo=max(1,(int)($_GET['p']??1)); $perPage=resolve_page_size($_GET); $cols=columns($schema,$table); $filters=parse_table_filters($_GET); $guard=large_table_guard($schema,$table,$filters); if($guard && $guard['level']==='block'){ $data=['rows'=>[],'total'=>0]; } else { $data=list_records($schema,$table,$filters,$pageNo,$perPage); $data['rows']=array_map('redact_row',$data['rows']); } $exportQs=$_GET; $exportQs['page']='export'; layout_start('Table: '.$table); ?><?php if($guard):?><div class="alert alert-<?=$guard['level']==='block'?'warning':'info'?>"><?=e($guard['message'])?></div><?php endif;?><div class="cardx"><div class="d-flex flex-wrap gap-2 justify-content-between"><div><h3><?=e($table)?></h3><p class="text-muted mb-0"><?=number_format($data['total'])?> matching records in <?=e($schema)?></p></div><div class="d-flex gap-2"><a class="btn btn-success" href="?page=form&mode=add&table=<?=urlencode($table)?>"><i class="fa fa-plus"></i> Add</a><a class="btn btn-outline-primary" href="?<?=http_build_query($exportQs)?>">Export CSV<?=$filters?' (filtered)':''?></a><a class="btn btn-outline-dark" href="?page=sync&table=<?=urlencode($table)?>">Copy/Sync</a></div></div><form class="search-panel mt-3" method="get"><input type="hidden" name="page" value="table"><input type="hidden" name="table" value="<?=e($table)?>"><div id="filters"><?php $show=$filters ?: [['col'=>'','op'=>'contains','val'=>'']]; foreach($show as $f):?><div class="filter-row"><select name="fcol[]" class="form-select"><option value="">Select column</option><?php foreach($cols as $c):?><option value="<?=e($c['name'])?>" <?=$f['col']===$c['name']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select><select name="fop[]" class="form-select"><option value="contains" <?=$f['op']==='contains'?'selected':''?>>contains</option><option value="equals" <?=$f['op']==='equals'?'selected':''?>>equals</option><option value="starts" <?=$f['op']==='starts'?'selected':''?>>starts with</option><option value="ends" <?=$f['op']==='ends'?'selected':''?>>ends with</option><option value="gt" <?=$f['op']==='gt'?'selected':''?>>&gt;</option><option value="lt" <?=$f['op']==='lt'?'selected':''?>>&lt;</option></select><input name="fval[]" class="form-control" value="<?=e($f['val'])?>" placeholder="Search value"></div><?php endforeach;?></div><template id="filter-template"><div class="filter-row"><select name="fcol[]" class="form-select"><option value="">Select column</option><?php foreach($cols as $c):?><option value="<?=e($c['name'])?>"><?=e($c['name'])?></option><?php endforeach;?></select><select name="fop[]" class="form-select"><option value="contains">contains</option><option value="equals">equals</option><option value="starts">starts with</option><option value="ends">ends with</option><option value="gt">&gt;</option><option value="lt">&lt;</option></select><input name="fval[]" class="form-control" placeholder="Search value"></div></template><div class="d-flex gap-2 mt-2 align-items-center"><button class="btn btn-primary">Search</button><button type="button" id="add-filter-btn" class="btn btn-outline-primary">Add Filter</button><a href="?page=table&table=<?=urlencode($table)?>" class="btn btn-outline-secondary">Reset</a><label class="ms-auto mb-0 small text-muted">Per page</label><select class="form-select form-select-sm w-auto" name="per_page" data-autosubmit><?php foreach(PAGE_SIZE_OPTIONS as $ps):?><option value="<?=$ps?>" <?=$perPage===$ps?'selected':''?>><?=$ps?></option><?php endforeach;?></select></div></form></div><div class="cardx table-card mt-3"><div class="table-scroll"><table class="table table-hover table-sm align-middle"><thead><tr><?php foreach($cols as $c):?><th><?=e($c['name'])?></th><?php endforeach;?><th class="sticky-actions">Actions</th></tr></thead><tbody><?php foreach($data['rows'] as $r): $key=row_key_query($schema,$table,$r);?><tr><?php foreach($cols as $c): $v=$r[$c['name']]??'';?><td title="<?=e($v)?>"><?=e(mb_strimwidth((string)$v,0,80,'...'))?></td><?php endforeach;?><td class="sticky-actions"><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary" href="?page=view&table=<?=urlencode($table)?>&<?=$key?>">View</a><?php if(can('edit_records')):?><a class="btn btn-outline-warning" href="?page=form&mode=edit&table=<?=urlencode($table)?>&<?=$key?>">Edit</a><?php endif;?><?php if(can('duplicate_records')):?><a class="btn btn-outline-success" href="?page=form&mode=duplicate&table=<?=urlencode($table)?>&<?=$key?>">Duplicate</a><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div><?php $pages=max(1,ceil($data['total']/$perPage));?><div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=e(http_build_query(array_merge($_GET,['p'=>$pageNo-1])))?>">Prev</a><?php endif;?><?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div></div><?php layout_end(); exit; }

if ($page==='view') { require_perm('view_tables'); $schema=current_schema(); $table=$_GET['table']??''; $r=fetch_record($schema,$table,$_GET); if(!$r) throw new RuntimeException('Record not found'); $display=redact_row($r); layout_start('Record Details'); ?><div class="cardx"><div class="d-flex justify-content-between"><h3><?=e($table)?> Record</h3><div class="d-flex gap-2"><a class="btn btn-warning" href="?page=form&mode=edit&table=<?=urlencode($table)?>&<?=row_key_query($schema,$table,$r)?>">Edit</a><a class="btn btn-success" href="?page=form&mode=duplicate&table=<?=urlencode($table)?>&<?=row_key_query($schema,$table,$r)?>">Duplicate</a><a class="btn btn-outline-dark" href="?page=copy_record&table=<?=urlencode($table)?>&<?=row_key_query($schema,$table,$r)?>">Copy to <?=e(opposite_schema($schema))?></a></div></div><div class="detail-grid mt-3"><?php foreach($display as $k=>$v):?><div><label><?=e($k)?></label><pre><?=e($v)?></pre></div><?php endforeach;?></div><div class="alert alert-secondary mt-3">Delete is disabled by system policy. Use status fields such as disabled/retired/deleted_at where available.</div></div><?php layout_end(); exit; }

if ($page==='form') { $schema=current_schema(); $table=$_GET['table']??''; $mode=$_GET['mode']??'add'; if(!table_exists($schema,$table)) throw new RuntimeException('Table not found'); require_perm($mode==='edit'?'edit_records':($mode==='duplicate'?'duplicate_records':'create_records')); $values=[];$keys=[]; if($mode!=='add'){ $values=fetch_record($schema,$table,$_GET) ?: throw new RuntimeException('Record not found'); foreach(primary_columns($schema,$table) as $pk) $keys[$pk]=$values[$pk]; } if($_SERVER['REQUEST_METHOD']==='POST'){ $action=$mode==='edit'?'update':($mode==='duplicate'?'duplicate':'insert'); $token=make_confirmation($action,['schema'=>$schema,'table'=>$table,'mode'=>$mode,'keys'=>$_POST['keys']??[],'data'=>$_POST['data']??[]]); redirect('?page=confirm&token='.$token); } layout_start(ucfirst($mode).' Record'); ?><div class="cardx"><h3><?=ucfirst(e($mode))?> in <?=e($schema.'.'.$table)?></h3><p class="text-muted">You will preview and confirm before saving.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><?php render_form($schema,$table,$values,$mode,$keys);?><div class="mt-3"><button class="btn btn-primary">Preview & Confirm</button><a class="btn btn-outline-secondary" href="?page=table&table=<?=urlencode($table)?>">Cancel</a></div></form></div><?php layout_end(); exit; }

if ($page==='copy_record') { $from=current_schema(); $to=opposite_schema($from); $table=$_GET['table']??''; require_perm('copy_records'); $r=fetch_record($from,$table,$_GET) ?: throw new RuntimeException('Record not found'); if($_SERVER['REQUEST_METHOD']==='POST'){ assert_copy_schemas($from,(string)($_POST['to_schema']??$to),false); $token=make_confirmation('copy_record',['from'=>$from,'to'=>$_POST['to_schema']??$to,'table'=>$table,'keys'=>array_intersect_key($_GET,array_flip(primary_columns($from,$table))),'data'=>$_POST['data']??[]]); redirect('?page=confirm&token='.$token); } layout_start('Copy Record'); ?><div class="cardx"><h3>Copy record</h3><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Destination</label><select class="form-select w-auto" name="to_schema"><option value="<?=e($to)?>"><?=e($to)?></option><option value="<?=e($from)?>"><?=e($from)?></option></select><p class="text-muted mt-2">Adjust values before copying. Save uses REPLACE to update existing primary keys.</p><?php render_form($from,$table,$r,'duplicate',[]);?><button class="btn btn-primary mt-3">Preview & Confirm Copy</button></form></div><?php layout_end(); exit; }

if ($page==='sync') { $schema=current_schema(); $table=$_GET['table']??''; require_perm('copy_records'); if($_SERVER['REQUEST_METHOD']==='POST'){ $mode=$_POST['mode']??'merge'; $to=$_POST['to_schema']??''; assert_copy_schemas((string)($_POST['from_schema']??''),(string)$to,true); if(!table_exists((string)$_POST['from_schema'],$table)||!table_exists($to,$table)) throw new RuntimeException('Table must exist in both schemas.'); if($mode==='sync' && is_protected_schema($to)) throw new RuntimeException('Full-table sync cannot target '.$to.'. Choose Merge instead.'); $action=$mode==='sync'?'sync_table':'merge_table'; $token=make_confirmation($action,['from'=>$_POST['from_schema'],'to'=>$to,'table'=>$table]); redirect('?page=confirm&token='.$token); } layout_start('Copy / Sync Table'); ?><div class="cardx"><h3>Copy full table data</h3><div class="alert alert-info"><b>Merge</b> upserts by primary key and never deletes existing rows — safe for HeraProduction. <b>Full sync</b> truncates the destination table first and can only target HeraTesting.</div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="row g-3"><div class="col-md-3"><label>Mode</label><select name="mode" class="form-select"><option value="merge">Merge (safe, upsert)</option><option value="sync">Full sync (truncate + reload, HeraTesting only)</option></select></div><div class="col-md-3"><label>From</label><select name="from_schema" class="form-select"><?php foreach(allowed_schemas() as $s):?><option><?=e($s)?></option><?php endforeach;?></select></div><div class="col-md-3"><label>To</label><select name="to_schema" class="form-select"><?php foreach(array_reverse(allowed_schemas()) as $s):?><option><?=e($s)?></option><?php endforeach;?></select></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-danger w-100">Preview & Confirm</button></div></div></form></div><?php layout_end(); exit; }

if ($page==='confirm') { $token=$_GET['token']??''; $c=get_confirmation($token) ?: throw new RuntimeException('Confirmation expired or already used.'); if(($c['username']??null)!==(user()['username']??null)) throw new RuntimeException('This confirmation was not created by your session.'); $payload=json_decode($c['payload'],true); if($_SERVER['REQUEST_METHOD']==='POST'){ if(($_POST['decision']??'')==='cancel'){ mark_confirmation($token,'cancelled'); flash('info','Operation cancelled.'); redirect('?'); } $a=$c['action']; if($a==='insert') insert_record($payload['schema'],$payload['table'],$payload['data']); elseif($a==='update') update_record($payload['schema'],$payload['table'],$payload['keys'],$payload['data']); elseif($a==='duplicate') insert_record($payload['schema'],$payload['table'],$payload['data']); elseif($a==='copy_record') copy_record($payload['from'],$payload['to'],$payload['table'],$payload['keys'],$payload['data']); elseif($a==='sync_table') sync_table($payload['from'],$payload['to'],$payload['table']); elseif($a==='merge_table') merge_table($payload['from'],$payload['to'],$payload['table']); elseif($a==='sql') run_sql($payload['schema'],$payload['sql']); elseif($a==='save_project') save_project($payload['data'],$payload['channels']??[],!empty($payload['id'])?(int)$payload['id']:null); elseif($a==='save_shortcode') save_shortcode($payload['data'],!empty($payload['id'])?(int)$payload['id']:null); else throw new RuntimeException('Unknown action'); mark_confirmation($token,'confirmed'); flash('success','Operation completed successfully.'); redirect($payload['return_to'] ?? '?page=dashboard'); } layout_start('Confirm Operation'); ?><div class="cardx confirm-box"><h3>Confirm <?=e($c['action'])?></h3><p>This action will change data. Please review before saving.</p><pre><?=e(json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button name="decision" value="confirm" class="btn btn-danger">Yes, Confirm & Save</button><button name="decision" value="cancel" class="btn btn-outline-secondary">Cancel</button></form></div><?php layout_end(); exit; }

if ($page==='export') { $schema=current_schema(); $table=$_GET['table']??''; require_perm('view_tables'); if(!table_exists($schema,$table)) throw new RuntimeException('Table not found'); $filters=parse_table_filters($_GET); $guard=large_table_guard($schema,$table,$filters); if($guard && $guard['level']==='block') throw new RuntimeException($guard['message']); audit('export',$schema,$table,null,'CSV export'.($filters?' (filtered)':'')); $rows=export_records($schema,$table,$filters,10000); header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="'.$schema.'_'.$table.'_export.csv"'); $out=fopen('php://output','w'); $first=true; foreach($rows as $row){ $row=redact_row($row); if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,$row);} exit; }

if ($page==='sql') { require_perm('run_sql'); $schema=current_schema(); $result=null; if($_SERVER['REQUEST_METHOD']==='POST'){ $sql=trim($_POST['sql']??''); $kind=safe_sql_kind($sql); if(in_array($kind,SQL_READONLY_KINDS,true)){ $isCsv=(($_POST['format']??''))==='csv'; $result=run_sql($schema,$sql,$isCsv?SQL_CONSOLE_CSV_MAX_ROWS:SQL_CONSOLE_MAX_ROWS); if($isCsv){ audit('sql_csv_export',$schema,null,null,$sql); header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="query_export.csv"'); $out=fopen('php://output','w'); $first=true; foreach($result['rows'] as $row){ if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,$row); } if($first) fputcsv($out,['(no rows returned)']); exit; } } else { if(is_protected_schema($schema)) throw new RuntimeException($schema.' is read-only in the SQL Console. Use the record forms (Add/Edit/Copy) for production writes.'); $token=make_confirmation('sql',['schema'=>$schema,'sql'=>$sql]); redirect('?page=confirm&token='.$token); } if(!empty($_POST['save_name'])) portal_pdo()->prepare('INSERT INTO saved_queries(name,schema_name,sql_text,created_by) VALUES(?,?,?,?)')->execute([$_POST['save_name'],$schema,$sql,user()['username']]); } layout_start('SQL Console'); ?><div class="cardx"><h3>Safe SQL Console</h3><p class="text-muted">DELETE, DROP and TRUNCATE are blocked. Write queries require confirmation. A leading <code>WITH ... AS (...)</code> common table expression is allowed — it's classified by the real statement that follows it.<?php if(is_protected_schema($schema)):?> <strong><?=e($schema)?> is read-only here</strong> — SELECT/SHOW/DESCRIBE/EXPLAIN only; use the record forms for production writes.<?php endif;?></p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><textarea name="sql" class="form-control code" rows="8" placeholder="SELECT * FROM subscription LIMIT 20"><?=e($_POST['sql']??'')?></textarea><div class="row g-2 mt-2"><div class="col-md-6"><input class="form-control" name="save_name" placeholder="Optional name to save this query"></div><div class="col-md-3"><button class="btn btn-primary w-100" name="format" value="preview">Run / Preview</button></div><div class="col-md-3"><button class="btn btn-outline-primary w-100" name="format" value="csv">Download CSV</button></div></div></form></div><?php if($result):?><div class="cardx mt-3"><h3>Result</h3><?php if($result['affected']!==null):?><p>Affected rows: <?=e($result['affected'])?></p><?php else:?><?php if(!empty($result['truncated'])):?><div class="alert alert-warning py-2">Showing the first <?=number_format($result['limit'])?> rows only — add a <code>LIMIT</code> or narrower <code>WHERE</code>, or use Download CSV (up to <?=number_format(SQL_CONSOLE_CSV_MAX_ROWS)?> rows). Queries are stopped after <?=SQL_CONSOLE_TIMEOUT_MS/1000?>s.</div><?php endif;?><div class="table-scroll"><table class="table table-sm"><thead><tr><?php foreach(array_keys($result['rows'][0]??[]) as $h):?><th><?=e($h)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($result['rows'] as $row):?><tr><?php foreach($row as $v):?><td><?=e(mb_strimwidth((string)$v,0,90,'...'))?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php endif;?></div><?php endif; layout_end(); exit; }

if ($page==='shortcodes') { require_perm('manage_shortcodes'); $db=portal_pdo(); $edit=null; if(isset($_GET['id'])){ $st=$db->prepare('SELECT * FROM portal_short_codes WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit=$st->fetch(); } if($_SERVER['REQUEST_METHOD']==='POST'){ $token=make_confirmation('save_shortcode',['id'=>$_POST['id']??null,'data'=>$_POST['data']??[],'return_to'=>'?page=shortcodes']); redirect('?page=confirm&token='.$token); } layout_start('Channel / Short Code Management'); $rows=$db->query('SELECT * FROM portal_short_codes ORDER BY short_code, channel_type, service_name')->fetchAll(); ?><div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit Channel':'Add Channel' ?></h3><p class="text-muted">Manage USSD and IVR channels. Saving requires confirmation.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><label>Channel Type</label><select class="form-select mb-2" name="data[channel_type]"><?php foreach(['USSD','IVR'] as $v):?><option value="<?=e($v)?>" <?=($edit['channel_type']??'USSD')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select><label>Short Code</label><input class="form-control mb-2" name="data[short_code]" value="<?=e($edit['short_code']??'')?>" placeholder="*123# or 141"><label>Service Name</label><input class="form-control mb-2" name="data[service_name]" value="<?=e($edit['service_name']??'')?>" placeholder="VAS Main Menu"><label>Provider</label><input class="form-control mb-2" name="data[provider]" value="<?=e($edit['provider']??'')?>" placeholder="Comium"><label>Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['Active','Inactive','Pending','Suspended'] as $v):?><option value="<?=e($v)?>" <?=($edit['status']??'Pending')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select><label>Description</label><textarea class="form-control mb-2" name="data[description]" rows="3"><?=e($edit['description']??'')?></textarea><button class="btn btn-primary w-100">Preview & Confirm Save</button><?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=shortcodes">Cancel Edit</a><?php endif;?></form></div></div><div class="col-lg-8"><div class="cardx"><div class="d-flex justify-content-between align-items-center"><h3>Channel Register</h3><span class="badge bg-primary"><?=count($rows)?> channels</span></div><div class="table-scroll"><table class="table table-hover"><thead><tr><th>Type</th><th>Short Code</th><th>Service Name</th><th>Provider</th><th>Status</th><th>Linked Projects</th><th></th></tr></thead><tbody><?php foreach($rows as $r): $st=$db->prepare('SELECT COUNT(*) c FROM portal_project_channels WHERE channel_id=?'); $st->execute([$r['id']]); $linked=(int)$st->fetch()['c']; ?><tr><td><span class="badge bg-dark"><?=e($r['channel_type'])?></span></td><td><strong><?=e($r['short_code'])?></strong></td><td><?=e($r['service_name'])?></td><td><?=e($r['provider'])?></td><td><span class="badge status-<?=e(strtolower($r['status']))?>"><?=e($r['status'])?></span></td><td><?=e($linked)?></td><td class="sticky-actions"><a class="btn btn-sm btn-warning" href="?page=shortcodes&id=<?=e($r['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div></div></div><?php layout_end(); exit; }

if ($page==='offers') {
    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'vas_offers')) throw new RuntimeException('vas_offers does not exist in '.$schema);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);
        if (($_POST['action']??'')==='toggle') {
            require_perm('edit_records');
            $row=fetch_record($schema,'vas_offers',['id'=>$id]) ?: throw new RuntimeException('Offer not found');
            $newStatus = offer_is_active($row['status']??'') ? '0' : '1';
            $token=make_confirmation('update',['schema'=>$schema,'table'=>'vas_offers','keys'=>['id'=>$id],'data'=>['status'=>$newStatus]]);
        } else {
            require_perm($id ? 'edit_records' : 'create_records');
            $data=$_POST['data']??[];
            if (!$id) {
                $code=trim((string)($data['offer_code']??''));
                if ($code==='') throw new RuntimeException('Offer Code is required for a new offer.');
                $st=pdo($schema)->prepare('SELECT COUNT(*) FROM vas_offers WHERE offer_code=?'); $st->execute([$code]);
                if ((int)$st->fetchColumn()>0) throw new RuntimeException('An offer with code '.$code.' already exists — a duplicate needs its own new Offer Code.');
            }
            if (isset($data['free_data'])) $data['free_data']=normalize_free_data_to_mb((string)$data['free_data']);
            $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'vas_offers','keys'=>['id'=>$id],'data'=>$data]);
        }
        redirect('?page=confirm&token='.$token);
    }
    $edit=null; $dupOf=null;
    if(isset($_GET['id'])){ $edit=fetch_record($schema,'vas_offers',['id'=>(int)$_GET['id']]); }
    elseif(isset($_GET['duplicate'])){
        $dupOf=fetch_record($schema,'vas_offers',['id'=>(int)$_GET['duplicate']]);
        if($dupOf){ $edit=$dupOf; unset($edit['id']); foreach(['offer_code','offer_code_for_other','pcrf_offer_code'] as $k) $edit[$k]=''; $edit['name']=trim((string)$dupOf['name']).' (copy)'; $edit['status']='0'; }
    }
    $filters=[];
    foreach(['name'=>'name','offer_code'=>'offer_code','vendor'=>'vendor','category'=>'category'] as $qp=>$col) if(trim((string)($_GET[$qp]??''))!=='') $filters[]=['col'=>$col,'op'=>'contains','val'=>trim((string)$_GET[$qp])];
    if (trim((string)($_GET['status']??''))!=='') $filters[]=['col'=>'status','op'=>'equals','val'=>$_GET['status']];
    $pageNo=max(1,(int)($_GET['p']??1));
    $perPage=resolve_page_size($_GET);
    $data=list_records($schema,'vas_offers',$filters,$pageNo,$perPage);
    $vendors=distinct_column_values($schema,'vas_offers','vendor');
    $categories=distinct_column_values($schema,'vas_offers','category');
    $subCategories=distinct_column_values($schema,'vas_offers','sub_category');
    $validities=distinct_column_values($schema,'vas_offers','validity_amount');
    $speedLimits=distinct_column_values($schema,'vas_offers','speed_limit');
    layout_start('Offer Management');
    ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="cardx">
                <h3><?= $dupOf?'Duplicate Offer':($edit?'Edit Offer':'Add Offer') ?></h3>
                <?php if($dupOf):?><div class="alert alert-info py-2 small">Copied from <b><?=e($dupOf['name'])?></b> (<?=e($dupOf['offer_code'])?>). Change what you need — the offer codes were left empty because each offer needs its own — then save. It starts as <b>Inactive</b>.</div><?php else:?><p class="text-muted">Saving requires confirmation. Toggling status also requires confirmation.</p><?php endif;?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                    <label>Vendor</label><input class="form-control mb-2" name="data[vendor]" list="dl_vendor" value="<?=e($edit['vendor']??'')?>"><datalist id="dl_vendor"><?php foreach($vendors as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist>
                    <label>Offer Code</label><input class="form-control mb-2" name="data[offer_code]" value="<?=e($edit['offer_code']??'')?>">
                    <label>Other Offer Code (Buy For Other Offer Code)</label><input class="form-control mb-2" name="data[offer_code_for_other]" value="<?=e($edit['offer_code_for_other']??'')?>">
                    <label>PCRF Offer Code</label><input class="form-control mb-2" name="data[pcrf_offer_code]" value="<?=e($edit['pcrf_offer_code']??'')?>">
                    <div class="row g-2">
                        <div class="col-6"><label>Category</label><input class="form-control mb-2" name="data[category]" list="dl_category" value="<?=e($edit['category']??'')?>"><datalist id="dl_category"><?php foreach($categories as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist></div>
                        <div class="col-6"><label>Sub-category</label><input class="form-control mb-2" name="data[sub_category]" list="dl_sub_category" value="<?=e($edit['sub_category']??'')?>"><datalist id="dl_sub_category"><?php foreach($subCategories as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist></div>
                    </div>
                    <label>Name</label><input class="form-control mb-2" name="data[name]" value="<?=e($edit['name']??'')?>">
                    <label>Description</label><textarea class="form-control mb-2" name="data[description]" rows="2"><?=e($edit['description']??'')?></textarea>
                    <div class="row g-2">
                        <div class="col-4"><label>Price</label><input class="form-control mb-2" name="data[one_time_price]" value="<?=e($edit['one_time_price']??'')?>"></div>
                        <div class="col-4"><label>Rental</label><input class="form-control mb-2" name="data[rental_price]" value="<?=e($edit['rental_price']??'')?>"></div>
                        <div class="col-4"><label>Discount</label><input class="form-control mb-2" name="data[discount]" value="<?=e($edit['discount']??'')?>"></div>
                    </div>
                    <div class="row g-2">
                        <div class="col-4"><label>Free Data (MB)</label><input class="form-control mb-2" name="data[free_data]" placeholder="e.g. 500 or 10GB" value="<?=e($edit['free_data']??'')?>"></div>
                        <div class="col-4"><label>Validity (days)</label><input class="form-control mb-2" name="data[validity_amount]" list="dl_validity" value="<?=e($edit['validity_amount']??'')?>"><datalist id="dl_validity"><?php foreach($validities as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist></div>
                        <div class="col-4"><label>Speed limit</label><input class="form-control mb-2" name="data[speed_limit]" list="dl_speed_limit" value="<?=e($edit['speed_limit']??'NA')?>"><datalist id="dl_speed_limit"><?php foreach($speedLimits as $v):?><option value="<?=e($v)?>"><?php endforeach;?></datalist></div>
                    </div>
                    <label>Status</label>
                    <select class="form-select mb-2" name="data[status]"><?php $editActive = offer_is_active($edit['status']??''); foreach(['1'=>'Active','0'=>'Inactive'] as $v=>$lbl):?><option value="<?=$v?>" <?=($editActive?'1':'0')===(string)$v?'selected':''?>><?=$lbl?></option><?php endforeach;?></select>
                    <button class="btn btn-primary w-100">Preview & Confirm Save</button>
                    <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=offers"><?=$dupOf?'Cancel':'Cancel Edit'?></a><?php endif;?>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="cardx">
                <div class="d-flex justify-content-between align-items-center"><h3>Offer Catalog</h3><span class="badge bg-primary"><?=number_format($data['total'])?> offers</span></div>
                <form method="get" class="row g-2 mt-1">
                    <input type="hidden" name="page" value="offers">
                    <div class="col-md-3"><input class="form-control" name="name" placeholder="Name" value="<?=e($_GET['name']??'')?>"></div>
                    <div class="col-md-3"><input class="form-control" name="offer_code" placeholder="Offer code" value="<?=e($_GET['offer_code']??'')?>"></div>
                    <div class="col-md-2"><input class="form-control" name="vendor" placeholder="Vendor" value="<?=e($_GET['vendor']??'')?>"></div>
                    <div class="col-md-2"><input class="form-control" name="category" placeholder="Category" value="<?=e($_GET['category']??'')?>"></div>
                    <div class="col-md-2"><select class="form-select" name="status"><option value="">Any status</option><?php foreach(['1'=>'Active','0'=>'Inactive'] as $v=>$lbl):?><option value="<?=$v?>" <?=(string)($_GET['status']??'')===(string)$v?'selected':''?>><?=$lbl?></option><?php endforeach;?></select></div>
                    <div class="col-md-2"><label class="form-label small text-muted mb-0">Per page</label><select class="form-select" name="per_page" data-autosubmit><?php foreach(PAGE_SIZE_OPTIONS as $ps):?><option value="<?=$ps?>" <?=$perPage===$ps?'selected':''?>><?=$ps?></option><?php endforeach;?></select></div>
                    <div class="col-12"><button class="btn btn-outline-primary">Search</button> <a class="btn btn-outline-secondary" href="?page=offers">Reset</a></div>
                </form>
                <div class="table-scroll mt-3"><table class="table table-hover table-sm"><thead><tr><th>Name</th><th>Offer Code</th><th>Category</th><th>Price</th><th>Validity</th><th>Status</th><th></th></tr></thead><tbody>
                <?php foreach($data['rows'] as $r):?><tr>
                    <td><strong><?=e($r['name'])?></strong><div class="text-muted small"><?=e(mb_strimwidth((string)$r['description'],0,60,'...'))?></div></td>
                    <td><?=e($r['offer_code'])?></td>
                    <td><?=e($r['category'])?><div class="text-muted small"><?=e($r['vendor'])?></div></td>
                    <td><?=e($r['one_time_price'])?></td>
                    <td><?=e($r['validity_amount'])?> d</td>
                    <td><span class="badge <?=offer_is_active($r['status'])?'bg-success':'bg-secondary'?>"><?=offer_is_active($r['status'])?'Active':'Inactive'?></span></td>
                    <td><div class="d-flex gap-1">
                        <a class="btn btn-sm btn-warning" href="?page=offers&id=<?=e($r['id'])?>" title="Edit offer" aria-label="Edit offer"><i class="fa-solid fa-pen"></i></a>
                        <?php if(can('create_records')):?><a class="btn btn-sm btn-outline-primary" title="Duplicate — copy this offer, change what you need, save as a new one" aria-label="Duplicate offer" href="?page=offers&duplicate=<?=e($r['id'])?>"><i class="fa-regular fa-copy"></i></a><?php endif;?>
                        <?php if(can('edit_records')):?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-sm btn-outline-dark" title="Activate / deactivate" aria-label="Activate or deactivate"><i class="fa-solid fa-power-off"></i></button></form><?php endif;?>
                    </div></td>
                </tr><?php endforeach;?>
                </tbody></table></div>
                <?php $pages=max(1,(int)ceil($data['total']/$perPage));?>
                <div class="d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
            </div>
        </div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='promotions') {
    require_perm('manage_promotions'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        save_promotion($schema, $_POST['name']??'', $_POST['offer_codes']??[], $id);
        flash('success','Promotion saved.');
        redirect('?page=promotions');
    }
    $edit=null; if(isset($_GET['id'])){ $edit=get_promotion((int)$_GET['id']); }
    $promotions=list_promotions($schema);
    $offers = table_exists($schema,'vas_offers') ? pdo($schema)->query("SELECT offer_code, name FROM vas_offers ORDER BY name")->fetchAll() : [];
    layout_start('Promotions');
    ?>
    <div class="row g-3">
        <div class="col-lg-5"><div class="cardx">
            <h3><?= $edit?'Edit Promotion':'Add Promotion' ?></h3>
            <p class="text-muted">Group offer codes under one name so the Promotion Performance report can run without hand-written SQL. "Buy for Other" transactions are picked up automatically via each offer's own Other Offer Code — no need to list those separately.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                <input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <label>Name</label><input class="form-control mb-2" name="name" value="<?=e($edit['name']??'')?>" required>
                <label>Offer Codes</label>
                <input class="form-control mb-1" type="text" placeholder="Search by code or name…" data-filter-select="promotion_offer_codes">
                <select class="form-select" id="promotion_offer_codes" name="offer_codes[]" multiple size="10">
                    <?php foreach($offers as $o): $selected = $edit && in_array($o['offer_code'],$edit['offer_codes'],true); ?>
                    <option value="<?=e($o['offer_code'])?>" <?=$selected?'selected':''?>><?=e($o['offer_code'])?> — <?=e($o['name'])?></option>
                    <?php endforeach;?>
                </select>
                <p class="text-muted small mt-1">Ctrl/Cmd-click to select multiple.</p>
                <button class="btn btn-primary w-100 mt-2">Save Promotion</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=promotions">Cancel Edit</a><?php endif;?>
            </form>
        </div></div>
        <div class="col-lg-7"><div class="cardx">
            <h3>Promotions in <?=e($schema)?></h3>
            <?php if(!$promotions):?><p class="text-muted mb-0">No promotions defined yet.</p><?php else:?>
            <div class="table-scroll"><table class="table table-hover"><thead><tr><th>Name</th><th></th></tr></thead><tbody>
            <?php foreach($promotions as $p):?><tr><td><?=e($p['name'])?></td><td><a class="btn btn-sm btn-warning" href="?page=promotions&id=<?=e($p['id'])?>">Edit</a></td></tr><?php endforeach;?>
            </tbody></table></div>
            <?php endif;?>
        </div></div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='offer_report') {
    require_perm('view_reports'); $schema=current_schema();
    $promotions=list_promotions($schema);
    $channels=distinct_recent_channels($schema);
    $offers = table_exists($schema,'vas_offers') ? pdo($schema)->query("SELECT offer_code, name FROM vas_offers ORDER BY name")->fetchAll() : [];
    $promotionId=(int)($_GET['promotion_id']??0);
    $selectedCodes = $_GET['offer_codes'] ?? null;
    if ($selectedCodes === null && $promotionId) { $p=get_promotion($promotionId); $selectedCodes = $p['offer_codes'] ?? []; }
    $selectedCodes = $selectedCodes ?? [];
    $channel=trim((string)($_GET['channel']??''));
    $dateFrom=trim((string)($_GET['date_from']??date('Y-m-d',strtotime('-6 days')))); $dateTo=trim((string)($_GET['date_to']??date('Y-m-d')));
    $result=null;
    if (isset($_GET['generate']) || ($_GET['format']??'')==='csv') {
        $result = offer_performance_report($schema, $selectedCodes, $channel, $dateFrom, $dateTo);
        if (($_GET['format']??'')==='csv') {
            audit('offer_report_export',$schema,'vas_offers',null,"offers=".implode(',',$selectedCodes)." range=$dateFrom..$dateTo channel=$channel");
            $codesForFilename = $selectedCodes ? implode('_', array_map(fn($c)=>preg_replace('/[^A-Za-z0-9]/','',$c), $selectedCodes)) : 'all';
            if (strlen($codesForFilename) > 80) $codesForFilename = substr($codesForFilename, 0, 80).'_etc';
            header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="offer_performance_'.$codesForFilename.'_report.csv"');
            $out=fopen('php://output','w'); $first=true;
            foreach($result as $row){ if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,$row); }
            if($first) fputcsv($out,['(no rows returned)']);
            exit;
        }
    }
    layout_start('Offer Performance');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-bullhorn me-2"></i>Offer Performance</h3>
        <p class="text-muted">Success/failure breakdown by offer, including "Buy for Other" purchases, over a bounded date range (subscription has no supporting index, so the range is capped at <?=PROMOTION_REPORT_MAX_RANGE_DAYS?> days). Leave Offers empty to report on every offer.</p>
        <?php if($promotions):?>
        <form method="get" class="row g-2 mb-2"><input type="hidden" name="page" value="offer_report">
            <div class="col-md-4"><label class="small text-muted mb-0">Quick-load from a Promotion</label><select class="form-select" name="promotion_id" data-autosubmit><option value="">— none —</option><?php foreach($promotions as $p):?><option value="<?=e($p['id'])?>" <?=$promotionId===(int)$p['id']?'selected':''?>><?=e($p['name'])?></option><?php endforeach;?></select></div>
        </form>
        <?php endif;?>
        <form method="get" class="row g-2">
            <input type="hidden" name="page" value="offer_report">
            <div class="col-md-4"><label class="small text-muted mb-0">Offers <small>(empty = all)</small></label><input class="form-control form-control-sm mb-1" type="text" placeholder="Search by code or name…" data-filter-select="offer_report_codes"><select class="form-select" id="offer_report_codes" name="offer_codes[]" multiple size="6"><?php foreach($offers as $o):?><option value="<?=e($o['offer_code'])?>" <?=in_array($o['offer_code'],$selectedCodes,true)?'selected':''?>><?=e($o['offer_code'])?> — <?=e($o['name'])?></option><?php endforeach;?></select></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Channel</label><input class="form-control" name="channel" list="dl_channel" value="<?=e($channel)?>" placeholder="Any"><datalist id="dl_channel"><?php foreach($channels as $c):?><option value="<?=e($c)?>"><?php endforeach;?></datalist></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Date from</label><input type="date" class="form-control" name="date_from" value="<?=e($dateFrom)?>"></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Date to</label><input type="date" class="form-control" name="date_to" value="<?=e($dateTo)?>"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" name="generate" value="1">Generate</button></div>
        </form>
    </div>
    <?php if($result!==null):?>
    <div class="cardx mt-3">
        <div class="d-flex justify-content-between align-items-center"><h3>Results <small class="text-muted"><?=number_format(count($result))?> rows</small></h3><a class="btn btn-outline-primary btn-sm" href="?<?=http_build_query(array_merge($_GET,['format'=>'csv']))?>">Download CSV</a></div>
        <?php if(!$result):?><p class="text-muted mb-0">No matching transactions in this range.</p><?php else:?>
        <div class="table-scroll mt-2"><table class="table table-hover table-sm"><thead><tr><th>Date</th><th>Channel</th><th>Offer</th><th>Offer Code</th><th>Txn Offer Code</th><th>Purchase Type</th><th>Result</th><th>Reason</th><th>Total</th><th>Success</th><th>Failed</th><th>Distinct Users</th></tr></thead><tbody>
        <?php foreach($result as $r):?><tr>
            <td><?=e($r['ReportDate'])?></td><td><?=e($r['Channel'])?></td><td><?=e($r['OfferName'])?></td><td><?=e($r['OfferCode'])?></td><td><?=e($r['TransactionOfferCode'])?></td><td><?=e($r['PurchaseType'])?></td>
            <td><span class="badge <?=$r['ResultStatus']==='Successful'?'bg-success':'bg-danger'?>"><?=e($r['ResultStatus'])?></span></td><td><?=e($r['FailureReason'])?></td>
            <td><?=number_format((int)$r['TotalAttempts'])?></td><td><?=number_format((int)$r['SuccessfulAttempts'])?></td><td><?=number_format((int)$r['UnsuccessfulAttempts'])?></td><td><?=number_format((int)$r['TotalDistinctUsers'])?></td>
        </tr><?php endforeach;?>
        </tbody></table></div>
        <?php endif;?>
    </div>
    <?php endif;?>
    <?php layout_end(); exit;
}

if ($page==='subscriptions') {
    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'subscription')) throw new RuntimeException('subscription does not exist in '.$schema);
    $hasSearch = trim((string)($_GET['msisdn']??''))!=='' || trim((string)($_GET['transaction_id']??''))!=='';
    $data = ['rows'=>[], 'total'=>0]; $f = null; $pageNo=max(1,(int)($_GET['p']??1));
    if ($hasSearch) { $f=subscription_filters_from_request($_GET); $data=search_subscriptions($schema,$f,$pageNo,25); }
    $exportQs=array_merge($_GET,['page'=>'subscriptions_export']);
    layout_start('Subscriptions');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-user-check me-2"></i>Subscriptions</h3>
        <div class="alert alert-warning">This table has ~<?=number_format(approx_table_count($schema,'subscription'))?> rows and no index on MSISDN or date. Search by exact MSISDN or Transaction ID — an unrestricted browse would scan the whole table.</div>
        <form method="get" class="row g-2">
            <input type="hidden" name="page" value="subscriptions">
            <div class="col-md-2"><label>MSISDN</label><input class="form-control" name="msisdn" value="<?=e($_GET['msisdn']??'')?>"></div>
            <div class="col-md-2"><label>Transaction ID</label><input class="form-control" name="transaction_id" value="<?=e($_GET['transaction_id']??'')?>"></div>
            <div class="col-md-2"><label>Type</label><input class="form-control" name="subscription_type" value="<?=e($_GET['subscription_type']??'')?>"></div>
            <div class="col-md-2"><label>Channel</label><input class="form-control" name="channel" value="<?=e($_GET['channel']??'')?>"></div>
            <div class="col-md-2"><label>Date from</label><input type="date" class="form-control" name="date_from" value="<?=e($_GET['date_from']??'')?>"></div>
            <div class="col-md-2"><label>Date to</label><input type="date" class="form-control" name="date_to" value="<?=e($_GET['date_to']??'')?>"></div>
            <div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-primary flex-fill">Search</button><?php if($hasSearch):?><a class="btn btn-outline-primary flex-fill" href="?<?=http_build_query($exportQs)?>">Export CSV</a><?php endif;?></div>
        </form>
    </div>
    <?php if ($hasSearch): ?>
    <div class="cardx table-card mt-3">
        <p class="text-muted px-3 pt-3 mb-0"><?=number_format($data['total'])?> matching subscriptions</p>
        <div class="table-scroll"><table class="table table-hover table-sm align-middle">
            <thead><tr><th>Date</th><th>MSISDN</th><th>Receiver</th><th>Transaction ID</th><th>Type</th><th>Channel</th><th>Result</th><th>Data</th><th>SMS</th><th>Minutes</th></tr></thead>
            <tbody><?php foreach($data['rows'] as $r): $s=subscription_status($r); $badge=fn($v)=>['active'=>'bg-success','expired'=>'bg-secondary'][$v]??'bg-light text-dark'; ?><tr>
                <td><?=e($r['date'])?></td>
                <td><?=e($r['subscriber_msisdn'])?></td>
                <td><?=e($r['receiver_msisdn'])?></td>
                <td><?=e($r['transaction_id'])?></td>
                <td><?=e($r['subscription_type'])?></td>
                <td><?=e($r['channel'])?></td>
                <td><?=e(mb_strimwidth((string)$r['result_desc'],0,60,'...'))?></td>
                <td><?=e($r['data_volume'])?> <span class="badge <?=$badge($s['data'])?>"><?=e($s['data'])?></span></td>
                <td><?=e($r['sms_volume'])?> <span class="badge <?=$badge($s['sms'])?>"><?=e($s['sms'])?></span></td>
                <td><?=e($r['minutes_volume'])?> <span class="badge <?=$badge($s['minutes'])?>"><?=e($s['minutes'])?></span></td>
            </tr><?php endforeach;?></tbody>
        </table></div>
        <?php $pages=max(1,(int)ceil($data['total']/25));?>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
    </div>
    <?php endif; layout_end(); exit;
}

if ($page==='subscriptions_export') {
    require_perm('view_tables'); $schema=current_schema();
    $f=subscription_filters_from_request($_GET);
    audit('export',$schema,'subscription',null,'Subscriptions CSV export: '.json_encode($f));
    $rows=export_subscriptions($schema,$f);
    header('Content-Type:text/csv');
    header('Content-Disposition: attachment; filename="'.$schema.'_subscriptions_export.csv"');
    $out=fopen('php://output','w'); $first=true;
    foreach($rows as $row){ if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,$row); }
    exit;
}

if ($page==='alert_settings') {
    require_perm('manage_api_keys'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['do']??'',['add_recipient','toggle_recipient','save_smtp','save_rules','save_ignored','update_prefs'],true)) {
        require_perm('manage_api_keys');
        if ($_POST['do']==='save_smtp') { save_smtp_settings($_POST); flash('success','Mail server saved. Use "Send test email" to check it.'); }
        elseif ($_POST['do']==='add_recipient') { save_alert_recipient((string)($_POST['email']??'')); flash('success','Recipient saved.'); }
        elseif ($_POST['do']==='save_rules') { save_alert_config($_POST); flash('success','Alert rules saved.'); }
        elseif ($_POST['do']==='save_ignored') { save_alert_ignored_reasons((array)($_POST['ignored']??[])); flash('success','Ignored failure reasons saved.'); }
        elseif ($_POST['do']==='update_prefs') { update_alert_recipient_prefs((int)($_POST['id']??0), !empty($_POST['notify_failure']), !empty($_POST['notify_vendor'])); flash('success','Recipient preferences saved.'); }
        else { toggle_alert_recipient((int)($_POST['id']??0)); flash('success','Recipient updated.'); }
        redirect('?page=alert_settings');
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='test_email') {
        require_perm('manage_api_keys');
        $err = send_email_alert('[VAS Cloud] Test email', 'This is a test email from the VAS Cloud portal Alert Settings page. If you can read this, alert emails are working.');
        audit('alert_test_email',$schema,null,null,$err ?? 'sent');
        flash($err===null?'success':'danger', $err===null?'Test email sent — check the inbox(es) below.':'Test email failed: '.$err);
        redirect('?page=alert_settings');
    }
    $cfg = alert_config(); $ignoredReasons = alert_ignored_reasons();
    $seenReasons = array_column(failure_reasons_breakdown($schema, 24, 30), 'c', 'reason');
    unset($seenReasons['(no reason given)']);
    $allReasons = array_values(array_unique(array_merge(array_keys($seenReasons), $ignoredReasons)));
    layout_start('Alert Settings');
    ?>
    <div class="cardx">
        <h3>What to be alerted about</h3>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_rules">
            <div class="col-lg-6"><div class="border rounded p-3 h-100">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="failure_rate_enabled" value="1" id="fre" <?=$cfg['failure_rate_enabled']?'checked':''?>><label class="form-check-label fw-semibold" for="fre">High failure rate</label></div>
                <p class="text-muted small">Alert when more than this share of the last hour's transactions failed.</p>
                <div class="row g-2"><div class="col-6"><label class="small text-muted mb-0">Failure rate above (%)</label><input class="form-control" type="number" min="1" max="100" name="failure_rate_pct" value="<?=e($cfg['failure_rate_pct'])?>"></div>
                <div class="col-6"><label class="small text-muted mb-0">Only if at least this many transactions</label><input class="form-control" type="number" min="1" name="failure_min_sample" value="<?=e($cfg['failure_min_sample'])?>"></div></div>
            </div></div>
            <div class="col-lg-6"><div class="border rounded p-3 h-100">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="vendor_silent_enabled" value="1" id="vse" <?=$cfg['vendor_silent_enabled']?'checked':''?>><label class="form-check-label fw-semibold" for="vse">Vendor gone silent</label></div>
                <p class="text-muted small">Alert when a vendor that was active this time yesterday sent nothing in the last hour.</p>
                <label class="small text-muted mb-0">Vendor must have sent at least this many yesterday</label><input class="form-control" type="number" min="1" name="vendor_silent_min_baseline" value="<?=e($cfg['vendor_silent_min_baseline'])?>">
            </div></div>
            <div class="col-12"><button class="btn btn-primary">Save alert rules</button> <small class="text-muted">Turning an alert off also removes it from the Dashboard and Alerts page.</small></div>
        </form>
    </div>
    <div class="cardx mt-3">
        <h3>Failure reasons that count toward the failure-rate alert</h3>
        <p class="text-muted">Tick <b>Ignore</b> on reasons that aren't a system problem — for example a customer with no credit — so they can't trigger a failure-rate alert. They still show up in the Dashboard and reports. Reasons shown are the ones seen in the last 24 hours plus anything you've already ignored.</p>
        <?php if(!$allReasons):?><p class="text-muted mb-0">No failures in the last 24 hours to choose from.</p><?php else:?>
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_ignored">
            <div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th style="width:90px">Ignore</th><th>Failure reason</th><th>Last 24h</th></tr></thead><tbody>
            <?php foreach($allReasons as $i=>$reason):?><tr><td><input class="form-check-input" type="checkbox" name="ignored[]" value="<?=e($reason)?>" id="ig<?=$i?>" <?=in_array($reason,$ignoredReasons,true)?'checked':''?>></td><td><label for="ig<?=$i?>" class="mb-0"><?=e($reason)?></label></td><td><?=isset($seenReasons[$reason])?number_format((int)$seenReasons[$reason]):'—'?></td></tr><?php endforeach;?>
            </tbody></table></div>
            <button class="btn btn-primary">Save ignored reasons</button>
        </form>
        <?php endif;?>
    </div>
    <div class="cardx mt-3">
        <h3>Push Alerting Setup</h3>
        <p class="text-muted mb-2">1a. <b>Slack:</b> add a Slack Incoming Webhook as an Integration (Service type: <b>Monitoring</b>, Base URL: your webhook URL, Status: Active) — Integrations page. Alerts fire there whenever the Alerts page or the Dashboard is viewed while an alert is active.</p>
        <?php $smtpServer = smtp_server_settings(); $smtp = smtp_settings(); $recipients = alert_recipients(); $envTo = array_filter(array_map('trim', explode(',', (string)getenv('ALERT_EMAIL_TO')))); ?>
        <p class="text-muted mb-2">1b. <b>Email:</b>
            <?php if(!$smtpServer):?><span class="badge bg-secondary">mail server not configured</span> fill in the mail server below, then add recipients.
            <?php elseif(!$smtp):?><span class="badge bg-warning text-dark">no recipients</span> mail server <?=e($smtpServer['host'].':'.$smtpServer['port'])?> (<?=e($smtpServer['secure'])?>) is configured — add at least one recipient below.
            <?php else:?><span class="badge bg-success">configured</span> sends via <?=e($smtp['host'].':'.$smtp['port'])?> (<?=e($smtp['secure'])?>, settings from <?=e($smtp['source'])?>) to <?=count($smtp['to'])?> recipient(s).
            <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="test_email"><button class="btn btn-sm btn-outline-primary ms-2">Send test email</button></form><?php endif;?></p>
        <details class="mb-3" <?=$smtpServer?'':'open'?>><summary class="fw-semibold">Mail server settings</summary>
        <form method="post" class="row g-2 mt-1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_smtp">
            <div class="col-md-5"><label class="small text-muted mb-0">SMTP host</label><input class="form-control" name="host" value="<?=e($smtpServer['host']??'')?>" placeholder="mail.company.com" required></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Port</label><input class="form-control" type="number" name="port" value="<?=e($smtpServer['port']??587)?>" required></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Security</label><select class="form-select" name="secure"><?php foreach(['tls'=>'STARTTLS (587)','ssl'=>'SSL/TLS (465)','none'=>'None (25)'] as $v=>$lbl):?><option value="<?=$v?>" <?=($smtpServer['secure']??'tls')===$v?'selected':''?>><?=$lbl?></option><?php endforeach;?></select></div>
            <div class="col-md-2 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="tls_verify" value="1" id="tlsv" <?=($smtpServer['verify']??true)?'checked':''?>><label class="form-check-label small" for="tlsv">Verify certificate</label></div></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Username <small>(blank if none)</small></label><input class="form-control" name="username" value="<?=e($smtpServer['user']??'')?>" autocomplete="off"></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Password</label><input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="<?=!empty($smtpServer['has_password'])?'saved — leave blank to keep':'password'?>"></div>
            <div class="col-md-4"><label class="small text-muted mb-0">From address</label><input class="form-control" type="email" name="from_email" value="<?=e(($smtpServer['from']??'')==='vas-cloud@localhost'?'':($smtpServer['from']??''))?>" placeholder="alerts@company.com"></div>
            <div class="col-12"><button class="btn btn-primary">Save mail server</button> <small class="text-muted">The password is stored encrypted and is never shown again.<?php if(($smtpServer['source']??'')==='environment'):?> Currently using the environment's settings; saving here overrides them.<?php endif;?></small></div>
        </form></details>
        <form method="post" class="row g-2 mb-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="add_recipient">
            <div class="col-md-6"><input class="form-control" type="email" name="email" placeholder="name@company.com" required></div>
            <div class="col-md-3"><button class="btn btn-primary">Add recipient</button></div>
        </form>
        <?php if($recipients || $envTo):?>
        <div class="table-scroll mb-3"><table class="table table-sm mb-0"><thead><tr><th>Email</th><th>Status</th><th>Receives</th><th></th></tr></thead><tbody>
        <?php foreach($recipients as $r):?><tr><td><?=e($r['email'])?></td><td><span class="badge <?=(int)$r['active']?'bg-success':'bg-secondary'?>"><?=(int)$r['active']?'Enabled':'Disabled'?></span></td>
            <td><form method="post" class="d-flex gap-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="update_prefs"><input type="hidden" name="id" value="<?=e($r['id'])?>">
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="notify_failure" value="1" data-autosubmit <?=(int)$r['notify_failure']?'checked':''?>> Failure rate</label>
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="notify_vendor" value="1" data-autosubmit <?=(int)$r['notify_vendor']?'checked':''?>> Vendor silent</label></form></td>
            <td><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="toggle_recipient"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-sm btn-outline-dark"><?=(int)$r['active']?'Disable':'Enable'?></button></form></td></tr><?php endforeach;?>
        <?php foreach($envTo as $addr):?><tr><td><?=e($addr)?></td><td><span class="badge bg-info text-dark">From environment</span></td><td><small class="text-muted">all alerts</small></td><td><small class="text-muted">set via ALERT_EMAIL_TO</small></td></tr><?php endforeach;?>
        </tbody></table></div>
        <?php endif;?>
        <p class="text-muted mb-0">2. For alerts even when nobody has the app open, point a scheduler (e.g. a Kubernetes CronJob — see deploy/k8s/05-alert-cronjob.yaml) at this URL every few minutes:</p>
        <p class="text-muted small mb-1">In-cluster URL (what the CronJob calls — no public hostname or /portal prefix involved):</p>
        <pre class="mb-0"><?='http://vas-cloud-app.vas-cloud.svc.cluster.local/?page=alert_cron&token='.e(alert_cron_token())?></pre>
    </div>
    </div>
    <p class="mt-3"><a href="?page=alerts">&larr; Back to Alerts &amp; Monitoring</a></p>
    <?php layout_end(); exit;
}

if ($page==='alerts') {
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
    $allOff = !$cfg['failure_rate_enabled'] && !$cfg['vendor_silent_enabled'];
    layout_start('Alerts & Monitoring');
    ?>
    <div class="metric-grid">
        <div class="metric"><span>Transactions (last hour)</span><strong><?=number_format($stats['total'])?></strong></div>
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
}

if ($page==='esim') {
    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'esim_profile')) throw new RuntimeException('esim_profile does not exist in '.$schema);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);
        require_perm($id ? 'edit_records' : 'create_records');
        $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'esim_profile','keys'=>['id'=>$id],'data'=>$_POST['data']??[]]);
        redirect('?page=confirm&token='.$token);
    }
    $edit=null; if(isset($_GET['id'])){ $edit=fetch_record($schema,'esim_profile',['id'=>(int)$_GET['id']]); }
    $filters=[];
    foreach(['msisdn'=>'msisdn','iccid'=>'iccid','imsi'=>'imsi'] as $qp=>$col) if(trim((string)($_GET[$qp]??''))!=='') $filters[]=['col'=>$col,'op'=>'equals','val'=>trim((string)$_GET[$qp])];
    if (trim((string)($_GET['status']??''))!=='') $filters[]=['col'=>'status','op'=>'contains','val'=>$_GET['status']];
    $pageNo=max(1,(int)($_GET['p']??1));
    $data=list_records($schema,'esim_profile',$filters,$pageNo,25); $data['rows']=array_map('redact_row',$data['rows']);
    layout_start('eSIM Profiles');
    ?>
    <div class="row g-3">
        <div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit Profile':'Add Profile' ?></h3><p class="text-muted">Saving requires confirmation.</p>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <label>Vendor</label><input class="form-control mb-2" name="data[vendor_name]" value="<?=e($edit['vendor_name']??'')?>">
                <label>ICCID</label><input class="form-control mb-2" name="data[iccid]" value="<?=e($edit['iccid']??'')?>">
                <label>IMSI</label><input class="form-control mb-2" name="data[imsi]" value="<?=e($edit['imsi']??'')?>">
                <label>MSISDN</label><input class="form-control mb-2" name="data[msisdn]" value="<?=e($edit['msisdn']??'')?>">
                <label>QR Code Value</label><input class="form-control mb-2" name="data[qr_code_value]" value="<?=e($edit['qr_code_value']??'')?>">
                <label>Profile Name</label><input class="form-control mb-2" name="data[profile_names]" value="<?=e($edit['profile_names']??'')?>">
                <label>SM-DP+ Address</label><input class="form-control mb-2" name="data[smdp_address]" value="<?=e($edit['smdp_address']??'')?>">
                <label>Matching ID</label><input class="form-control mb-2" name="data[matching_id]" value="<?=e($edit['matching_id']??'')?>">
                <div class="row g-2">
                    <div class="col-6"><label>Installation Status</label><input class="form-control mb-2" name="data[installation_status]" value="<?=e($edit['installation_status']??'')?>"></div>
                    <div class="col-6"><label>Status</label><input class="form-control mb-2" name="data[status]" value="<?=e($edit['status']??'')?>"></div>
                </div>
                <label>Availability</label><input class="form-control mb-2" name="data[Availability]" value="<?=e($edit['Availability']??'')?>">
                <button class="btn btn-primary w-100">Preview & Confirm Save</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=esim">Cancel Edit</a><?php endif;?>
            </form>
        </div></div>
        <div class="col-lg-8"><div class="cardx">
            <div class="d-flex justify-content-between align-items-center"><h3>eSIM Profiles</h3><span class="badge bg-primary"><?=number_format($data['total'])?> profiles</span></div>
            <form method="get" class="row g-2 mt-1"><input type="hidden" name="page" value="esim">
                <div class="col-md-3"><input class="form-control" name="msisdn" placeholder="MSISDN" value="<?=e($_GET['msisdn']??'')?>"></div>
                <div class="col-md-3"><input class="form-control" name="iccid" placeholder="ICCID" value="<?=e($_GET['iccid']??'')?>"></div>
                <div class="col-md-3"><input class="form-control" name="imsi" placeholder="IMSI" value="<?=e($_GET['imsi']??'')?>"></div>
                <div class="col-md-3"><input class="form-control" name="status" placeholder="Status contains" value="<?=e($_GET['status']??'')?>"></div>
                <div class="col-12"><button class="btn btn-outline-primary">Search</button> <a class="btn btn-outline-secondary" href="?page=esim">Reset</a></div>
            </form>
            <div class="table-scroll mt-3"><table class="table table-hover table-sm"><thead><tr><th>MSISDN</th><th>ICCID</th><th>Vendor</th><th>Installation</th><th>Status</th><th>Last Connection</th><th></th></tr></thead><tbody>
            <?php foreach($data['rows'] as $r):?><tr>
                <td><?=e($r['msisdn'])?></td><td><?=e($r['iccid'])?></td><td><?=e($r['vendor_name'])?></td>
                <td><?=e($r['installation_status'])?></td><td><?=e($r['status'])?></td><td><?=e($r['last_connection'])?></td>
                <td><a class="btn btn-sm btn-warning" href="?page=esim&id=<?=e($r['id'])?>">Edit</a></td>
            </tr><?php endforeach;?>
            </tbody></table></div>
            <?php $pages=max(1,(int)ceil($data['total']/25));?>
            <div class="d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
        </div></div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='sales') {
    require_perm('view_tables'); $schema=current_schema();
    $orderNo = trim((string)($_GET['order_no'] ?? ''));
    $iccid = trim((string)($_GET['iccid'] ?? ''));
    $order = null; $items = []; $invoices = []; $ordersByIccid = [];
    if ($orderNo !== '') { $order = find_sales_order($schema, $orderNo); $items = sales_order_items_for($schema, $orderNo); $invoices = sales_invoices_for($schema, $orderNo); }
    elseif ($iccid !== '') { $ordersByIccid = find_sales_orders_by_iccid($schema, $iccid); }
    layout_start('Sales & Invoices');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-file-invoice-dollar me-2"></i>Sales Orders & Invoices</h3>
        <p class="text-muted">Look up an order by Order No. or ICCID to see its line items and linked invoice in one place.</p>
        <form method="get" class="row g-2"><input type="hidden" name="page" value="sales">
            <div class="col-md-4"><label>Order No.</label><input class="form-control" name="order_no" value="<?=e($orderNo)?>"></div>
            <div class="col-md-4"><label>ICCID</label><input class="form-control" name="iccid" value="<?=e($iccid)?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100">Search</button></div>
        </form>
    </div>
    <?php if ($orderNo !== ''): if (!$order): ?>
        <div class="alert alert-warning mt-3">No order found with Order No. "<?=e($orderNo)?>".</div>
    <?php else: ?>
        <div class="row g-3 mt-1">
            <div class="col-lg-4"><div class="cardx"><h3>Order <?=e($order['order_no'])?></h3><div class="table-scroll"><table class="table table-sm mb-0">
                <tr><th>Created</th><td><?=e($order['created_at'])?></td></tr>
                <tr><th>Quantity</th><td><?=e($order['quantity'])?></td></tr>
                <tr><th>Amount payable</th><td><?=e($order['amount_payable'])?></td></tr>
                <tr><th>Amount paid</th><td><?=e($order['amount_paid'])?></td></tr>
                <tr><th>Shipping</th><td><?=e($order['amount_shipping'])?></td></tr>
                <tr><th>Discount</th><td><?=e($order['discount'])?></td></tr>
                <tr><th>ICCID</th><td><?=e($order['iccid'])?></td></tr>
            </table></div></div></div>
            <div class="col-lg-4"><div class="cardx"><h3>Line Items</h3><?php if(!$items):?><p class="text-muted mb-0">No line items.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>Offer</th><th>Qty</th><th>Fee</th><th>Bundle</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e($it['offer_id'])?></td><td><?=e($it['quantity'])?></td><td><?=e($it['fee'])?></td><td><?=e($it['bundle_code'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
            <div class="col-lg-4"><div class="cardx"><h3>Invoices</h3><?php if(!$invoices):?><p class="text-muted mb-0">No invoice on file.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>Invoice No.</th><th>Status</th><th>Created</th></tr></thead><tbody><?php foreach($invoices as $inv):?><tr><td><?=e($inv['invoice_no'])?></td><td><?=e($inv['status'])?></td><td><?=e($inv['created_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
        </div>
    <?php endif; elseif ($iccid !== ''): ?>
        <div class="cardx mt-3"><h3>Orders for ICCID <?=e($iccid)?></h3><?php if(!$ordersByIccid):?><p class="text-muted mb-0">No orders found.</p><?php else:?><table class="table table-hover table-sm"><thead><tr><th>Order No.</th><th>Created</th><th>Qty</th><th>Amount Payable</th><th></th></tr></thead><tbody><?php foreach($ordersByIccid as $o):?><tr><td><?=e($o['order_no'])?></td><td><?=e($o['created_at'])?></td><td><?=e($o['quantity'])?></td><td><?=e($o['amount_payable'])?></td><td><a class="btn btn-sm btn-outline-primary" href="?page=sales&order_no=<?=urlencode((string)$o['order_no'])?>">View</a></td></tr><?php endforeach;?></tbody></table><?php endif;?></div>
    <?php endif; layout_end(); exit;
}

if ($page==='friends_family') {
    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'unique_number_subscription')) throw new RuntimeException('unique_number_subscription does not exist in '.$schema);
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='toggle') {
        require_perm('edit_records');
        $id=(int)($_POST['id']??0);
        $row=fetch_record($schema,'unique_number_subscription',['id'=>$id]) ?: throw new RuntimeException('Record not found');
        $newVal = ($row['active']??'')==='1' ? '0' : '1';
        $token=make_confirmation('update',['schema'=>$schema,'table'=>'unique_number_subscription','keys'=>['id'=>$id],'data'=>['active'=>$newVal]]);
        redirect('?page=confirm&token='.$token);
    }
    $filters=[];
    foreach(['msisdn'=>'msisdn','friend_number'=>'friend_number','transaction_id'=>'transaction_id'] as $qp=>$col) if(trim((string)($_GET[$qp]??''))!=='') $filters[]=['col'=>$col,'op'=>'contains','val'=>trim((string)$_GET[$qp])];
    $pageNo=max(1,(int)($_GET['p']??1));
    $data=list_records($schema,'unique_number_subscription',$filters,$pageNo,25);
    layout_start('Friends & Family');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-user-group me-2"></i>Friends & Family Numbers</h3>
        <form method="get" class="row g-2"><input type="hidden" name="page" value="friends_family">
            <div class="col-md-3"><input class="form-control" name="msisdn" placeholder="MSISDN" value="<?=e($_GET['msisdn']??'')?>"></div>
            <div class="col-md-3"><input class="form-control" name="friend_number" placeholder="Friend number" value="<?=e($_GET['friend_number']??'')?>"></div>
            <div class="col-md-3"><input class="form-control" name="transaction_id" placeholder="Transaction ID" value="<?=e($_GET['transaction_id']??'')?>"></div>
            <div class="col-md-3"><button class="btn btn-outline-primary w-100">Search</button></div>
        </form>
    </div>
    <div class="cardx table-card mt-3">
        <p class="text-muted px-3 pt-3 mb-0"><?=number_format($data['total'])?> matching records</p>
        <div class="table-scroll"><table class="table table-hover table-sm"><thead><tr><th>Date</th><th>MSISDN</th><th>Friend Number</th><th>Offer Code</th><th>Expiry</th><th>Active</th><?php if(can('edit_records')):?><th></th><?php endif;?></tr></thead><tbody>
        <?php foreach($data['rows'] as $r):?><tr>
            <td><?=e($r['date'])?></td><td><?=e($r['msisdn'])?></td><td><?=e($r['friend_number'])?></td><td><?=e($r['offer_code'])?></td><td><?=e($r['expiry'])?></td>
            <td><span class="badge <?=$r['active']==='1'?'bg-success':'bg-secondary'?>"><?=$r['active']==='1'?'Active':'Inactive'?></span></td>
            <?php if(can('edit_records')):?><td><form method="post" data-confirm="Toggle this number's active status?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-sm btn-outline-dark">Toggle</button></form></td><?php endif;?>
        </tr><?php endforeach;?>
        </tbody></table></div>
        <?php $pages=max(1,(int)ceil($data['total']/25));?>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='voting') {
    require_perm('view_tables'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);
        require_perm($id ? 'edit_records' : 'create_records');
        $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'voting_contestant','keys'=>['id'=>$id],'data'=>$_POST['data']??[]]);
        redirect('?page=confirm&token='.$token);
    }
    $edit=null; if(isset($_GET['id'])){ $edit=fetch_record($schema,'voting_contestant',['id'=>(int)$_GET['id']]); }
    $tally = voting_tally($schema);
    $contestants = table_exists($schema,'voting_contestant') ? pdo($schema)->query('SELECT * FROM voting_contestant ORDER BY number')->fetchAll() : [];
    layout_start('Voting Service');
    ?>
    <div class="row g-3">
        <div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit Contestant':'Add Contestant' ?></h3>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <label>Name</label><input class="form-control mb-2" name="data[name]" value="<?=e($edit['name']??'')?>">
                <label>Number (vote code)</label><input class="form-control mb-2" name="data[number]" value="<?=e($edit['number']??'')?>">
                <label>Status</label><input class="form-control mb-2" name="data[status]" value="<?=e($edit['status']??'active')?>">
                <button class="btn btn-primary w-100">Preview & Confirm Save</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=voting">Cancel Edit</a><?php endif;?>
            </form>
        </div>
        <div class="cardx mt-3"><h3>Contestants</h3><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Name</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($contestants as $c):?><tr><td><?=e($c['number'])?></td><td><?=e($c['name'])?></td><td><?=e($c['status'])?></td><td><a class="btn btn-sm btn-warning" href="?page=voting&id=<?=e($c['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div>
        </div>
        <div class="col-lg-8"><div class="cardx"><h3>Live Tally</h3><?php if(!$tally):?><p class="text-muted mb-0">No votes recorded.</p><?php else:?><div class="table-scroll"><table class="table table-hover"><thead><tr><th>Vote Code</th><th>Contestant</th><th>Votes</th></tr></thead><tbody><?php foreach($tally as $t):?><tr><td><?=e($t['content'])?></td><td><?=e($t['contestant']??'Unknown')?></td><td><strong><?=number_format($t['votes'])?></strong></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='api_keys') {
    require_perm('manage_api_keys');
    $newKey=null;
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (($_POST['do']??'')==='create') { $newKey=create_api_key(trim($_POST['label']??'') ?: 'Unnamed'); flash('warning','New key created — copy it now, it will not be shown again.'); }
        elseif (($_POST['do']??'')==='revoke') { revoke_api_key((int)$_POST['id']); flash('info','Key revoked.'); }
    }
    $keys=list_api_keys();
    layout_start('Partner API Keys');
    ?>
    <div class="row g-3">
        <div class="col-lg-4"><div class="cardx"><h3>Create API Key</h3><p class="text-muted">Read-only access for partner integrations (e.g. the mobile app or USSD gateway) to <code>api.php</code>.</p>
            <form method="post" data-confirm="Create a new API key?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="create">
                <label>Label</label><input class="form-control mb-2" name="label" placeholder="e.g. GNM Mobile App">
                <button class="btn btn-primary w-100">Create Key</button>
            </form>
            <?php if($newKey):?><div class="alert alert-warning mt-3"><strong>Copy this key now</strong> — it will not be shown again:<br><code style="word-break:break-all;"><?=e($newKey)?></code></div><?php endif;?>
        </div></div>
        <div class="col-lg-8"><div class="cardx"><h3>Keys</h3><table class="table table-sm"><thead><tr><th>Label</th><th>Status</th><th>Created by</th><th>Created</th><th>Last used</th><th></th></tr></thead><tbody>
        <?php foreach($keys as $k):?><tr>
            <td><?=e($k['label'])?></td><td><span class="badge <?=$k['status']==='active'?'bg-success':'bg-secondary'?>"><?=e($k['status'])?></span></td>
            <td><?=e($k['created_by'])?></td><td><?=e($k['created_at'])?></td><td><?=e($k['last_used_at']??'never')?></td>
            <td><?php if($k['status']==='active'):?><form method="post" data-confirm="Revoke this API key? Integrations using it will stop working immediately."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="revoke"><input type="hidden" name="id" value="<?=e($k['id'])?>"><button class="btn btn-sm btn-outline-danger">Revoke</button></form><?php endif;?></td>
        </tr><?php endforeach;?>
        </tbody></table></div></div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='ussd_ivr') {
    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'channel_service_code')) throw new RuntimeException('channel_service_code does not exist in '.$schema);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);
        require_perm($id ? 'edit_records' : 'create_records');
        $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'channel_service_code','keys'=>['id'=>$id],'data'=>$_POST['data']??[]]);
        redirect('?page=confirm&token='.$token);
    }
    $edit=null; if(isset($_GET['id'])){ $edit=fetch_record($schema,'channel_service_code',['id'=>(int)$_GET['id']]); }
    $typeFilter = in_array($_GET['type']??'', ['USSD','IVR'], true) ? $_GET['type'] : null;
    $routes = channel_route_activity($schema, $typeFilter);
    $queue = agent_queue_snapshot($schema);
    $ussdToday = channel_activity_today($schema, ['USSD']);
    $ivrToday = channel_activity_today($schema, ['IVR']);
    layout_start('USSD & IVR');
    ?>
    <div class="metric-grid">
        <div class="metric"><span>USSD Transactions Today</span><strong><?=number_format($ussdToday['total'])?></strong></div>
        <div class="metric"><span>USSD Failed Today</span><strong><?=number_format($ussdToday['failed'])?></strong></div>
        <div class="metric"><span>IVR Transactions Today</span><strong><?=number_format($ivrToday['total'])?></strong></div>
        <div class="metric"><span>IVR Failed Today</span><strong><?=number_format($ivrToday['failed'])?></strong></div>
    </div>
    <p class="text-muted mt-2">Counted from <?=e(AUDIT_LOG_TABLE)?> where channel = USSD/IVR. Channel registration/ownership (short codes, providers) lives on the <a href="?page=shortcodes">Short Codes</a> page; this page is the operational routing and live-queue view.</p>
    <div class="row g-3 mt-1">
        <div class="col-lg-5"><div class="cardx"><h3><?= $edit?'Edit Route':'Add Route' ?></h3><p class="text-muted">Maps a short code + service code to the offer it triggers. Saving requires confirmation.</p>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <label>Type</label><select class="form-select mb-2" name="data[type]"><?php foreach(['USSD','IVR'] as $v):?><option value="<?=e($v)?>" <?=($edit['type']??'USSD')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select>
                <label>Short Code</label><input class="form-control mb-2" name="data[shortcode]" value="<?=e($edit['shortcode']??'')?>" placeholder="*123#">
                <label>Service Code</label><input class="form-control mb-2" name="data[service_code]" value="<?=e($edit['service_code']??'')?>">
                <label>Offer Code</label><input class="form-control mb-2" name="data[offer_code]" value="<?=e($edit['offer_code']??'')?>">
                <label>Status</label><input class="form-control mb-2" name="data[status]" value="<?=e($edit['status']??'active')?>">
                <button class="btn btn-primary w-100">Preview & Confirm Save</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=ussd_ivr">Cancel Edit</a><?php endif;?>
            </form>
        </div></div>
        <div class="col-lg-7"><div class="cardx">
            <div class="d-flex justify-content-between align-items-center"><h3>Routing Table</h3><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary <?=!$typeFilter?'active':''?>" href="?page=ussd_ivr">All</a><a class="btn btn-outline-primary <?=$typeFilter==='USSD'?'active':''?>" href="?page=ussd_ivr&type=USSD">USSD</a><a class="btn btn-outline-primary <?=$typeFilter==='IVR'?'active':''?>" href="?page=ussd_ivr&type=IVR">IVR</a></div></div>
            <?php if(!$routes):?><p class="text-muted mb-0 mt-2">No routes configured<?=$typeFilter?" for $typeFilter":''?>.</p><?php else:?>
            <table class="table table-hover table-sm mt-2"><thead><tr><th>Type</th><th>Short Code</th><th>Service Code</th><th>Offer Code</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach($routes as $r):?><tr><td><span class="badge bg-dark"><?=e($r['type'])?></span></td><td><?=e($r['shortcode'])?></td><td><?=e($r['service_code'])?></td><td><?=e($r['offer_code'])?></td><td><?=e($r['status'])?></td><td><a class="btn btn-sm btn-warning" href="?page=ussd_ivr&id=<?=e($r['id'])?>">Edit</a></td></tr><?php endforeach;?>
            </tbody></table><?php endif;?>
        </div>
        <div class="cardx mt-3"><h3>Live Agent Queue</h3><p class="text-muted">Current USSD/IVR sessions held in <code>agent_queue</code>.</p>
            <?php if(!$queue):?><p class="text-muted mb-0">Queue is empty.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>MSISDN</th><th>Service Code</th><th>Status</th></tr></thead><tbody><?php foreach($queue as $q):?><tr><td><?=e($q['msisdn'])?></td><td><?=e($q['service_code'])?></td><td><span class="badge bg-info text-dark"><?=e($q['status'])?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
        </div></div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='ussd_menu') {
    require_perm('view_tables');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_perm('manage_ussd_menus');
        save_menu_node($_POST['data']??[], !empty($_POST['id'])?(int)$_POST['id']:null);
        flash('success','Menu node saved.');
        redirect('?page=ussd_menu&short_code='.urlencode($_POST['data']['short_code']??''));
    }
    $shortCode = trim((string)($_GET['short_code'] ?? ''));
    if ($shortCode === '') $shortCode = trim((string)($_GET['new_short_code'] ?? ''));
    $known = menu_shortcodes();
    if ($shortCode==='' && $known) $shortCode = $known[0];
    $edit=null; if(isset($_GET['id'])){ $edit=menu_node((int)$_GET['id']); }
    $tree = $shortCode!=='' ? menu_tree($shortCode) : [];
    $flatNodes = $shortCode!=='' ? menu_nodes_flat($shortCode) : [];
    $offers = table_exists(current_schema(),'vas_offers') ? pdo(current_schema())->query("SELECT offer_code, name FROM vas_offers WHERE ".OFFER_ACTIVE_SQL." ORDER BY name")->fetchAll() : [];
    layout_start('USSD Menu Builder');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-sitemap me-2"></i>USSD Menu Builder</h3>
        <p class="text-muted mb-0">Design and preview a USSD menu tree per short code. <strong>This is a design/staging tool</strong> — it does not push configuration to Mobius or any gateway; that needs the gateway's own menu-config API, which isn't wired up yet. Use the JSON export as the source of truth to hand-enter (or later auto-push) into the real gateway.</p>
        <form method="get" class="row g-2 mt-2"><input type="hidden" name="page" value="ussd_menu">
            <div class="col-md-4"><select class="form-select" name="short_code"><?php foreach($known as $sc):?><option value="<?=e($sc)?>" <?=$shortCode===$sc?'selected':''?>><?=e($sc)?></option><?php endforeach;?></select></div>
            <div class="col-md-4"><input class="form-control" name="new_short_code" placeholder="Or start a new short code, e.g. *123#"></div>
            <div class="col-md-4"><button class="btn btn-outline-primary w-100">Switch / Start</button></div>
        </form>
    </div>
    <?php if($shortCode!==''):?>
    <div class="row g-3 mt-1">
        <div class="col-lg-5"><div class="cardx"><h3><?= $edit?'Edit Node':'Add Node' ?></h3>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><input type="hidden" name="data[short_code]" value="<?=e($shortCode)?>">
                <label>Parent</label><select class="form-select mb-2" name="data[parent_id]"><option value="">— None (root menu item) —</option><?php foreach($flatNodes as $n): if($edit && (int)$n['id']===(int)$edit['id']) continue; ?><option value="<?=e($n['id'])?>" <?=(int)($edit['parent_id']??-1)===(int)$n['id']?'selected':''?>><?=e($n['prompt_text'])?></option><?php endforeach;?></select>
                <label>Prompt Text</label><input class="form-control mb-2" name="data[prompt_text]" value="<?=e($edit['prompt_text']??'')?>" placeholder="e.g. Buy Data Bundle">
                <label>Order</label><input type="number" class="form-control mb-2" name="data[display_order]" value="<?=e($edit['display_order']??0)?>">
                <label>Node Type</label><select class="form-select mb-2" name="data[node_type]" id="node_type"><?php foreach(['menu'=>'Submenu (has children)','offer'=>'Purchase an offer','action'=>'Action (e.g. check balance)','end'=>'End session'] as $v=>$label):?><option value="<?=e($v)?>" <?=($edit['node_type']??'menu')===$v?'selected':''?>><?=e($label)?></option><?php endforeach;?></select>
                <label>Offer <small class="text-muted">(if type = Purchase an offer)</small></label><select class="form-select mb-2" name="data[offer_code]"><option value="">—</option><?php foreach($offers as $o):?><option value="<?=e($o['offer_code'])?>" <?=($edit['offer_code']??'')===$o['offer_code']?'selected':''?>><?=e($o['name'])?> (<?=e($o['offer_code'])?>)</option><?php endforeach;?></select>
                <label>Action Key <small class="text-muted">(if type = Action)</small></label><input class="form-control mb-2" name="data[action_key]" value="<?=e($edit['action_key']??'')?>" placeholder="e.g. check_balance">
                <label>Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['draft','active','inactive'] as $v):?><option value="<?=e($v)?>" <?=($edit['status']??'draft')===$v?'selected':''?>><?=e(ucfirst($v))?></option><?php endforeach;?></select>
                <button class="btn btn-primary w-100">Save Node</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>">Cancel Edit</a><?php endif;?>
            </form>
        </div></div>
        <div class="col-lg-7">
            <div class="cardx"><h3>Menu Tree — <?=e($shortCode)?></h3>
                <?php if(!$tree):?><p class="text-muted mb-0">No nodes yet. Add the first root menu item on the left.</p><?php else:?>
                <?php $renderTree = function($nodes, $depth=0) use (&$renderTree, $shortCode) { foreach($nodes as $n): $badge = ['menu'=>'bg-primary','offer'=>'bg-success','action'=>'bg-info text-dark','end'=>'bg-secondary'][$n['node_type']]; ?><div style="margin-left:<?=$depth*20?>px" class="d-flex align-items-center gap-2 py-1"><span class="badge <?=$badge?>"><?=e($n['node_type'])?></span><span><?=e($n['prompt_text'])?></span><?php if($n['offer_code']):?><small class="text-muted">(<?=e($n['offer_code'])?>)</small><?php endif;?><?php if($n['status']!=='active'):?><span class="badge status-<?=e($n['status'])?>"><?=e($n['status'])?></span><?php endif;?><a class="btn btn-sm btn-outline-warning py-0" href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>&id=<?=e($n['id'])?>">Edit</a></div><?php if($n['children']) $renderTree($n['children'],$depth+1); endforeach; }; $renderTree($tree); ?>
                <?php endif;?>
            </div>
            <div class="cardx mt-3"><div class="d-flex justify-content-between align-items-center"><h3>Session Preview</h3><a class="btn btn-outline-primary btn-sm" href="?page=ussd_menu_export&short_code=<?=urlencode($shortCode)?>">Export JSON</a></div>
                <?php if(!$tree):?><p class="text-muted mb-0">Nothing to preview yet.</p><?php else:?><pre class="mb-0"><?=render_menu_preview($tree)?></pre><?php endif;?>
            </div>
        </div>
    </div>
    <?php endif; layout_end(); exit;
}

if ($page==='ussd_menu_export') {
    require_perm('view_tables');
    $shortCode = trim((string)($_GET['short_code'] ?? ''));
    if ($shortCode==='') throw new RuntimeException('short_code is required');
    $tree = menu_tree($shortCode);
    audit('export','vas_portal','ussd_menu_nodes',null,'Menu JSON export: '.$shortCode);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="ussd_menu_'.preg_replace('/[^A-Za-z0-9_-]/','_',$shortCode).'.json"');
    echo json_encode(['short_code'=>$shortCode,'generated_at'=>date('c'),'note'=>'Design export from VAS Cloud — not a Mobius or gateway-native config format.','menu'=>$tree], JSON_PRETTY_PRINT);
    exit;
}

if ($page==='integrations') {
    require_perm('view_tables');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        if (($_POST['action']??'')==='test') {
            require_perm('view_tables');
            $result = test_integration((int)$_POST['id']);
            flash($result['ok']?'success':'danger', ($result['ok']?'Check succeeded: ':'Check failed: ').$result['message'].' ('.$result['latency_ms'].'ms)');
        } else {
            require_perm($id ? 'edit_records' : 'create_records');
            save_integration($_POST['data']??[], $id);
            flash('success','Integration saved.');
        }
        redirect('?page=integrations');
    }
    $edit=null; if(isset($_GET['id'])){ $edit=get_integration((int)$_GET['id']); }
    $typeFilter = in_array($_GET['type']??'', INTEGRATION_TYPES, true) ? $_GET['type'] : null;
    $connections = list_integrations($typeFilter);
    layout_start('Integrations');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-plug-circle-check me-2"></i>Integrations</h3>
        <p class="text-muted mb-0">One registry for every external system this platform talks to — SMSC, USSD gateway, IVR platform, monitoring endpoints, or anything you add later. "Test" performs a real live check right now: an HTTP request to the configured URL, or a raw TCP connect for socket-based systems (e.g. SMPP) — it does not simulate a result.</p>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-12 col-xl-7 order-<?=$edit?0:2?>"><div class="cardx"><h3><?= $edit?'Edit Integration':'Add Integration' ?></h3>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <div class="row g-2">
                    <div class="col-6"><label>Service Type</label><select class="form-select mb-2" name="data[service_type]"><?php foreach(INTEGRATION_TYPES as $v):?><option value="<?=e($v)?>" <?=($edit['service_type']??'other')===$v?'selected':''?>><?=e(ucfirst($v))?></option><?php endforeach;?></select></div>
                    <div class="col-6"><label>Protocol</label><select class="form-select mb-2" name="data[protocol]"><?php foreach(['http'=>'HTTP (URL health check)','tcp'=>'TCP (raw socket connect)'] as $v=>$label):?><option value="<?=e($v)?>" <?=($edit['protocol']??'http')===$v?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
                </div>
                <label>Name</label><input class="form-control mb-2" name="data[name]" value="<?=e($edit['name']??'')?>" placeholder="Primary SMSC / USSD Gateway / IVR Platform">
                <div class="row g-2"><div class="col-8"><label>Host</label><input class="form-control mb-2" name="data[host]" value="<?=e($edit['host']??'')?>" placeholder="smsc.example.com"></div><div class="col-4"><label>Port</label><input class="form-control mb-2" name="data[port]" value="<?=e($edit['port']??'')?>"></div></div>
                <label>Base URL <small class="text-muted">(HTTP only — overrides host if set)</small></label><input class="form-control mb-2" name="data[base_url]" value="<?=e($edit['base_url']??'')?>" placeholder="https://ussd-gateway.example.com">
                <label>Health Check Path <small class="text-muted">(HTTP only)</small></label><input class="form-control mb-2" name="data[health_check_path]" value="<?=e($edit['health_check_path']??'')?>" placeholder="/health">
                <div class="row g-2">
                    <div class="col-6"><label>Auth Type</label><select class="form-select mb-2" name="data[auth_type]"><?php foreach(['none','bearer','api_key','basic'] as $v):?><option value="<?=e($v)?>" <?=($edit['auth_type']??'none')===$v?'selected':''?>><?=e(ucfirst(str_replace('_',' ',$v)))?></option><?php endforeach;?></select></div>
                    <div class="col-6"><label>Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['active','inactive','testing'] as $v):?><option value="<?=e($v)?>" <?=($edit['status']??'testing')===$v?'selected':''?>><?=e(ucfirst($v))?></option><?php endforeach;?></select></div>
                </div>
                <label>Credential <small class="text-muted">(token/API key/user:pass — encrypted at rest<?=$edit?'; leave blank to keep the existing one':''?>)</small></label><input type="password" class="form-control mb-2" name="data[auth_credential]" autocomplete="new-password">
                <label>Notes</label><textarea class="form-control mb-2" name="data[notes]" rows="2"><?=e($edit['notes']??'')?></textarea>
                <button class="btn btn-primary w-100">Save Integration</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=integrations">Cancel Edit</a><?php endif;?>
            </form>
        </div></div>
        <div class="col-12 order-1"><div class="cardx">
            <div class="d-flex justify-content-between align-items-center"><h3>Connections</h3><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary <?=!$typeFilter?'active':''?>" href="?page=integrations">All</a><?php foreach(INTEGRATION_TYPES as $t):?><a class="btn btn-outline-primary <?=$typeFilter===$t?'active':''?>" href="?page=integrations&type=<?=e($t)?>"><?=e(ucfirst($t))?></a><?php endforeach;?></div></div>
            <?php if(!$connections):?><p class="text-muted mb-0 mt-2">No integrations registered<?=$typeFilter?" for $typeFilter":''?> yet.</p><?php else:?>
            <div class="table-scroll mt-2"><table class="table table-hover table-sm"><thead><tr><th>Type</th><th>Name</th><th>Target</th><th>Status</th><th>Last Check</th><th>Last 10 / 24h Uptime</th><th></th></tr></thead><tbody>
            <?php foreach($connections as $c):
                $target = $c['protocol']==='tcp' ? e($c['host']).':'.e($c['port']) : e($c['base_url'] ?: $c['host']);
                $lastCheck = 'never';
                if ($c['last_check_at']) {
                    $badge = $c['last_check_ok'] ? '<span class="badge bg-success">UP</span>' : '<span class="badge bg-danger">DOWN</span>';
                    $lastCheck = $badge.' '.e($c['last_check_latency_ms']).'ms<br><small class="text-muted">'.e($c['last_check_at']).'</small>';
                }
                $history = integration_check_history((int)$c['id'], 10);
                $sparkline = implode('', array_map(fn($h)=>$h['ok']?'●':'○', array_reverse($history)));
                $uptime = integration_uptime_pct((int)$c['id'], 24);
            ?><tr>
                <td><span class="badge bg-dark"><?=e($c['service_type'])?></span></td>
                <td><strong><?=e($c['name'])?></strong></td>
                <td><small><?=$target?></small></td>
                <td><span class="badge <?=$c['status']==='active'?'bg-success':($c['status']==='testing'?'bg-warning text-dark':'bg-secondary')?>"><?=e($c['status'])?></span></td>
                <td><?=$lastCheck?></td>
                <td><span title="Oldest to newest, left to right" style="letter-spacing:2px;color:#22aa55;"><?=e($sparkline)?></span><br><small class="text-muted"><?=$uptime!==null?$uptime.'% up (24h)':'no data yet'?></small></td>
                <td><div class="d-flex gap-1">
                    <a class="btn btn-sm btn-warning" href="?page=integrations&id=<?=e($c['id'])?>">Edit</a>
                    <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=e($c['id'])?>"><button class="btn btn-sm btn-outline-dark">Test</button></form>
                </div></td>
            </tr><?php endforeach;?>
            </tbody></table></div><?php endif;?>
        </div></div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='monitoring') {
    require_perm('view_reports'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='check_all') {
        require_perm('view_tables');
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
        <div class="metric"><span>Transactions today</span><strong><?=number_format($snap['tx_total'])?></strong><small class="text-muted"><?=$snap['tx_success_pct']===null?'no traffic yet':$snap['tx_success_pct'].'% succeeded'?></small></div>
        <div class="metric"><span>Failed today</span><strong><?=number_format($snap['tx_failed'])?></strong><small class="text-muted"><a href="?page=investigate&date_from=<?=$today?>&date_to=<?=$today?>">Investigate →</a></small></div>
        <div class="metric"><span>Integrations</span><strong style="font-size:1.35rem;text-transform:none;white-space:nowrap"><span class="text-success"><?=$snap['int_up']?> up</span><?php if($snap['int_down']):?> <span class="text-danger">· <?=$snap['int_down']?> down</span><?php endif;?></strong><small class="text-muted"><?=$snap['int_stale']?> not recently checked</small></div>
        <div class="metric"><span>Active alerts</span><strong class="<?=$snap['alerts']?'text-danger':'text-success'?>"><?=count($snap['alerts'])?></strong><small class="text-muted"><a href="?page=alerts">Open Alerts →</a></small></div>
    </div>
    <div class="cardx mt-3">
        <h3>Channels today <small class="text-muted">(vs the same time yesterday)</small></h3>
        <?php if(!$snap['channels']):?><p class="text-muted mb-0">No transactions yet today.</p><?php else:?>
        <div class="row g-3 mt-1"><?php foreach($snap['channels'] as $c):?>
            <div class="col-md-6 col-xl-3"><div class="border rounded p-3 h-100">
                <div class="d-flex justify-content-between align-items-baseline"><strong><?=e($c['channel'])?></strong><?php if($c['delta_pct']!==null):?><small class="<?=$c['delta_pct']<=-30?'text-danger fw-semibold':'text-muted'?>"><?=$c['delta_pct']>=0?'▲':'▼'?> <?=abs($c['delta_pct'])?>%</small><?php endif;?></div>
                <div class="fs-3 fw-bold"><?=number_format($c['total'])?></div>
                <div class="progress mb-1" style="height:8px"><div class="progress-bar bg-success" style="width:<?=min(100,$c['success_pct'])?>%"></div></div>
                <small class="text-muted"><?=$c['success_pct']?>% success · <?=number_format($c['failed'])?> failed</small>
                <a class="d-block small mt-1" href="?page=investigate&date_from=<?=$today?>&date_to=<?=$today?><?=$c['channel']==='(none)'?'':'&channel='.urlencode($c['channel'])?>">Investigate →</a>
            </div></div>
        <?php endforeach;?></div><?php endif;?>
    </div>
    <div class="cardx mt-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h3 class="mb-0">Integrations</h3>
            <div class="d-flex gap-2"><?php if(can('view_tables') && $activeInts):?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="check_all"><button class="btn btn-sm btn-primary"><i class="fa-solid fa-rotate me-1"></i>Check all now</button></form><?php endif;?><a class="btn btn-sm btn-outline-primary" href="?page=integrations">Manage</a></div></div>
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
}

if ($page==='projects') { require_perm('manage_projects'); $db=portal_pdo(); $edit=null; $selected=[]; if(isset($_GET['id'])){ $st=$db->prepare('SELECT * FROM portal_projects WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit=$st->fetch(); if($edit) $selected=project_channel_ids((int)$edit['id']); } if($_SERVER['REQUEST_METHOD']==='POST'){ $token=make_confirmation('save_project',['id'=>$_POST['id']??null,'data'=>$_POST['data']??[],'channels'=>$_POST['channels']??[],'return_to'=>'?page=projects']); redirect('?page=confirm&token='.$token); } layout_start('Project Management'); $channels=all_channels(); $rows=$db->query('SELECT * FROM portal_projects ORDER BY id DESC')->fetchAll(); ?><div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit Project':'Add Project' ?></h3><p class="text-muted">Link each project to one or more USSD/IVR channels through the short code.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><label>Project Name</label><input class="form-control mb-2" name="data[project_name]" value="<?=e($edit['project_name']??'')?>" placeholder="VAS Main Menu Upgrade"><label>Primary Short Code</label><input class="form-control mb-2" name="data[short_code]" value="<?=e($edit['short_code']??'')?>" placeholder="*123#"><label>Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['Planning','Development','Testing','Launched','Completed','Suspended'] as $v):?><option value="<?=e($v)?>" <?=($edit['status']??'Planning')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select><div class="row g-2"><div class="col-md-6"><label>Start Date</label><input type="date" class="form-control mb-2" name="data[start_date]" value="<?=e($edit['start_date']??'') ?>"></div><div class="col-md-6"><label>Launch Date</label><input type="date" class="form-control mb-2" name="data[launch_date]" value="<?=e($edit['launch_date']??'') ?>"></div></div><label>Description</label><textarea class="form-control mb-2" name="data[description]" rows="3"><?=e($edit['description']??'')?></textarea><label>Linked Channels</label><div class="channel-picker mb-3"><?php foreach($channels as $c):?><label class="channel-choice"><input type="checkbox" name="channels[]" value="<?=e($c['id'])?>" <?=in_array((int)$c['id'],$selected,true)?'checked':''?>> <span><b><?=e($c['channel_type'])?> <?=e($c['short_code'])?></b><small><?=e($c['service_name'])?> • <?=e($c['provider'])?></small></span></label><?php endforeach;?></div><button class="btn btn-primary w-100">Preview & Confirm Save</button><?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=projects">Cancel Edit</a><?php endif;?></form></div></div><div class="col-lg-8"><div class="cardx"><div class="d-flex justify-content-between align-items-center"><h3>Project Register</h3><span class="badge bg-primary"><?=count($rows)?> projects</span></div><div class="table-scroll"><table class="table table-hover"><thead><tr><th>Project Name</th><th>Primary Short Code</th><th>Status</th><th>Start</th><th>Launch</th><th>Linked Channels</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=e($r['project_name'])?></strong><div class="text-muted small"><?=e(mb_strimwidth((string)$r['description'],0,90,'...'))?></div></td><td><?=e($r['short_code'])?></td><td><span class="badge status-<?=e(strtolower($r['status']))?>"><?=e($r['status'])?></span></td><td><?=e($r['start_date'])?></td><td><?=e($r['launch_date'])?></td><td><?=e(project_channels_label((int)$r['id']))?></td><td class="sticky-actions"><a class="btn btn-sm btn-warning" href="?page=projects&id=<?=e($r['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div></div></div><?php layout_end(); exit; }

if ($page==='account') {
    $me = user();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $st = portal_pdo()->prepare('SELECT password_hash FROM portal_users WHERE id=?'); $st->execute([(int)$me['id']]); $row = $st->fetch();
        $new = (string)($_POST['new_password'] ?? '');
        if (!$row || !password_verify((string)($_POST['current_password'] ?? ''), $row['password_hash'])) flash('danger', 'Current password is incorrect.');
        elseif (strlen($new) < 10) flash('danger', 'New password must be at least 10 characters.');
        elseif ($new !== (string)($_POST['confirm_password'] ?? '')) flash('danger', 'New password and confirmation do not match.');
        else {
            portal_pdo()->prepare('UPDATE portal_users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$me['id']]);
            audit('password_change', 'vas_portal', 'portal_users', (string)$me['id'], 'User changed their own password');
            session_regenerate_id(true); flash('success', 'Password changed.');
        }
        redirect('?page=account');
    }
    layout_start('My Account');
    ?><div class="row"><div class="col-lg-5"><div class="cardx"><h3>Change my password</h3>
    <p class="text-muted"><?=e($me['full_name'])?> · <?=e($me['username'])?> · <?=e($me['role'])?></p>
    <form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <label class="form-label">Current password</label><input type="password" class="form-control mb-2" name="current_password" required autocomplete="current-password">
        <label class="form-label">New password <small class="text-muted">(at least 10 characters)</small></label><input type="password" class="form-control mb-2" name="new_password" required minlength="10" autocomplete="new-password">
        <label class="form-label">Confirm new password</label><input type="password" class="form-control mb-3" name="confirm_password" required minlength="10" autocomplete="new-password">
        <button class="btn btn-primary">Change password</button></form></div></div></div>
    <?php layout_end(); exit;
}
if ($page==='users') {
    require_perm('manage_users');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $data=$_POST; $id=!empty($data['id'])?(int)$data['id']:null;
        $hash=null; if(!empty($data['password'])) $hash=password_hash($data['password'],PASSWORD_DEFAULT);
        try {
            if (!in_array($data['role']??'',['admin','manager','operator','viewer'],true) || !in_array($data['status']??'',['active','disabled'],true) || !in_array($data['default_schema_name']??'',allowed_schemas(),true)) throw new RuntimeException('Invalid role, status or default schema.');
            if (trim((string)($data['username']??''))==='' || trim((string)($data['full_name']??''))==='') throw new RuntimeException('Full name and username are required.');
            if (!empty($data['password']) && strlen((string)$data['password'])<10) throw new RuntimeException('Password must be at least 10 characters.');
            if (!$id && !$hash) throw new RuntimeException('A password (at least 10 characters) is required for a new user.');
            if ($id && $id===(int)user()['id'] && ($data['status']!=='active' || $data['role']!=='admin')) throw new RuntimeException('You cannot disable or demote your own account — ask another admin.');
            if ($id) {
                if ($hash) {
                    portal_pdo()->prepare('UPDATE portal_users SET full_name=?,username=?,password_hash=?,role=?,status=?,default_schema_name=? WHERE id=?')
                        ->execute([$data['full_name'],$data['username'],$hash,$data['role'],$data['status'],$data['default_schema_name'],$id]);
                } else {
                    portal_pdo()->prepare('UPDATE portal_users SET full_name=?,username=?,role=?,status=?,default_schema_name=? WHERE id=?')
                        ->execute([$data['full_name'],$data['username'],$data['role'],$data['status'],$data['default_schema_name'],$id]);
                }
                audit('update','vas_portal','portal_users',(string)$id,json_encode(['username'=>$data['username'],'role'=>$data['role'],'status'=>$data['status'],'password_changed'=>(bool)$hash]));
            } else {
                portal_pdo()->prepare('INSERT INTO portal_users(full_name,username,password_hash,role,status,default_schema_name) VALUES(?,?,?,?,?,?)')
                    ->execute([$data['full_name'],$data['username'],$hash,$data['role'],$data['status'],$data['default_schema_name']]);
                audit('insert','vas_portal','portal_users',null,json_encode(['username'=>$data['username']]));
            }
            flash('success','User saved.');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        } catch (PDOException $e) {
            flash('danger', str_contains($e->getMessage(),'Duplicate') ? 'That username is already taken.' : 'Could not save user: '.$e->getMessage());
        }
        redirect('?page=users');
    }
    $edit=null; if(isset($_GET['id'])){ $st=portal_pdo()->prepare('SELECT id,full_name,username,role,status,default_schema_name FROM portal_users WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit=$st->fetch(); }
    layout_start('User & Access Control');
    $users=portal_pdo()->query('SELECT id,full_name,username,role,status,default_schema_name,last_login,created_at FROM portal_users ORDER BY id DESC')->fetchAll();
    ?><div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit User':'Create User' ?></h3><form method="post" data-confirm="Confirm saving this user?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><label class="form-label">Full name</label><input class="form-control mb-2" name="full_name" value="<?=e($edit['full_name']??'')?>" placeholder="Full name"><label class="form-label">Username</label><input class="form-control mb-2" name="username" value="<?=e($edit['username']??'')?>" placeholder="Username"><label class="form-label">Password <?php if($edit):?><small class="text-muted">(leave blank to keep current)</small><?php endif;?></label><input class="form-control mb-2" name="password" type="password" autocomplete="new-password" placeholder="<?=$edit?'Leave blank to keep current':'Leave blank for default: ChangeMe123'?>"><label class="form-label">Role</label><select name="role" class="form-select mb-2"><?php foreach(['viewer','operator','manager','admin'] as $r):?><option value="<?=e($r)?>" <?=($edit['role']??'viewer')===$r?'selected':''?>><?=e(ucfirst($r))?></option><?php endforeach;?></select><label class="form-label">Status</label><select name="status" class="form-select mb-2"><?php foreach(['active','disabled'] as $s):?><option value="<?=e($s)?>" <?=($edit['status']??'active')===$s?'selected':''?>><?=e(ucfirst($s))?></option><?php endforeach;?></select><label class="form-label">Default Schema</label><select name="default_schema_name" class="form-select mb-2"><?php foreach(allowed_schemas() as $s):?><option value="<?=e($s)?>" <?=($edit['default_schema_name']??'HeraTesting')===$s?'selected':''?>><?=e($s)?></option><?php endforeach;?></select><button class="btn btn-primary w-100">Save User</button><?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=users">Cancel Edit</a><?php endif;?></form></div></div><div class="col-lg-8"><div class="cardx"><h3>Users</h3><div class="table-scroll"><table class="table table-hover"><thead><tr><th>Name</th><th>User</th><th>Role</th><th>Status</th><th>Default</th><th>Last Login</th><th></th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><?=e($u['full_name'])?></td><td><?=e($u['username'])?></td><td><span class="badge bg-<?=role_badge($u['role'])?>"><?=e($u['role'])?></span></td><td><span class="badge <?=$u['status']==='active'?'bg-success':'bg-secondary'?>"><?=e($u['status'])?></span></td><td><?=e($u['default_schema_name'])?></td><td><?=e($u['last_login'] ?? 'never')?></td><td><a class="btn btn-sm btn-warning" href="?page=users&id=<?=e($u['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div></div></div><?php layout_end(); exit; }

if ($page==='audit') { require_perm('view_audit'); layout_start('Audit Trail'); $rows=portal_pdo()->query('SELECT * FROM portal_audit_trail ORDER BY id DESC LIMIT 300')->fetchAll(); ?><div class="cardx"><h3>Latest activity</h3><div class="table-scroll"><table class="table table-sm"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Schema</th><th>Table</th><th>Details</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['created_at'])?></td><td><?=e($r['username'])?></td><td><?=e($r['action'])?></td><td><?=e($r['schema_name'])?></td><td><?=e($r['target_table'])?></td><td><?=e(mb_strimwidth((string)$r['details'],0,150,'...'))?></td></tr><?php endforeach;?></tbody></table></div></div><?php layout_end(); exit; }

if ($page==='reports') { require_perm('view_reports'); layout_start('Reports & Monitoring'); $schema=current_schema(); $tables=table_names($schema); ?><div class="cardx mb-3"><h3><i class="fa-solid fa-headset me-2"></i>Complaint / Transaction Investigation</h3><p class="text-muted mb-2">Look up what happened for a specific subscriber or transaction — filter <?=e(AUDIT_LOG_TABLE)?> by MSISDN, transaction ID, date range, vendor or result, view the full vendor request/response, and export the filtered results to CSV.</p><a class="btn btn-primary" href="?page=investigate">Open Investigation Tool</a></div><div class="metric-grid"><?php foreach(array_slice($tables,0,8) as $t):?><div class="metric"><span><?=e($t)?></span><strong><?=number_format(approx_table_count($schema,$t))?></strong></div><?php endforeach;?></div><div class="cardx"><h3>Operational Monitoring</h3><p class="text-muted">Use this page for quick health checks across VAS tables. Future integration can include API latency, transaction success rates, partner dashboards and alerts.</p></div><?php layout_end(); exit; }

if ($page==='investigate_detail') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_perm('view_reports');
        $src = investigate_source($_GET, current_schema());
        $row = $src === 'subscription' ? subscription_log_detail(current_schema(), (int)($_GET['id'] ?? 0)) : audit_log_detail(current_schema(), (int)($_GET['id'] ?? 0), (string)($_GET['d'] ?? ''));
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Transaction not found.']); exit; }
        audit('investigate_view', current_schema(), $src === 'subscription' ? 'subscription' : AUDIT_LOG_TABLE, (string)$row['id'], 'viewed '.$src.' record of '.$row['transaction_id']);
        echo json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

if ($page==='investigate') {
    require_perm('view_reports');
    $schema=current_schema();
    $sources=investigate_sources($schema); $source=investigate_source($_GET,$schema);
    if ($source==='subscription') {
        $f=subscription_log_filters_from_request($_GET);
        $pageNo=max(1,(int)($_GET['p']??1));
        $data=search_subscription_log($schema,$f,$pageNo,25);
        layout_start('Complaint Investigation');
        $qs=$_GET; unset($qs['p']);
        ?>
        <?=investigate_source_tabs($sources,$source,$_GET)?>
        <div class="cardx">
            <h3><i class="fa-solid fa-headset me-2"></i>Complaint / Subscription Investigation</h3>
            <p class="text-muted">Searches <code><?=e($schema)?>.subscription</code> — one row per subscription attempt with its result. It has no request/response text (use <b>audit_log</b> for that), but it keeps the outcome for dates audit_log may no longer cover. A date range is required (max <?=SUBSCRIPTION_LOG_MAX_RANGE_DAYS?> days) — this table is very large.</p>
            <form method="get" class="row g-2">
                <input type="hidden" name="page" value="investigate"><input type="hidden" name="source" value="subscription">
                <div class="col-md-2"><label>Date from</label><input type="date" name="date_from" class="form-control" value="<?=e($f['date_from'])?>" required></div>
                <div class="col-md-2"><label>Date to</label><input type="date" name="date_to" class="form-control" value="<?=e($f['date_to'])?>" required></div>
                <div class="col-md-2"><label>MSISDN <small class="text-muted">(subscriber or receiver)</small></label><input class="form-control" name="msisdn" value="<?=e($f['msisdn'])?>"></div>
                <div class="col-md-3"><label>Transaction ID</label><input class="form-control" name="transaction_id" value="<?=e($f['transaction_id'])?>"></div>
                <div class="col-md-1"><label>Channel</label><input class="form-control" name="channel" value="<?=e($f['channel'])?>"></div>
                <div class="col-md-2"><label>Type</label><input class="form-control" name="subscription_type" value="<?=e($f['subscription_type'])?>"></div>
                <div class="col-md-6"><label>Result contains</label><input class="form-control" name="result_desc" value="<?=e($f['result_desc'])?>"></div>
                <div class="col-md-6 d-flex align-items-end gap-2">
                    <button class="btn btn-primary flex-fill"><i class="fa fa-search"></i> Search</button>
                    <a class="btn btn-outline-primary flex-fill" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate_export','source'=>'subscription']))?>"><i class="fa fa-file-csv"></i> Export CSV</a>
                </div>
            </form>
        </div>
        <div class="cardx table-card mt-3">
            <p class="text-muted px-3 pt-3 mb-0"><?=number_format($data['total'])?> matching records</p>
            <div class="table-scroll"><table class="table table-hover table-sm align-middle">
                <thead><tr><th>Details</th><th>Date</th><th>Transaction ID</th><th>Subscriber</th><th>Receiver</th><th>Type</th><th>Channel</th><th>Result</th></tr></thead>
                <tbody><?php foreach($data['rows'] as $r): $ok=is_subscription_success($r['result_desc']);?><tr>
                    <td><button type="button" class="btn btn-sm btn-outline-primary" data-tx-view data-source="subscription" data-id="<?=e($r['id'])?>"><i class="fa-solid fa-eye me-1"></i>View</button></td>
                    <td class="text-nowrap"><?=e($r['date'])?></td>
                    <td class="cell-full" style="min-width:300px"><button type="button" class="btn btn-sm btn-link p-0 me-1 align-baseline" title="Copy transaction ID" data-copy="<?=e($r['transaction_id'])?>"><i class="fa-regular fa-copy"></i></button><span class="txid"><?=e($r['transaction_id'])?></span></td>
                    <td class="text-nowrap"><?=e($r['subscriber_msisdn'])?> <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="Copy MSISDN" data-copy="<?=e($r['subscriber_msisdn'])?>"><i class="fa-regular fa-copy"></i></button></td>
                    <td class="text-nowrap"><?=e($r['receiver_msisdn'])?></td>
                    <td><?=e($r['subscription_type'])?></td>
                    <td><?=e($r['channel'])?></td>
                    <td title="<?=e($r['result_desc'])?>"><span class="badge <?=$ok?'bg-success':'bg-danger'?> me-1"><?=$ok?'Success':'Failed'?></span><?=e(mb_strimwidth((string)$r['result_desc'],0,70,'...'))?></td>
                </tr><?php endforeach;?></tbody>
            </table></div>
            <?php $pages=max(1,(int)ceil($data['total']/25));?>
            <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
        </div>
        <?php include_once __DIR__.'/../lib/txviewer_modal.php'; ?>
        <script src="txviewer.js?v=<?=e((string)(@filemtime(__DIR__.'/txviewer.js') ?: time()))?>"></script>
        <?php layout_end(); exit;
    }
    $f=audit_log_filters_from_request($_GET);
    $pageNo=max(1,(int)($_GET['p']??1));
    $data=search_audit_log($schema,$f,$pageNo,25);
    layout_start('Complaint Investigation');
    $qs=$_GET; unset($qs['p']);
    ?>
    <?=investigate_source_tabs($sources,$source,$_GET)?>
    <div class="cardx">
        <h3><i class="fa-solid fa-headset me-2"></i>Complaint / Transaction Investigation</h3>
        <p class="text-muted">Searches <code><?=e($schema)?>.<?=e(AUDIT_LOG_TABLE)?></code>. A date range is required (max <?=AUDIT_LOG_MAX_RANGE_DAYS?> days) — this table is very large and partitioned by date.</p>
        <form method="get" class="row g-2">
            <input type="hidden" name="page" value="investigate">
            <div class="col-md-2"><label>Date from</label><input type="date" name="date_from" class="form-control" value="<?=e($f['date_from'])?>" required></div>
            <div class="col-md-2"><label>Date to</label><input type="date" name="date_to" class="form-control" value="<?=e($f['date_to'])?>" required></div>
            <div class="col-md-2"><label>MSISDN</label><input class="form-control" name="msisdn" value="<?=e($f['msisdn'])?>"></div>
            <div class="col-md-2"><label>Transaction ID</label><input class="form-control" name="transaction_id" value="<?=e($f['transaction_id'])?>"></div>
            <div class="col-md-2"><label>Result status</label><input class="form-control" name="result_status" value="<?=e($f['result_status'])?>" placeholder="0 = success, or a failure code"></div>
            <div class="col-md-2"><label>Vendor</label><input class="form-control" name="vendor" value="<?=e($f['vendor'])?>"></div>
            <div class="col-md-3"><label>Channel</label><input class="form-control" name="channel" value="<?=e($f['channel'])?>"></div>
            <div class="col-md-5"><label>Result description contains</label><input class="form-control" name="result_desc" value="<?=e($f['result_desc'])?>"></div>
            <div class="col-md-4 d-flex align-items-end gap-2">
                <button class="btn btn-primary flex-fill"><i class="fa fa-search"></i> Search</button>
                <a class="btn btn-outline-primary flex-fill" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate_export']))?>"><i class="fa fa-file-csv"></i> Export CSV</a>
            </div>
        </form>
    </div>
    <div class="cardx table-card mt-3">
        <p class="text-muted px-3 pt-3 mb-0"><?=number_format($data['total'])?> matching transactions</p>
        <div class="table-scroll"><table class="table table-hover table-sm align-middle">
            <thead><tr><th>Payload</th><th>Date</th><th>Transaction ID</th><th>MSISDN</th><th>Vendor</th><th>Channel</th><th>Status</th><th>Result</th><th>Response (ms)</th></tr></thead>
            <tbody><?php foreach($data['rows'] as $r):?><tr>
                <td><button type="button" class="btn btn-sm btn-outline-primary" data-tx-view data-id="<?=e($r['id'])?>" data-date="<?=e($r['create_date'])?>"><i class="fa-solid fa-eye me-1"></i>View</button></td>
                <td class="text-nowrap"><?=e($r['create_date'])?></td>
                <td class="cell-full" style="min-width:300px"><button type="button" class="btn btn-sm btn-link p-0 me-1 align-baseline" title="Copy transaction ID" data-copy="<?=e($r['transaction_id'])?>"><i class="fa-regular fa-copy"></i></button><span class="txid"><?=e($r['transaction_id'])?></span></td>
                <td class="text-nowrap"><?=e($r['msisdn'])?> <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="Copy MSISDN" data-copy="<?=e($r['msisdn'])?>"><i class="fa-regular fa-copy"></i></button></td>
                <td><?=e($r['vendor_entity_name'])?></td>
                <td><?=e($r['channel'])?></td>
                <td><span class="badge <?=is_success_status($r['result_status'])?'bg-success':'bg-danger'?>"><?=e($r['result_status'])?></span></td>
                <td title="<?=e($r['result_description'])?>"><?=e(mb_strimwidth((string)$r['result_description'],0,80,'...'))?></td>
                <td><?=e($r['response_time'])?></td>
            </tr><?php endforeach;?></tbody>
        </table></div>
        <?php $pages=max(1,(int)ceil($data['total']/25));?>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
    </div>
    <?php include_once __DIR__.'/../lib/txviewer_modal.php'; ?>
    <script src="txviewer.js?v=<?=e((string)(@filemtime(__DIR__.'/txviewer.js') ?: time()))?>"></script>
    <?php layout_end(); exit;
}

if ($page==='investigate_export') {
    require_perm('view_reports');
    $schema=current_schema();
    if (investigate_source($_GET,$schema)==='subscription') {
        $f=subscription_log_filters_from_request($_GET);
        audit('export',$schema,'subscription',null,'Complaint investigation (subscription) CSV export: '.json_encode($f));
        $rows=export_subscription_log($schema,$f);
        header('Content-Type:text/csv');
        header('Content-Disposition: attachment; filename="'.$schema.'_subscription_'.$f['date_from'].'_to_'.$f['date_to'].'.csv"');
        $out=fopen('php://output','w');
        fputcsv($out, array_map('trim', explode(',', SUBSCRIPTION_LOG_COLS)));
        foreach($rows as $row) fputcsv($out,$row);
        exit;
    }
    $f=audit_log_filters_from_request($_GET);
    audit('export',$schema,AUDIT_LOG_TABLE,null,'Complaint investigation CSV export: '.json_encode($f));
    $rows=export_audit_log($schema,$f);
    header('Content-Type:text/csv');
    header('Content-Disposition: attachment; filename="'.$schema.'_audit_log_'.$f['date_from'].'_to_'.$f['date_to'].'.csv"');
    $out=fopen('php://output','w');
    fputcsv($out, ['id','transaction_id','create_date','msisdn','vendor_entity_name','channel','result_status','result_description','response_time','input_text','output_text']);
    foreach($rows as $row) fputcsv($out,$row);
    exit;
}

throw new RuntimeException('Page not found');
} catch(Throwable $e){ layout_start('Something went wrong'); ?><div class="cardx"><h3>Something went wrong</h3><div class="alert alert-danger"><?=e($e->getMessage())?></div><a class="btn btn-primary" href="?page=dashboard">Back to dashboard</a></div><?php layout_end(); }
