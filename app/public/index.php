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
    if (in_array('HeraProduction', allowed_schemas(), true)) { try { maybe_send_daily_summary('HeraProduction'); } catch (Throwable $e) { error_log('daily summary: '.$e->getMessage()); } }
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
    if ($page==='ussd_sim' || $page==='ussd_quiz' || $page==='ussd_flows') return can('manage_ussd_menus');
    if ($page==='ussd_proxy') return can('manage_api_keys');
    if ($page==='api_keys') return can('manage_api_keys');
    if ($page==='promotions') return can('manage_promotions');
    if (in_array($page,['alert_settings','retention','status'],true)) return can('manage_api_keys');
    if (in_array($page,['investigate','reports','alerts','monitoring','offer_report','timeline','vendor'],true)) return can('view_reports');
    if (in_array($page,['subscriptions','offers','offer_health','esim','sales','friends_family','voting','ussd_ivr','ussd_menu','integrations','tables','projects','shortcodes'],true)) return can('view_tables');
    return true;
}
function layout_start(string $title): void {
    $u=user(); $schema=current_schema(); $current=$_GET['page']??'dashboard';
    $nav = [
        ['dashboard','fa-gauge','Dashboard'],
        ['monitoring','fa-heart-pulse','Monitoring'],
        ['group','fa-layer-group','Operations',[['subscriptions','fa-user-check','Subscriptions'],['offers','fa-tags','Offer Management'],['offer_health','fa-stethoscope','Offer Health'],['esim','fa-sim-card','eSIM Profiles'],['sales','fa-file-invoice-dollar','Sales & Invoices'],['friends_family','fa-user-group','Friends & Family'],['voting','fa-square-poll-vertical','Voting Service']]],
        ['group','fa-tower-broadcast','Infrastructure',[['ussd_ivr','fa-mobile-screen-button','USSD & IVR'],['ussd_menu','fa-sitemap','USSD Menu Builder'],['ussd_sim','fa-mobile-screen','USSD Simulator'],['ussd_quiz','fa-circle-question','USSD Quiz'],['ussd_flows','fa-diagram-project','Service Flows'],['ussd_proxy','fa-plug','USSD Proxy'],['integrations','fa-plug-circle-check','Integrations']]],
        ['group','fa-chart-line','Reports',[['investigate','fa-headset','Complaint Investigation'],['timeline','fa-timeline','Customer Timeline'],['alerts','fa-triangle-exclamation','Alerts'],['offer_report','fa-bullhorn','Offer Performance'],['reports','fa-chart-line','Reports'],['sql','fa-code','SQL Console']]],
        ['group','fa-gears','Admin',[['tables','fa-database','Database Tables'],['promotions','fa-bullhorn','Promotions'],['projects','fa-diagram-project','Projects'],['shortcodes','fa-hashtag','Short Codes'],['api_keys','fa-key','Partner API Keys'],['alert_settings','fa-bell','Alert Settings'],['retention','fa-database','Data Retention'],['status','fa-server','System Status'],['audit','fa-shield-halved','Audit Trail'],['users','fa-users-gear','Users']]],
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
        <?php if(count(user_schemas())<2):?><span class="env-menu env-<?=schema_kind($schema)?>"><span class="env-static"><i class="fa-solid fa-database"></i><?=e(schema_label($schema))?></span></span><?php else:?><details class="env-menu env-<?=schema_kind($schema)?>"><summary title="Database in use"><i class="fa-solid fa-database"></i><?=e(schema_label($schema))?> <i class="fa-solid fa-chevron-down"></i></summary><div class="env-list"><?php foreach(user_schemas() as $s):?><a class="env-<?=schema_kind($s)?><?=$s===$schema?' on':''?>" href="?page=switch_schema&schema=<?=urlencode($s)?>"><span class="dot"></span><strong><?=e(schema_label($s))?></strong><small><?=e($s)?></small></a><?php endforeach;?></div></details><?php endif;?>
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
// Addresses shown on the USSD Proxy page are built from this browser's own address, so a path prefix the app can't see (/portal) is included.
document.querySelectorAll('[data-public-url]').forEach(el => { el.textContent = new URL(el.dataset.publicUrl, location.href).href; });
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
}

if ($page==='tables') { require_perm('view_tables'); layout_start('Database Tables'); $schema=current_schema(); ?><div class="cardx"><div class="d-flex justify-content-between align-items-center"><h3>Tables in <?=e($schema)?></h3><a class="btn btn-outline-primary" href="?page=reports">View Reports</a></div><div class="module-grid mt-3"><?php foreach(table_names($schema) as $t):?><a class="module-card" href="?page=table&table=<?=urlencode($t)?>"><i class="fa-solid fa-table"></i><strong><?=e($t)?></strong><span><?=number_format(approx_table_count($schema,$t))?> records (est.)</span></a><?php endforeach;?></div></div><?php layout_end(); exit; }

if ($page==='table') { require_perm('view_tables'); $schema=current_schema(); $table=$_GET['table']??''; if(!table_exists($schema,$table)) throw new RuntimeException('Table not found'); $pageNo=max(1,(int)($_GET['p']??1)); $perPage=resolve_page_size($_GET); $cols=columns($schema,$table); $filters=parse_table_filters($_GET); $guard=large_table_guard($schema,$table,$filters); if($guard && $guard['level']==='block'){ $data=['rows'=>[],'total'=>0]; } else { $data=list_records($schema,$table,$filters,$pageNo,$perPage); $data['rows']=array_map('redact_row',$data['rows']); } $exportQs=$_GET; $exportQs['page']='export'; layout_start('Table: '.$table); ?><?php if($guard):?><div class="alert alert-<?=$guard['level']==='block'?'warning':'info'?>"><?=e($guard['message'])?></div><?php endif;?><div class="cardx"><div class="d-flex flex-wrap gap-2 justify-content-between"><div><h3><?=e($table)?></h3><p class="text-muted mb-0"><?=number_format($data['total'])?> matching records in <?=e($schema)?></p></div><div class="d-flex gap-2"><a class="btn btn-success" href="?page=form&mode=add&table=<?=urlencode($table)?>"><i class="fa fa-plus"></i> Add</a><a class="btn btn-outline-primary" href="?<?=http_build_query($exportQs)?>">Export CSV<?=$filters?' (filtered)':''?></a><a class="btn btn-outline-dark" href="?page=sync&table=<?=urlencode($table)?>">Copy/Sync</a></div></div><form class="search-panel mt-3" method="get"><input type="hidden" name="page" value="table"><input type="hidden" name="table" value="<?=e($table)?>"><div id="filters"><?php $show=$filters ?: [['col'=>'','op'=>'contains','val'=>'']]; foreach($show as $f):?><div class="filter-row"><select name="fcol[]" class="form-select"><option value="">Select column</option><?php foreach($cols as $c):?><option value="<?=e($c['name'])?>" <?=$f['col']===$c['name']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select><select name="fop[]" class="form-select"><option value="contains" <?=$f['op']==='contains'?'selected':''?>>contains</option><option value="equals" <?=$f['op']==='equals'?'selected':''?>>equals</option><option value="starts" <?=$f['op']==='starts'?'selected':''?>>starts with</option><option value="ends" <?=$f['op']==='ends'?'selected':''?>>ends with</option><option value="gt" <?=$f['op']==='gt'?'selected':''?>>&gt;</option><option value="lt" <?=$f['op']==='lt'?'selected':''?>>&lt;</option></select><input name="fval[]" class="form-control" value="<?=e($f['val'])?>" placeholder="Search value"></div><?php endforeach;?></div><template id="filter-template"><div class="filter-row"><select name="fcol[]" class="form-select"><option value="">Select column</option><?php foreach($cols as $c):?><option value="<?=e($c['name'])?>"><?=e($c['name'])?></option><?php endforeach;?></select><select name="fop[]" class="form-select"><option value="contains">contains</option><option value="equals">equals</option><option value="starts">starts with</option><option value="ends">ends with</option><option value="gt">&gt;</option><option value="lt">&lt;</option></select><input name="fval[]" class="form-control" placeholder="Search value"></div></template><div class="d-flex gap-2 mt-2 align-items-center"><button class="btn btn-primary">Search</button><button type="button" id="add-filter-btn" class="btn btn-outline-primary">Add Filter</button><a href="?page=table&table=<?=urlencode($table)?>" class="btn btn-outline-secondary">Reset</a><label class="ms-auto mb-0 small text-muted">Per page</label><select class="form-select form-select-sm w-auto" name="per_page" data-autosubmit><?php foreach(PAGE_SIZE_OPTIONS as $ps):?><option value="<?=$ps?>" <?=$perPage===$ps?'selected':''?>><?=$ps?></option><?php endforeach;?></select></div></form></div><div class="cardx table-card mt-3"><div class="table-scroll"><table class="table table-hover table-sm align-middle"><thead><tr><?php foreach($cols as $c):?><th><?=e($c['name'])?></th><?php endforeach;?><th class="sticky-actions">Actions</th></tr></thead><tbody><?php foreach($data['rows'] as $r): $key=row_key_query($schema,$table,$r);?><tr><?php foreach($cols as $c): $v=$r[$c['name']]??'';?><td title="<?=e($v)?>"><?=e(mb_strimwidth((string)$v,0,80,'...'))?></td><?php endforeach;?><td class="sticky-actions"><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary" href="?page=view&table=<?=urlencode($table)?>&<?=$key?>">View</a><?php if(can('edit_records')):?><a class="btn btn-outline-warning" href="?page=form&mode=edit&table=<?=urlencode($table)?>&<?=$key?>">Edit</a><?php endif;?><?php if(can('duplicate_records')):?><a class="btn btn-outline-success" href="?page=form&mode=duplicate&table=<?=urlencode($table)?>&<?=$key?>">Duplicate</a><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div><?php $pages=max(1,ceil($data['total']/$perPage));?><div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=e(http_build_query(array_merge($_GET,['p'=>$pageNo-1])))?>">Prev</a><?php endif;?><?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div></div><?php layout_end(); exit; }

if ($page==='view') { require_perm('view_tables'); $schema=current_schema(); $table=$_GET['table']??''; $r=fetch_record($schema,$table,$_GET); if(!$r) throw new RuntimeException('Record not found'); $display=redact_row($r); layout_start('Record Details'); ?><div class="cardx"><div class="d-flex justify-content-between"><h3><?=e($table)?> Record</h3><div class="d-flex gap-2"><a class="btn btn-warning" href="?page=form&mode=edit&table=<?=urlencode($table)?>&<?=row_key_query($schema,$table,$r)?>">Edit</a><a class="btn btn-success" href="?page=form&mode=duplicate&table=<?=urlencode($table)?>&<?=row_key_query($schema,$table,$r)?>">Duplicate</a><a class="btn btn-outline-dark" href="?page=copy_record&table=<?=urlencode($table)?>&<?=row_key_query($schema,$table,$r)?>">Copy to <?=e(opposite_schema($schema))?></a></div></div><div class="detail-grid mt-3"><?php foreach($display as $k=>$v):?><div><label><?=e($k)?></label><pre><?=e($v)?></pre></div><?php endforeach;?></div><div class="alert alert-secondary mt-3">Delete is disabled by system policy. Use status fields such as disabled/retired/deleted_at where available.</div></div><?php layout_end(); exit; }

if ($page==='form') { $schema=current_schema(); $table=$_GET['table']??''; $mode=$_GET['mode']??'add'; if(!table_exists($schema,$table)) throw new RuntimeException('Table not found'); require_perm($mode==='edit'?'edit_records':($mode==='duplicate'?'duplicate_records':'create_records')); $values=[];$keys=[]; if($mode!=='add'){ $values=fetch_record($schema,$table,$_GET) ?: throw new RuntimeException('Record not found'); foreach(primary_columns($schema,$table) as $pk) $keys[$pk]=$values[$pk]; } if($_SERVER['REQUEST_METHOD']==='POST'){ $action=$mode==='edit'?'update':($mode==='duplicate'?'duplicate':'insert'); $token=make_confirmation($action,['schema'=>$schema,'table'=>$table,'mode'=>$mode,'keys'=>$_POST['keys']??[],'data'=>$_POST['data']??[],'return_to'=>'?page=table&table='.urlencode($table)]); redirect('?page=confirm&token='.$token); } layout_start(ucfirst($mode).' Record'); ?><div class="cardx"><h3><?=ucfirst(e($mode))?> in <?=e($schema.'.'.$table)?></h3><p class="text-muted">You will preview and confirm before saving.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><?php render_form($schema,$table,$values,$mode,$keys);?><div class="mt-3"><button class="btn btn-primary">Preview & Confirm</button><a class="btn btn-outline-secondary" href="?page=table&table=<?=urlencode($table)?>">Cancel</a></div></form></div><?php layout_end(); exit; }

if ($page==='copy_record') { $from=current_schema(); $to=opposite_schema($from); $table=$_GET['table']??''; require_perm('copy_records'); $r=fetch_record($from,$table,$_GET) ?: throw new RuntimeException('Record not found'); if($_SERVER['REQUEST_METHOD']==='POST'){ assert_copy_schemas($from,(string)($_POST['to_schema']??$to),false); $token=make_confirmation('copy_record',['from'=>$from,'to'=>$_POST['to_schema']??$to,'table'=>$table,'keys'=>array_intersect_key($_GET,array_flip(primary_columns($from,$table))),'data'=>$_POST['data']??[],'return_to'=>'?page=table&table='.urlencode($table)]); redirect('?page=confirm&token='.$token); } layout_start('Copy Record'); ?><div class="cardx"><h3>Copy record</h3><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Destination</label><select class="form-select w-auto" name="to_schema"><option value="<?=e($to)?>"><?=e($to)?></option><option value="<?=e($from)?>"><?=e($from)?></option></select><p class="text-muted mt-2">Adjust values before copying. Save uses REPLACE to update existing primary keys.</p><?php render_form($from,$table,$r,'duplicate',[]);?><button class="btn btn-primary mt-3">Preview & Confirm Copy</button></form></div><?php layout_end(); exit; }

if ($page==='sync') { $schema=current_schema(); $table=$_GET['table']??''; require_perm('copy_records'); if($_SERVER['REQUEST_METHOD']==='POST'){ $mode=$_POST['mode']??'merge'; $to=$_POST['to_schema']??''; assert_copy_schemas((string)($_POST['from_schema']??''),(string)$to,true); if(!table_exists((string)$_POST['from_schema'],$table)||!table_exists($to,$table)) throw new RuntimeException('Table must exist in both schemas.'); if($mode==='sync' && is_protected_schema($to)) throw new RuntimeException('Full-table sync cannot target '.$to.'. Choose Merge instead.'); $action=$mode==='sync'?'sync_table':'merge_table'; $token=make_confirmation($action,['from'=>$_POST['from_schema'],'to'=>$to,'table'=>$table,'return_to'=>'?page=table&table='.urlencode($table)]); redirect('?page=confirm&token='.$token); } layout_start('Copy / Sync Table'); ?><div class="cardx"><h3>Copy full table data</h3><div class="alert alert-info"><b>Merge</b> upserts by primary key and never deletes existing rows — safe for HeraProduction. <b>Full sync</b> truncates the destination table first and can only target HeraTesting.</div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="row g-3"><div class="col-md-3"><label>Mode</label><select name="mode" class="form-select"><option value="merge">Merge (safe, upsert)</option><option value="sync">Full sync (truncate + reload, HeraTesting only)</option></select></div><div class="col-md-3"><label>From</label><select name="from_schema" class="form-select"><?php foreach(user_schemas() as $s):?><option><?=e($s)?></option><?php endforeach;?></select></div><div class="col-md-3"><label>To</label><select name="to_schema" class="form-select"><?php foreach(array_reverse(user_schemas()) as $s):?><option><?=e($s)?></option><?php endforeach;?></select></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-danger w-100">Preview & Confirm</button></div></div></form></div><?php layout_end(); exit; }

if ($page==='confirm') { $token=$_GET['token']??''; $c=get_confirmation($token) ?: throw new RuntimeException('Confirmation expired or already used.'); if(($c['username']??null)!==(user()['username']??null)) throw new RuntimeException('This confirmation was not created by your session.'); $payload=json_decode($c['payload'],true); if($_SERVER['REQUEST_METHOD']==='POST'){ if(($_POST['decision']??'')==='cancel'){ mark_confirmation($token,'cancelled'); flash('info','Operation cancelled.'); redirect('?'); } $a=$c['action']; if($a==='insert') insert_record($payload['schema'],$payload['table'],$payload['data']); elseif($a==='update') update_record($payload['schema'],$payload['table'],$payload['keys'],$payload['data']); elseif($a==='duplicate') insert_record($payload['schema'],$payload['table'],$payload['data']); elseif($a==='copy_record') copy_record($payload['from'],$payload['to'],$payload['table'],$payload['keys'],$payload['data']); elseif($a==='sync_table') sync_table($payload['from'],$payload['to'],$payload['table']); elseif($a==='merge_table') merge_table($payload['from'],$payload['to'],$payload['table']); elseif($a==='sql') run_sql($payload['schema'],$payload['sql']); elseif($a==='save_project') save_project($payload['data'],$payload['channels']??[],!empty($payload['id'])?(int)$payload['id']:null); elseif($a==='save_shortcode') save_shortcode($payload['data'],!empty($payload['id'])?(int)$payload['id']:null); else throw new RuntimeException('Unknown action'); mark_confirmation($token,'confirmed'); flash('success','Operation completed successfully.'); redirect($payload['return_to'] ?? '?page=dashboard'); } layout_start('Confirm Operation'); ?><div class="cardx confirm-box"><h3>Confirm <?=e($c['action'])?></h3><p>This action will change data. Please review before saving.</p><pre><?=e(json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button name="decision" value="confirm" class="btn btn-danger">Yes, Confirm & Save</button><button name="decision" value="cancel" class="btn btn-outline-secondary">Cancel</button></form></div><?php layout_end(); exit; }

if ($page==='export') { $schema=current_schema(); $table=$_GET['table']??''; require_perm('view_tables'); if(!table_exists($schema,$table)) throw new RuntimeException('Table not found'); $filters=parse_table_filters($_GET); $guard=large_table_guard($schema,$table,$filters); if($guard && $guard['level']==='block') throw new RuntimeException($guard['message']); audit('export',$schema,$table,null,'CSV export'.($filters?' (filtered)':'')); $rows=export_records($schema,$table,$filters,10000); header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="'.$schema.'_'.$table.'_export.csv"'); $out=fopen('php://output','w'); $first=true; foreach($rows as $row){ $row=redact_row($row); if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,csv_safe_row($row));} exit; }

if ($page==='sql') { require_perm('run_sql'); $schema=current_schema(); $result=null; if($_SERVER['REQUEST_METHOD']==='POST'){ $sql=trim($_POST['sql']??''); $kind=safe_sql_kind($sql); if(in_array($kind,SQL_READONLY_KINDS,true)){ $isCsv=(($_POST['format']??''))==='csv'; $result=run_sql($schema,$sql,$isCsv?SQL_CONSOLE_CSV_MAX_ROWS:SQL_CONSOLE_MAX_ROWS); if($isCsv){ audit('sql_csv_export',$schema,null,null,$sql); header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="query_export.csv"'); $out=fopen('php://output','w'); $first=true; foreach($result['rows'] as $row){ if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,csv_safe_row($row)); } if($first) fputcsv($out,['(no rows returned)']); exit; } } else { if(is_protected_schema($schema)) throw new RuntimeException($schema.' is read-only in the SQL Console. Use the record forms (Add/Edit/Copy) for production writes.'); $token=make_confirmation('sql',['schema'=>$schema,'sql'=>$sql,'return_to'=>'?page=sql']); redirect('?page=confirm&token='.$token); } if(!empty($_POST['save_name'])) portal_pdo()->prepare('INSERT INTO saved_queries(name,schema_name,sql_text,created_by) VALUES(?,?,?,?)')->execute([$_POST['save_name'],$schema,$sql,user()['username']]); } layout_start('SQL Console'); ?><div class="cardx"><h3>Safe SQL Console</h3><p class="text-muted">DELETE, DROP and TRUNCATE are blocked. Write queries require confirmation. A leading <code>WITH ... AS (...)</code> common table expression is allowed — it's classified by the real statement that follows it.<?php if(is_protected_schema($schema)):?> <strong><?=e($schema)?> is read-only here</strong> — SELECT/SHOW/DESCRIBE/EXPLAIN only; use the record forms for production writes.<?php endif;?></p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><textarea name="sql" class="form-control code" rows="8" placeholder="SELECT * FROM subscription LIMIT 20"><?=e($_POST['sql']??'')?></textarea><div class="row g-2 mt-2"><div class="col-md-6"><input class="form-control" name="save_name" placeholder="Optional name to save this query"></div><div class="col-md-3"><button class="btn btn-primary w-100" name="format" value="preview">Run / Preview</button></div><div class="col-md-3"><button class="btn btn-outline-primary w-100" name="format" value="csv">Download CSV</button></div></div></form></div><?php if($result):?><div class="cardx mt-3"><h3>Result</h3><?php if($result['affected']!==null):?><p>Affected rows: <?=e($result['affected'])?></p><?php else:?><?php if(!empty($result['truncated'])):?><div class="alert alert-warning py-2">Showing the first <?=number_format($result['limit'])?> rows only — add a <code>LIMIT</code> or narrower <code>WHERE</code>, or use Download CSV (up to <?=number_format(SQL_CONSOLE_CSV_MAX_ROWS)?> rows). Queries are stopped after <?=SQL_CONSOLE_TIMEOUT_MS/1000?>s.</div><?php endif;?><div class="table-scroll"><table class="table table-sm"><thead><tr><?php foreach(array_keys($result['rows'][0]??[]) as $h):?><th><?=e($h)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($result['rows'] as $row):?><tr><?php foreach($row as $v):?><td><?=e(mb_strimwidth((string)$v,0,90,'...'))?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php endif;?></div><?php endif; layout_end(); exit; }

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
            $token=make_confirmation('update',['schema'=>$schema,'table'=>'vas_offers','keys'=>['id'=>$id],'data'=>['status'=>$newStatus],'return_to'=>'?page=offers']);
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
            $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'vas_offers','keys'=>['id'=>$id],'data'=>$data,'return_to'=>'?page=offers']);
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
    foreach(['name'=>'name','offer_code'=>'offer_code','vendor'=>'vendor','category'=>'category','sub_category'=>'sub_category'] as $qp=>$col) if(trim((string)($_GET[$qp]??''))!=='') $filters[]=['col'=>$col,'op'=>'contains','val'=>trim((string)$_GET[$qp])];
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
                    <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=offers"><?=$dupOf?'Cancel':'Cancel Edit'?></a><?php if(!$dupOf && !empty($edit['id'])):?><a class="btn btn-link w-100" href="?page=offer_history&id=<?=e($edit['id'])?>"><i class="fa-solid fa-clock-rotate-left me-1"></i>View history of this offer</a><?php endif; endif;?>
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
                    <div class="col-md-2"><input class="form-control" name="sub_category" placeholder="Sub-category" value="<?=e($_GET['sub_category']??'')?>"></div>
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
                        <?php if(can('edit_records')):?><a class="btn btn-sm btn-warning" href="?page=offers&id=<?=e($r['id'])?>" title="Edit offer" aria-label="Edit offer"><i class="fa-solid fa-pen"></i></a><?php endif;?>
                        <a class="btn btn-sm btn-outline-secondary" title="History — who changed this offer" aria-label="Offer history" href="?page=offer_history&id=<?=e($r['id'])?>"><i class="fa-solid fa-clock-rotate-left"></i></a>
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
            foreach($result as $row){ if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,csv_safe_row($row)); }
            if($first) fputcsv($out,['(no rows returned)']);
            exit;
        }
    }
    layout_start('Offer Performance');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-bullhorn me-2"></i>Offer Performance</h3>
        <p class="text-muted">Success/failure breakdown by offer, including "Buy for Other" purchases, over a bounded date range (capped at <?=PROMOTION_REPORT_MAX_RANGE_DAYS?> days; one or two days is fastest). Leave Offers empty to report on every offer.</p>
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
        <div class="alert alert-warning">This table has ~<?=number_format(approx_table_count($schema,'subscription'))?> rows. Search by exact MSISDN or Transaction ID, and add a date range where you can — an unrestricted browse would scan the whole table.</div>
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
    foreach($rows as $row){ if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,csv_safe_row($row)); }
    exit;
}

if ($page==='alert_settings') {
    require_perm('manage_api_keys'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['do']??'',['add_recipient','toggle_recipient','save_smtp','save_rules','save_ignored','update_prefs','save_summary','rotate_token'],true)) {
        require_perm('manage_api_keys');
        if ($_POST['do']==='save_smtp') { save_smtp_settings($_POST); flash('success','Mail server saved. Use "Send test email" to check it.'); }
        elseif ($_POST['do']==='add_recipient') { save_alert_recipient((string)($_POST['email']??'')); flash('success','Recipient saved.'); }
        elseif ($_POST['do']==='save_rules') { save_alert_config($_POST); flash('success','Alert rules saved.'); }
        elseif ($_POST['do']==='rotate_token') { rotate_alert_cron_token(); flash('warning','Cron token rotated. The scheduled alert job is now rejected until you update its secret — run the kubectl command shown under "Scheduled alerts".'); }
        elseif ($_POST['do']==='save_summary') { save_summary_config($_POST); flash('success','Daily summary settings saved.'); }
        elseif ($_POST['do']==='save_ignored') { save_alert_ignored_reasons((array)($_POST['ignored']??[])); flash('success','Ignored failure reasons saved.'); }
        elseif ($_POST['do']==='update_prefs') { update_alert_recipient_prefs((int)($_POST['id']??0), !empty($_POST['notify_failure']), !empty($_POST['notify_vendor']), !empty($_POST['notify_slow']), !empty($_POST['notify_summary'])); flash('success','Recipient preferences saved.'); }
        else { toggle_alert_recipient((int)($_POST['id']??0)); flash('success','Recipient updated.'); }
        redirect('?page=alert_settings');
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='send_summary') {
        require_perm('manage_api_keys');
        $err = send_daily_summary($schema, null, true);
        flash($err===null?'success':'danger', $err===null?'Summary for yesterday sent to everyone who receives it.':'Could not send: '.$err);
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
                <p class="text-muted small">Alert when more than this share of the last hour's <b>customer</b> transactions failed. Hera's own background lookups (no channel) are not counted, so they can't hide a real problem.</p>
                <div class="row g-2"><div class="col-6"><label class="small text-muted mb-0">Failure rate above (%)</label><input class="form-control" type="number" min="1" max="100" name="failure_rate_pct" value="<?=e($cfg['failure_rate_pct'])?>"></div>
                <div class="col-6"><label class="small text-muted mb-0">Only if at least this many transactions</label><input class="form-control" type="number" min="1" name="failure_min_sample" value="<?=e($cfg['failure_min_sample'])?>"></div></div>
            </div></div>
            <div class="col-lg-6"><div class="border rounded p-3 h-100">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="vendor_silent_enabled" value="1" id="vse" <?=$cfg['vendor_silent_enabled']?'checked':''?>><label class="form-check-label fw-semibold" for="vse">Vendor gone silent</label></div>
                <p class="text-muted small">Alert when a vendor that was active this time yesterday sent nothing in the last hour.</p>
                <label class="small text-muted mb-0">Vendor must have sent at least this many yesterday</label><input class="form-control" type="number" min="1" name="vendor_silent_min_baseline" value="<?=e($cfg['vendor_silent_min_baseline'])?>">
            </div></div>
            <div class="col-12"><div class="border rounded p-3">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="vendor_slow_enabled" value="1" id="vsl" <?=$cfg['vendor_slow_enabled']?'checked':''?>><label class="form-check-label fw-semibold" for="vsl">Vendor responding slowly</label></div>
                <p class="text-muted small">Alert when a vendor's average response time over the last hour is above the limit (a vendor can be "up" and still hurting customers). Response times are in milliseconds.</p>
                <div class="row g-2"><div class="col-md-4"><label class="small text-muted mb-0">Average slower than (ms)</label><input class="form-control" type="number" min="50" name="vendor_slow_ms" value="<?=e($cfg['vendor_slow_ms'])?>"></div>
                <div class="col-md-4"><label class="small text-muted mb-0">Only if at least this many transactions</label><input class="form-control" type="number" min="1" name="vendor_slow_min_sample" value="<?=e($cfg['vendor_slow_min_sample'])?>"></div></div>
            </div></div>
            <div class="col-12"><button class="btn btn-primary">Save alert rules</button> <small class="text-muted">Turning an alert off also removes it from the Dashboard and Alerts page.</small></div>
        </form>
    </div>
    <div class="cardx mt-3">
        <h3>Daily summary email</h3>
        <p class="text-muted">One email each morning with yesterday's totals, success rate, channels, vendors (with average response time), top failure reasons and any vendor that went silent. Sent for HeraProduction by the scheduled job, so the 5-minute alert CronJob must be running. Recipients choose below whether they get it.</p>
        <form method="post" class="row g-2 align-items-end"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_summary">
            <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="summary_enabled" value="1" id="sme" <?=$cfg['summary_enabled']?'checked':''?>><label class="form-check-label fw-semibold" for="sme">Send every day</label></div></div>
            <div class="col-auto"><label class="small text-muted mb-0">After (hour, server time <?=e(date('T'))?>)</label><input class="form-control" type="number" min="0" max="23" name="summary_hour" value="<?=e($cfg['summary_hour'])?>"></div>
            <div class="col-auto"><button class="btn btn-primary">Save</button></div>
        </form>
        <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="send_summary"><button class="btn btn-sm btn-outline-primary">Send yesterday's summary now</button> <small class="text-muted">Goes to every enabled recipient who has "Daily summary" ticked — use it to check the layout.</small></form>
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
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="notify_vendor" value="1" data-autosubmit <?=(int)$r['notify_vendor']?'checked':''?>> Vendor silent</label>
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="notify_slow" value="1" data-autosubmit <?=(int)$r['notify_slow']?'checked':''?>> Vendor slow</label>
                <label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="notify_summary" value="1" data-autosubmit <?=(int)$r['notify_summary']?'checked':''?>> Daily summary</label></form></td>
            <td><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="toggle_recipient"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-sm btn-outline-dark"><?=(int)$r['active']?'Disable':'Enable'?></button></form></td></tr><?php endforeach;?>
        <?php foreach($envTo as $addr):?><tr><td><?=e($addr)?></td><td><span class="badge bg-info text-dark">From environment</span></td><td><small class="text-muted">all alerts</small></td><td><small class="text-muted">set via ALERT_EMAIL_TO</small></td></tr><?php endforeach;?>
        </tbody></table></div>
        <?php endif;?>
        <p class="text-muted mb-0">2. For alerts even when nobody has the app open, point a scheduler (e.g. a Kubernetes CronJob — see deploy/k8s/05-alert-cronjob.yaml) at this URL every few minutes:</p>
        <p class="text-muted small mb-1">In-cluster URL (what the CronJob calls — no public hostname or /portal prefix involved):</p>
        <pre class="mb-2"><?='http://vas-cloud-app.vas-cloud.svc.cluster.local/?page=alert_cron&token='.e(alert_cron_token())?></pre>
        <form method="post" class="d-inline" data-confirm="Rotate the cron token? Scheduled alerts and the daily summary stop until you update the Kubernetes secret."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="rotate_token"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-rotate me-1"></i>Rotate token</button></form>
        <span class="text-muted small ms-2">After rotating, update the secret the CronJob reads:</span>
        <pre class="mt-2 mb-0">kubectl -n vas-cloud create secret generic vas-cloud-alert-cron-secret --from-literal=TOKEN=<?=e(alert_cron_token())?> --dry-run=client -o yaml | kubectl apply -f -</pre>
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
}

if ($page==='esim') {
    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'esim_profile')) throw new RuntimeException('esim_profile does not exist in '.$schema);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);
        require_perm($id ? 'edit_records' : 'create_records');
        $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'esim_profile','keys'=>['id'=>$id],'data'=>$_POST['data']??[],'return_to'=>'?page=esim']);
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
        $token=make_confirmation('update',['schema'=>$schema,'table'=>'unique_number_subscription','keys'=>['id'=>$id],'data'=>['active'=>$newVal],'return_to'=>'?page=friends_family']);
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
        $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'voting_contestant','keys'=>['id'=>$id],'data'=>$_POST['data']??[],'return_to'=>'?page=voting']);
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
        $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'channel_service_code','keys'=>['id'=>$id],'data'=>$_POST['data']??[],'return_to'=>'?page=ussd_ivr']);
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

if ($page==='ussd_proxy') {
    require_perm('manage_api_keys');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $do=(string)($_POST['do']??'');
        if ($do==='save') { save_ussd_proxy_config($_POST); flash('success','USSD proxy settings saved.'); redirect('?page=ussd_proxy'); }
        if ($do==='save_share') { save_share_config($_POST); flash('success','Shared Bundle settings saved.'); redirect('?page=ussd_proxy'); }
        if ($do==='save_purchase') { save_purchase_config($_POST); flash('success','Purchase settings saved.'); redirect('?page=ussd_proxy'); }
        if ($do==='save_mobius') { save_mobius_config($_POST); flash('success','Mobius connection saved. Use "Test connection" to check the login.'); redirect('?page=ussd_proxy'); }
        if ($do==='rotate') { ussd_proxy_rotate_token(); flash('warning','New token created. Update the URL in the Mobius menu(s) — the old URL no longer works.'); redirect('?page=ussd_proxy'); }
        if ($do==='clear') { portal_pdo()->exec('DELETE FROM ussd_proxy_log'); audit('ussd_proxy_log_clear',null,'ussd_proxy_log',null,null); flash('success','Captured requests cleared.'); redirect('?page=ussd_proxy'); }
    }
    $cfg=ussd_proxy_config(); $token=ussd_proxy_token(); $mobiusTest=null;
    $mobiusProbe=null; $heraAsk=null; $shareAsk=null;
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='share_ask') { $shareAsk=share_ask($cfg,(string)($_POST['sa_op']??''),(string)($_POST['sa_msisdn']??'')); }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='hera_ask') { $heraAsk=hera_ask($cfg,(string)($_POST['ask_op']??''),(string)($_POST['ask_msisdn']??''),trim((string)($_POST['ask_offer']??'')),trim((string)($_POST['ask_sub']??''))); }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='mobius_probe') { $mobiusProbe=mobius_probe($cfg); audit('ussd_proxy_mobius_probe',null,'ussd_proxy_config',null,json_encode(array_map(fn($r)=>['l'=>$r['label'],'ok'=>$r['ok']],$mobiusProbe))); }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='mobius_test') { $mobiusTest=mobius_test($cfg); audit('ussd_proxy_mobius_test',null,'ussd_proxy_config',null,json_encode(array_map(fn($r)=>['base'=>$r['base'],'ok'=>$r['ok']],$mobiusTest))); }
    $logs=portal_pdo()->query('SELECT * FROM ussd_proxy_log ORDER BY id DESC LIMIT 25')->fetchAll();
    ussd_purchase_table(); $purchases=portal_pdo()->query('SELECT * FROM ussd_purchases ORDER BY id DESC LIMIT 15')->fetchAll();
    // the tester: a sample request (or a captured one to replay), run through the live logic without storing any session
    $test=null; $testIn=''; $testMode='proxy'; $testCt='application/json';
    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['do']??'',['test','replay'],true)) {
        $testMode=(($_POST['tmode']??'')==='ms_initiated')?'ms_initiated':'proxy';
        if ($_POST['do']==='replay') {
            $st=portal_pdo()->prepare('SELECT * FROM ussd_proxy_log WHERE id=?'); $st->execute([(int)($_POST['id']??0)]); $lg=$st->fetch();
            if (!$lg) throw new RuntimeException('That captured request is gone.');
            $testMode=$lg['mode']; $q=json_decode((string)$lg['query_text'],true)?:[]; $raw=(string)$lg['body_text'];
            $flat=ussd_proxy_flatten('',$raw,$q,[]); $testIn=$raw!==''?$raw:http_build_query($q);
        } else {
            $testIn=(string)($_POST['sample']??''); $raw=$testIn; $q=[];
            if (!ctype_space($testIn) && $testIn!=='' && $testIn[0]!=='{' && $testIn[0]!=='<' && !str_contains($testIn,"\n") ) { parse_str($testIn,$q); $raw=''; }
            $flat=ussd_proxy_flatten('',$raw,$q,[]);
        }
        $test=ussd_proxy_process($cfg,$testMode,$flat,true,true); $test['flat']=$flat;
    }
    $keys=[]; foreach($logs as $lg){ $q=json_decode((string)$lg['query_text'],true)?:[]; $keys=array_merge($keys,array_keys(ussd_proxy_flatten('',(string)$lg['body_text'],$q,[]))); if(count($keys)>0) break; }
    $keys=array_values(array_unique($keys));
    $host=($_SERVER['HTTP_HOST']??'your-host'); $proto=(($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'))?'https':'http';
    $base=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/'),'/'); $urlFor=fn($m)=>$proto.'://'.$host.$base.'/ussd.php?t='.$token.'&m='.$m;
    layout_start('USSD Proxy');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-plug me-2"></i>USSD proxy endpoint <span class="badge <?=$cfg['enabled']==='1'?($cfg['mode']==='live'?'bg-success':'bg-warning text-dark'):'bg-secondary'?> ms-2"><?=$cfg['enabled']==='1'?($cfg['mode']==='live'?'ON — serving menus':'ON — capture only'):'OFF'?></span></h3>
        <p class="text-muted mb-2">The address a Mobius <b>PROXY</b> (or <b>MS_INITIATED</b>) menu calls. It is <b>off</b> until you switch it on below, so nothing changes in production until you decide. Start in <b>Capture</b> mode: it records exactly what Mobius sends (and answers with a fixed test text), so we can set the field names from a real request instead of guessing. Only the menu you point at it is affected — no other short code is touched.</p>
        <p class="mb-1 small text-muted">Put one of these in the Mobius menu's <b>URL</b> field (use the address Mobius can actually reach — the in-cluster one only works from inside the cluster):</p>
        <?php foreach(['proxy'=>'PROXY menu','ms_initiated'=>'MS_INITIATED menu'] as $m=>$lbl):?>
        <div class="small fw-semibold mt-2"><?=e($lbl)?> — public address</div><pre class="mb-1 txid" title="Click to select, then copy" data-public-url="ussd.php?t=<?=e($token)?>&amp;m=<?=e($m)?>"><?=e($urlFor($m))?></pre>
        <div class="small text-muted">in-cluster: <code><?=e('http://vas-cloud-app.vas-cloud.svc.cluster.local/ussd.php?t='.$token.'&m='.$m)?></code></div>
        <?php endforeach;?>
        <form method="post" class="mt-3 d-inline" data-confirm="Create a new token? The URL in every Mobius menu must be updated."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="rotate"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-rotate me-1"></i>New token</button></form>
        <span class="small text-muted ms-2">The token is the password to this endpoint — treat the URL like a secret.</span>
    </div>

    <form method="post" class="cardx mt-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save">
        <h3>Settings</h3>
        <div class="row g-3">
            <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="pe" <?=$cfg['enabled']==='1'?'checked':''?>><label class="form-check-label fw-semibold" for="pe">Endpoint enabled</label></div></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Mode</label><select class="form-select" name="mode"><option value="capture" <?=$cfg['mode']!=='live'?'selected':''?>>Capture only (learn the format)</option><option value="live" <?=$cfg['mode']==='live'?'selected':''?>>Live (serve the menus)</option></select></div>
            <div class="col-md-6"><label class="small text-muted mb-0">Only accept calls from these IPs <small>(optional — blank = any; e.g. 192.168.162.20)</small></label><input class="form-control" name="allow_ips" value="<?=e($cfg['allow_ips'])?>"></div>
            <div class="col-md-6"><label class="small text-muted mb-0">Short code served for the PROXY menu</label><input class="form-control" name="shortcode_proxy" value="<?=e($cfg['shortcode_proxy'])?>"></div>
            <div class="col-md-6"><label class="small text-muted mb-0">Short code served for the MS_INITIATED menu</label><input class="form-control" name="shortcode_ms_initiated" value="<?=e($cfg['shortcode_ms_initiated'])?>"></div>
        </div>
        <h5 class="mt-4">What Mobius sends <small class="text-muted">(fill in after a capture — the names below suggest themselves from the latest captured request)</small></h5>
        <datalist id="keyList"><?php foreach($keys as $k):?><option value="<?=e($k)?>"><?php endforeach;?></datalist>
        <div class="row g-3">
            <div class="col-md-3"><label class="small text-muted mb-0">Field with the customer's number</label><input class="form-control" list="keyList" name="f_msisdn" value="<?=e($cfg['f_msisdn'])?>"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Field with the session id</label><input class="form-control" list="keyList" name="f_session" value="<?=e($cfg['f_session'])?>"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Field with what they typed</label><input class="form-control" list="keyList" name="f_input" value="<?=e($cfg['f_input'])?>"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Field with the short code <small>(optional)</small></label><input class="form-control" list="keyList" name="f_shortcode" value="<?=e($cfg['f_shortcode'])?>"></div>
            <div class="col-md-4"><label class="small text-muted mb-0">The typed text is…</label><select class="form-select" name="reply_mode"><option value="step" <?=$cfg['reply_mode']!=='cumulative'?'selected':''?>>just the latest reply (we remember the session)</option><option value="cumulative" <?=$cfg['reply_mode']==='cumulative'?'selected':''?>>everything so far, e.g. 1*2 or *9606*9090*1*2#</option></select></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Session timeout (s)</label><input class="form-control" type="number" name="session_ttl" value="<?=e($cfg['session_ttl'])?>"></div>
        </div>
        <h5 class="mt-4">What we send back</h5>
        <div class="row g-3">
            <div class="col-md-4"><label class="small text-muted mb-0">Content type</label><input class="form-control" name="resp_type" value="<?=e($cfg['resp_type'])?>"></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Word for "session continues" / "ends" in {end}</label><div class="input-group"><input class="form-control" name="end_false" value="<?=e($cfg['end_false'])?>"><input class="form-control" name="end_true" value="<?=e($cfg['end_true'])?>"></div></div>
            <div class="col-12"><label class="small text-muted mb-0">Reply template <small>— placeholders: <code>{text}</code> <code>{text_json}</code> <code>{text_xml}</code> <code>{text_url}</code> <code>{end}</code> <code>{end_int}</code> <code>{con_end}</code> (CON/END) <code>{session}</code> <code>{msisdn}</code></small></label><textarea class="form-control code" rows="3" name="resp_body"><?=e($cfg['resp_body'])?></textarea></div>
            <div class="col-12"><label class="small text-muted mb-0">Reply used in capture mode</label><input class="form-control" name="capture_body" value="<?=e($cfg['capture_body'])?>"></div>
        </div>
        <button class="btn btn-primary mt-3">Save settings</button>
    </form>

    <div class="cardx mt-3"><h3>Mobius connection <small class="text-muted">(for PROXY menus)</small></h3>
        <p class="text-muted">A PROXY menu does not take its screen from our HTTP reply. Mobius sends us each reply, and we send the next screen back through <b>Mobius's REST API</b> (<code>auth/login</code>, then <code>ussdcalls/proxy</code>). That needs a Mobius API user — ideally a dedicated one created under <i>Admins</i> in Mobius, not your own. The password is turned into the MD5 hash Mobius expects and only that hash is kept, <b>encrypted</b>; it is never shown again.</p>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_mobius">
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="push_enabled" value="1" id="pp" <?=$cfg['push_enabled']==='1'?'checked':''?>><label class="form-check-label fw-semibold" for="pp">Answer PROXY menus through the Mobius API <small class="text-muted">(applies in Live mode, to the PROXY menu only)</small></label></div></div>
            <div class="col-md-6"><label class="small text-muted mb-0">Mobius REST address(es) <small>(comma-separated if there are several servers; tried in order)</small></label><input class="form-control" name="mobius_base" value="<?=e($cfg['mobius_base'])?>" placeholder="http://192.168.162.20:28080/rest/"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">API user name</label><input class="form-control" name="mobius_user" value="<?=e($cfg['mobius_user'])?>" autocomplete="off"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Password</label><input class="form-control" type="password" name="mobius_pass" autocomplete="new-password" placeholder="<?=$cfg['mobius_pass']!==''?'saved — leave blank to keep':'password'?>"></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save connection</button></div>
        </form>
        <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="mobius_test"><button class="btn btn-sm btn-outline-primary">Test connection</button> <small class="text-muted">Logs in with the saved details and reports the result. Nothing else is sent.</small></form>
        <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="mobius_probe"><button class="btn btn-sm btn-outline-secondary">Run diagnostics</button> <small class="text-muted">For when screens aren't getting through: logs in, then makes a harmless read-only call (<code>ussdcalls/count</code>) in each way Mobius might want the login presented, and shows what Mobius says to each. Sends no screen and changes nothing.</small></form>
        <?php if($mobiusProbe):?><div class="mt-2"><?php foreach($mobiusProbe as $mp):?><div class="small"><span class="badge <?=$mp['ok']?'bg-success':'bg-danger'?> me-1"><?=$mp['ok']?'OK':'Refused'?></span><b><?=e($mp['label'])?></b> — <?=e($mp['msg'])?></div><?php endforeach;?></div><?php endif;?>
        <?php if($mobiusTest):?><div class="mt-2"><?php foreach($mobiusTest as $mt):?><div class="small"><span class="badge <?=$mt['ok']?'bg-success':'bg-danger'?> me-1"><?=$mt['ok']?'OK':'Failed'?></span><code><?=e($mt['base'])?></code> <?=e($mt['msg'])?></div><?php endforeach;?></div><?php endif;?>
    </div>
    <div class="cardx mt-3"><h3>Buying from the menu <small class="text-muted">(offer items in the Menu Builder)</small></h3>
        <p class="text-muted">When a customer picks an <b>offer</b> item, the menu shows its name and price (read from <b><?=e(USSD_OFFER_SCHEMA)?></b> only) and asks <i>1. Confirm / 2. Cancel</i>. What happens on Confirm depends on the mode. Each call can buy each offer only once, even if Mobius repeats a request.</p>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_purchase">
            <div class="col-md-4"><label class="small text-muted mb-0">Mode</label><select class="form-select" name="purchase_mode">
                <option value="off" <?=$cfg['purchase_mode']==='off'?'selected':''?>>Off — nothing is bought</option>
                <option value="test" <?=$cfg['purchase_mode']==='test'?'selected':''?>>Test — record it, tell the customer it was a test</option>
                <option value="test_low" <?=$cfg['purchase_mode']==='test_low'?'selected':''?>>Test — pretend the balance is too low</option>
                <option value="live" <?=$cfg['purchase_mode']==='live'?'selected':''?>>Live — send the purchase request below</option></select></div>
            <div class="col-md-8"><label class="small text-muted mb-0">Purchase address <small>(POST, JSON — the Hera <b>test</b> environment; needed for Live only)</small></label><input class="form-control" name="purchase_url" value="<?=e($cfg['purchase_url'])?>" placeholder="https://…"></div>
            <div class="col-12"><label class="small text-muted mb-0">Request body <small>— placeholders: <code>{msisdn}</code> <code>{offer_code}</code> <code>{other_offer_code}</code> <code>{vendor}</code> <code>{price}</code> <code>{price_d}</code> (D9) <code>{txn}</code> (the call id) <code>{shortcode}</code> <code>{imsi}</code> <code>{local_address_json}</code> <code>{remote_address_json}</code> <code>{local_dialog_id}</code> <code>{remote_dialog_id}</code> (taken from Mobius's own request)</small></label><textarea class="form-control code" rows="3" name="purchase_body"><?=e($cfg['purchase_body'])?></textarea></div>
            <div class="col-12"><label class="small text-muted mb-0">Request body for buying <b>for another number</b> <small>— same placeholders plus <code>{other_msisdn}</code> (the digits as the customer typed them). Pre-filled with the request your Mobius menu sends. Blank = buying for another number is not switched on (customers are told so, nothing is sent)</small></label><textarea class="form-control code" rows="3" name="purchase_body_other"><?=e($cfg['purchase_body_other'])?></textarea></div>
            <div class="col-md-5"><label class="small text-muted mb-0">Headers to send <small>(one per line, e.g. <code>X-API-KEY: …</code> and <code>X-USERNAME: USSD</code> — kept encrypted, never shown again)</small></label><textarea class="form-control code" rows="3" name="purchase_auth" autocomplete="off" placeholder="<?=$cfg['purchase_auth']!==''?'saved — leave blank to keep':"X-API-KEY: …\nX-USERNAME: USSD"?>"></textarea><?php if($cfg['purchase_auth']!==''):?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="purchase_auth_clear" value="1" id="pac"><label class="form-check-label small" for="pac">Remove the saved header</label></div><?php endif;?></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Success means <small>(text the reply must contain. Blank = the customer is only told the request was <b>sent</b>; see the real reply in the table below, then fill this in)</small></label><input class="form-control" name="purchase_ok_match" value="<?=e($cfg['purchase_ok_match'])?>" placeholder="e.g. success"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Show Hera's own message <small>(name of the reply field that holds the text for the customer, e.g. <code>message</code>; blank = our wording)</small></label><input class="form-control" name="purchase_reply_field" value="<?=e($cfg['purchase_reply_field'])?>"></div>
            <div class="col-md-12"><label class="small text-muted mb-0">Low balance means <small>(if a failed reply contains any of these words, comma-separated, the customer is told the balance is too low)</small></label><input class="form-control" name="purchase_lowbal" value="<?=e($cfg['purchase_lowbal'])?>"></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Timeout (s)</label><input class="form-control" type="number" min="2" max="15" name="purchase_timeout" value="<?=e($cfg['purchase_timeout'])?>"></div>
            <div class="col-12"><button class="btn btn-primary">Save purchase settings</button></div>
        </form>
        <form method="post" class="row g-2 mt-3 align-items-end"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="hera_ask">
            <div class="col-12"><b class="small">Ask Hera about an offer</b> <small class="text-muted">— the same questions the phone menu asks while browsing. Buys nothing, and shows Hera's raw reply.</small></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="ask_op"><option value="chooseOffer">Details of one offer (chooseOffer)</option><option value="listOffer">List a sub-category (listOffer)</option></select></div>
            <div class="col-md-3"><input class="form-control form-control-sm" name="ask_msisdn" placeholder="Phone number, e.g. 220…" value="<?=e((string)($_POST['ask_msisdn']??($purchases[0]['msisdn']??'')))?>"></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="ask_offer" placeholder="Offer code" value="<?=e((string)($_POST['ask_offer']??($purchases[0]['offer_code']??'')))?>"></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="ask_sub" placeholder="or sub-category" value="<?=e((string)($_POST['ask_sub']??''))?>"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Ask Hera</button></div>
        </form>
        <?php if($heraAsk):?><div class="mt-2 small"><div><b>Sent:</b> <code><?=e(json_encode($heraAsk['sent'],JSON_UNESCAPED_SLASHES))?></code></div><div><b>Hera answered</b> (HTTP <?=e($heraAsk['code'])?>)<?=$heraAsk['error']!==''?': '.e($heraAsk['error']):''?>:</div><pre class="mb-0"><?=e($heraAsk['raw'])?></pre></div><?php endif;?>
        <?php if($purchases):?><div class="table-scroll mt-3"><table class="table table-sm mb-0"><thead><tr><th>Time</th><th>Number</th><th>Offer</th><th>Mode</th><th>Result</th><th>Shown to customer</th></tr></thead><tbody>
            <?php foreach($purchases as $pu):?><tr><td class="text-nowrap"><?=e($pu['created_at'])?></td><td><?=e($pu['msisdn'])?><?=!empty($pu['recipient'])?' <small class="text-muted">→ '.e($pu['recipient']).'</small>':''?></td><td title="<?=e((string)($pu['request_body']??''))?>"><?=e($pu['offer_code'])?> <small class="text-muted"><?=e($pu['offer_name'])?></small><?=!empty($pu['request_body'])?' <i class="fa-solid fa-code text-muted" title="Hover to see the request we sent"></i>':''?></td><td><?=e($pu['mode'])?></td><td><span class="badge <?=['ok'=>'bg-success','sent'=>'bg-primary','blocked'=>'bg-secondary','test'=>'bg-info text-dark','lowbal'=>'bg-warning text-dark','failed'=>'bg-danger','pending'=>'bg-warning text-dark'][$pu['status']]??'bg-secondary'?>"><?=e($pu['status'])?></span><?=$pu['http_code']?' <small class="text-muted">HTTP '.e($pu['http_code']).'</small>':''?></td><td title="<?=e((string)$pu['response'])?>"><?=e((string)$pu['reply_text'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <div class="cardx mt-3"><h3>Shared Bundle <small class="text-muted">(Seddo — the "Shared Bundle service" menu item)</small></h3>
        <p class="text-muted">Buy the bundle, add a sharing number, check balance and numbers — the flow from the Shared Bundle diagram, calling Hera's <code>…/prepaid/ShareBundle/</code> addresses. <b>Test</b> simulates every answer and sends nothing. In <b>Live</b>, the calls are real and use the headers saved for purchases. The <b>subscribe</b> and <b>add-number</b> bodies below are first guesses from the diagram's variable names (not yet checked against Hera): try one of each on a test number and read the reply in the table below. A blank body blocks that call.</p>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_share">
            <div class="col-md-3"><label class="small text-muted mb-0">Mode</label><select class="form-select" name="share_mode"><option value="off" <?=$cfg['share_mode']==='off'?'selected':''?>>Off</option><option value="test" <?=$cfg['share_mode']==='test'?'selected':''?>>Test — simulated, nothing sent</option><option value="live" <?=$cfg['share_mode']==='live'?'selected':''?>>Live — real calls</option></select></div>
            <div class="col-md-7"><label class="small text-muted mb-0">ShareBundle address <small>(subscribe / addNumber / DataUsage / listNumber are added to it)</small></label><input class="form-control" name="share_base" value="<?=e($cfg['share_base'])?>"></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Success code</label><input class="form-control" name="share_ok_code" value="<?=e($cfg['share_ok_code'])?>"></div>
            <div class="col-12"><details><summary class="small">Request bodies <small class="text-muted">— placeholders: <code>{msisdn}</code> <code>{other_msisdn}</code> <code>{offer_code}</code> <code>{vendor}</code> <code>{price}</code> <code>{price_d}</code> <code>{txn}</code></small></summary>
                <div class="row g-2 mt-1">
                <?php foreach(['subscribe'=>'Subscribe (blank = blocked)','validate'=>'Validate a number (blank = skipped)','add'=>'Add the number (blank = blocked)','balance'=>'Balance','numbers'=>'My numbers'] as $op=>$lab):?>
                    <div class="col-md-6"><label class="small text-muted mb-0"><?=e($lab)?></label><textarea class="form-control code" rows="2" name="share_body_<?=e($op)?>"><?=e($cfg['share_body_'.$op])?></textarea></div>
                <?php endforeach;?></div></details></div>
            <div class="col-12"><button class="btn btn-primary">Save Shared Bundle settings</button></div>
        </form>
        <form method="post" class="row g-2 mt-2 align-items-end"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="share_ask">
            <div class="col-12"><b class="small">Ask Hera about an account</b> <small class="text-muted">— read-only: shows Hera's raw reply for balance or numbers, so the screens can be matched to it.</small></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="sa_op"><option value="balance">Balance (DataUsage)</option><option value="numbers">My numbers (listNumber)</option></select></div>
            <div class="col-md-5"><input class="form-control form-control-sm" name="sa_msisdn" placeholder="Phone number, e.g. 220…" value="<?=e((string)($_POST['sa_msisdn']??''))?>"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Ask Hera</button></div></form>
        <?php if($shareAsk):?><div class="mt-2 small"><div><b>Hera answered</b> (HTTP <?=e($shareAsk['code'])?>)<?=$shareAsk['error']!==''?': '.e($shareAsk['error']):''?> — shown to a customer as:</div><pre class="mb-1"><?=e($shareAsk['text'])?></pre><div>Raw reply:</div><pre class="mb-0"><?=e($shareAsk['raw'])?></pre></div><?php endif;?>
    </div>
    <div class="cardx mt-3"><h3>Try it without Mobius</h3>
        <p class="text-muted">Paste a sample request (JSON, XML or <code>name=value&amp;name=value</code>) — or press <b>Replay</b> on a captured one below. It runs the live logic with the settings above and shows what would be sent back. Nothing is stored and no session is kept.</p>
        <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="test">
            <div class="col-12"><textarea class="form-control code" rows="3" name="sample" placeholder='{"msisdn":"220xxxxxxx","sessionId":"abc123","text":"1"}'><?=e($testIn)?></textarea></div>
            <div class="col-md-3"><select class="form-select" name="tmode"><option value="proxy" <?=$testMode==='proxy'?'selected':''?>>as the PROXY menu</option><option value="ms_initiated" <?=$testMode==='ms_initiated'?'selected':''?>>as the MS_INITIATED menu</option></select></div>
            <div class="col-md-2"><button class="btn btn-outline-primary w-100">Run</button></div>
        </form>
        <?php if($test):?><div class="mt-3"><div class="small text-muted">Fields we read: <?php foreach($test['flat'] as $k=>$v):?><code><?=e($k)?></code>=<?=e(mb_strimwidth($v,0,40,'…'))?> · <?php endforeach; if(!$test['flat']):?><b class="text-danger">none — the sample couldn't be read</b><?php endif;?></div>
            <?php if(!empty($test['screen'])):?><div class="small text-muted mt-1">Short code <b><?=e($test['sc'])?></b>, replies so far: <b><?=e(implode(' → ',$test['replies'])?:'(none)')?></b>, <?=e($test['note'])?></div><?php endif;?>
            <pre class="mt-2 mb-0"><?=e($test['body'])?></pre><div class="small text-muted">Content type: <?=e($test['ctype'])?></div></div><?php endif;?>
    </div>

    <div class="cardx table-card mt-3"><div class="d-flex justify-content-between align-items-center px-3 pt-3"><h3 class="mb-0">Captured requests <small class="text-muted">(latest 25, kept 3 days)</small></h3>
        <form method="post" data-confirm="Delete all captured requests?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="clear"><button class="btn btn-sm btn-outline-secondary">Clear</button></form></div>
        <p class="text-muted px-3 mb-0 small">These contain customers' phone numbers — they are visible to admins only and deleted after 3 days.</p>
        <?php if(!$logs):?><p class="px-3 pb-3 pt-2 mb-0 text-muted">Nothing yet. Enable the endpoint, create the Mobius menu with the URL above, and dial the test short code.</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th>Time</th><th>Menu</th><th>From</th><th>Request</th><th>We replied</th><th class="text-end">ms</th><th></th></tr></thead><tbody>
        <?php foreach($logs as $lg): $q=json_decode((string)$lg['query_text'],true)?:[]; $fl=ussd_proxy_flatten('',(string)$lg['body_text'],$q,[]);?>
        <tr><td class="text-nowrap"><?=e($lg['created_at'])?></td><td><?=e($lg['mode'])?></td><td class="text-nowrap"><?=e($lg['remote_ip'])?></td>
            <td class="cell-full"><details><summary><?=e($lg['method'])?> <?php foreach(array_slice($fl,0,5,true) as $k=>$v):?><code><?=e($k)?></code>=<?=e(mb_strimwidth($v,0,24,'…'))?> <?php endforeach; if(!$fl):?>(empty)<?php endif;?></summary>
                <div class="small mt-1"><b>Query</b> <code><?=e($lg['query_text'])?></code></div><div class="small"><b>Headers</b> <code><?=e($lg['headers_text'])?></code></div><div class="small"><b>Body</b></div><pre class="mb-0"><?=e($lg['body_text'])?></pre></details></td>
            <td class="cell-full"><?=e(mb_strimwidth((string)$lg['response_text'],0,70,'…'))?><div class="small text-muted"><?=e($lg['note'])?></div></td><td class="text-end"><?=e($lg['ms'])?></td>
            <td><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="replay"><input type="hidden" name="id" value="<?=e($lg['id'])?>"><button class="btn btn-sm btn-outline-primary" title="Run this request through the live logic without sending anything">Replay</button></form></td></tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <?php layout_end(); exit;
}

if ($page==='ussd_sim') {
    require_perm('manage_ussd_menus');
    $known=menu_shortcodes(); $sc=trim((string)($_GET['sc']??'')); if($sc==='') $sc=$known[0]??'*9606*9090#';
    $withDraft=($_GET['draft']??'1')==='1';
    $prev=array_values(array_filter(explode(',',(string)($_GET['trail']??'')),fn($x)=>$x!==''));
    $reply=trim((string)($_GET['reply']??'')); $seed=trim((string)($_GET['seed']??'')); if($seed==='') $seed='sim'.mt_rand();
    $replies=$prev; if($reply!=='') $replies[]=$reply;
    if(count($replies)>30) $replies=array_slice($replies,-30);
    $fsim=(string)($_GET['fsim']??'success'); if(!in_array($fsim,['success','lowbal,success','lowbal,fail','lowbal','fail'],true)) $fsim='success';
    $scr=ussd_screen($sc,$replies,$withDraft?['active','draft']:['active'],null,['seed'=>$seed,'share'=>'ussd_share_sim','msisdn'=>'2200000000','flow_sim'=>$fsim]);
    $trail=implode(',',$replies);
    layout_start('USSD Simulator');
    ?>
    <div class="row g-3">
        <div class="col-lg-5"><div class="cardx">
            <h3><i class="fa-solid fa-mobile-screen me-2"></i>Try a menu</h3>
            <p class="text-muted">Shows exactly what a phone would show for a short code, walking the menu you built in the <a href="?page=ussd_menu">Menu Builder</a>. Nothing is sent to Mobius or any customer.</p>
            <form method="get" class="mb-3"><input type="hidden" name="page" value="ussd_sim">
                <label class="small text-muted mb-0">Short code</label>
                <div class="input-group mb-2"><input class="form-control" name="sc" value="<?=e($sc)?>" list="scList"><datalist id="scList"><?php foreach($known as $k):?><option value="<?=e($k)?>"><?php endforeach;?></datalist><button class="btn btn-outline-primary">Dial</button></div>
                <label class="small text-muted mb-0 mt-1">If a service flow asks Hera, pretend it says</label><select class="form-select form-select-sm mb-2" name="fsim" data-autosubmit><?php foreach(['success'=>'success','lowbal,success'=>'low balance, then success on the next call (e.g. a loan)','lowbal,fail'=>'low balance, then failure','lowbal'=>'low balance','fail'=>'failure'] as $fv=>$fl):?><option value="<?=e($fv)?>" <?=$fsim===$fv?'selected':''?>><?=e($fl)?></option><?php endforeach;?></select>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="draft" value="1" id="dr" <?=$withDraft?'checked':''?> data-autosubmit><label class="form-check-label small" for="dr">Include <b>draft</b> items (the live service will only show <b>active</b> ones)</label></div>
            </form>
            <?php if(!in_array($sc,$known,true)):?><div class="alert alert-info py-2 small">No menu exists for <b><?=e($sc)?></b> yet. <a href="?page=ussd_menu&new_short_code=<?=urlencode($sc)?>">Start one in the Menu Builder</a>.</div><?php endif;?>
            <div class="small text-muted">Replies so far: <b><?=$trail!==''?e(str_replace(',',' → ',$trail)):'(none)'?></b></div>
        </div></div>
        <div class="col-lg-7"><div class="cardx">
            <div class="ussd-phone">
                <div class="ussd-screen"><?=nl2br(e($scr['text']))?></div>
                <div class="ussd-meta <?=$scr['too_long']?'text-danger fw-semibold':'text-muted'?>"><?=$scr['chars']?> / <?=USSD_MAX_CHARS?> characters<?=$scr['too_long']?' — too long: some phones cut or reject it':''?></div>
                <?php if($scr['end']):?>
                    <?php if($scr['kind']==='purchase'):?><div class="alert alert-warning py-2 mt-2 mb-0 small">Simulator: nothing was bought. On the live short code this is where the purchase happens (purchase mode: <b><?=e(ussd_proxy_config()['purchase_mode'])?></b>).</div><?php endif;?>
                    <div class="alert alert-secondary py-2 mt-2 mb-2">Session ended (<?=e($scr['kind'])?>).</div>
                    <a class="btn btn-primary" href="?page=ussd_sim&sc=<?=urlencode($sc)?>&draft=<?=$withDraft?1:0?>&fsim=<?=urlencode($fsim)?>&seed=<?=mt_rand()?>"><i class="fa-solid fa-rotate-right me-1"></i>Dial again</a>
                <?php else:?>
                <form method="get" class="d-flex gap-2 mt-2"><input type="hidden" name="page" value="ussd_sim"><input type="hidden" name="sc" value="<?=e($sc)?>"><input type="hidden" name="draft" value="<?=$withDraft?1:0?>"><input type="hidden" name="trail" value="<?=e($trail)?>"><input type="hidden" name="seed" value="<?=e($seed)?>"><input type="hidden" name="fsim" value="<?=e($fsim)?>">
                    <input class="form-control" name="reply" inputmode="numeric" autocomplete="off" autofocus placeholder="Your reply, e.g. 1"><button class="btn btn-primary">Send</button>
                    <a class="btn btn-outline-secondary" href="?page=ussd_sim&sc=<?=urlencode($sc)?>&draft=<?=$withDraft?1:0?>&fsim=<?=urlencode($fsim)?>&seed=<?=mt_rand()?>">Restart</a></form>
                <?php endif;?>
            </div>
        </div></div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='ussd_quiz') {
    require_perm('manage_ussd_menus'); ussd_quiz_tables(); $db=portal_pdo();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $do=(string)($_POST['do']??'');
        if ($do==='save_quiz') { $k=save_quiz($_POST); flash('success','Quiz saved.'); redirect('?page=ussd_quiz&quiz='.urlencode($k)); }
        if ($do==='save_question') { $k=save_quiz_question($_POST); flash('success','Question saved.'); redirect('?page=ussd_quiz&quiz='.urlencode($k)); }
        if ($do==='toggle_question') { $k=toggle_quiz_question((int)($_POST['id']??0)); redirect('?page=ussd_quiz&quiz='.urlencode($k)); }
        if ($do==='import_questions') { $n=import_quiz_questions(trim((string)($_POST['quiz_key']??'')),(string)($_POST['lines']??'')); flash('success',$n.' questions added.'); redirect('?page=ussd_quiz&quiz='.urlencode(trim((string)($_POST['quiz_key']??'')))); }
    }
    $quizzes=$db->query('SELECT * FROM ussd_quizzes ORDER BY title')->fetchAll();
    $key=trim((string)($_GET['quiz']??'')); if($key===''&&$quizzes) $key=$quizzes[0]['quiz_key'];
    $quiz=null; foreach($quizzes as $q0) if($q0['quiz_key']===$key) $quiz=$q0;
    $qs=[]; $editQ=null; $plays=[]; $stats=null; $top=[];
    if($quiz){
        $st=$db->prepare('SELECT * FROM ussd_quiz_questions WHERE quiz_key=? ORDER BY status, id'); $st->execute([$key]); $qs=$st->fetchAll();
        if(isset($_GET['edit'])) foreach($qs as $q1) if((int)$q1['id']===(int)$_GET['edit']) $editQ=$q1;
        $st=$db->prepare('SELECT * FROM ussd_quiz_plays WHERE quiz_key=? ORDER BY id DESC LIMIT 15'); $st->execute([$key]); $plays=$st->fetchAll();
        $st=$db->prepare("SELECT COUNT(*) n, ROUND(AVG(score),2) avg_score, SUM(won) winners, COUNT(DISTINCT msisdn) players FROM ussd_quiz_plays WHERE quiz_key=? AND created_at >= NOW() - INTERVAL 7 DAY"); $st->execute([$key]); $stats=$st->fetch();
        $st=$db->prepare('SELECT msisdn, SUM(score) pts, COUNT(*) games FROM ussd_quiz_plays WHERE quiz_key=? AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY msisdn ORDER BY pts DESC LIMIT 10'); $st->execute([$key]); $top=$st->fetchAll();
    }
    $activeQ=count(array_filter($qs,fn($q)=>$q['status']==='active'));
    layout_start('USSD Quiz');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-circle-question me-2"></i>USSD Quiz</h3>
        <p class="text-muted">A quiz is a set of questions that customers play on a phone: each game picks a few at random, scores one point per right answer, and offers <i>Play again</i>. Put one into any menu with the <b>Quiz game</b> item type in the <a href="?page=ussd_menu">Menu Builder</a>. Try it in the <a href="?page=ussd_sim">Simulator</a> first.</p>
        <div class="d-flex flex-wrap gap-2 mb-2"><?php foreach($quizzes as $q0):?><a class="btn btn-sm <?=$q0['quiz_key']===$key?'btn-primary':'btn-outline-primary'?>" href="?page=ussd_quiz&quiz=<?=urlencode($q0['quiz_key'])?>"><?=e($q0['title'])?><?=$q0['status']==='inactive'?' (off)':''?></a><?php endforeach;?><?php if(!$quizzes):?><span class="text-muted">No quiz yet — create the first one below.</span><?php endif;?></div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-lg-4"><div class="cardx"><h3><?=$quiz?'Quiz settings':'New quiz'?></h3>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_quiz">
                <label class="small text-muted mb-0">Key <small>(letters, digits, - _ ; can't be changed later)</small></label><input class="form-control mb-2" name="quiz_key" value="<?=e($quiz['quiz_key']??'')?>" <?=$quiz?'readonly':''?> placeholder="comium_trivia">
                <label class="small text-muted mb-0">Title shown on the phone</label><input class="form-control mb-2" name="title" value="<?=e($quiz['title']??'')?>" placeholder="Comium Daily Quiz">
                <div class="row g-2"><div class="col-6"><label class="small text-muted mb-0">Questions per game</label><input type="number" min="3" max="10" class="form-control mb-2" name="per_game" value="<?=e($quiz['per_game']??5)?>"></div>
                <div class="col-6"><label class="small text-muted mb-0">Score to win</label><input type="number" min="1" max="10" class="form-control mb-2" name="win_score" value="<?=e($quiz['win_score']??4)?>"></div></div>
                <label class="small text-muted mb-0">Message to winners <small>(shown on the result screen; the prize itself is given by you — winners are listed here)</small></label><input class="form-control mb-2" name="win_text" value="<?=e($quiz['win_text']??'')?>" placeholder="You win! Watch for our SMS.">
                <label class="small text-muted mb-0">Status</label><select class="form-select mb-2" name="status"><option value="active" <?=($quiz['status']??'active')==='active'?'selected':''?>>Active</option><option value="inactive" <?=($quiz['status']??'')==='inactive'?'selected':''?>>Off</option></select>
                <button class="btn btn-primary w-100">Save quiz</button></form>
            <?php if($quiz):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=ussd_quiz&quiz=">+ New quiz</a><?php endif;?>
        </div></div>
        <div class="col-lg-8">
        <?php if($quiz):?>
            <div class="cardx"><h3><?=$editQ?'Edit question':'Add a question'?></h3>
                <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_question"><input type="hidden" name="quiz_key" value="<?=e($key)?>"><input type="hidden" name="id" value="<?=e($editQ['id']??'')?>">
                    <div class="col-12"><input class="form-control" name="question" value="<?=e($editQ['question']??'')?>" placeholder="Question, e.g. What is the capital of The Gambia?"></div>
                    <?php for($n=1;$n<=4;$n++):?><div class="col-md-6"><div class="input-group"><span class="input-group-text"><?=$n?></span><input class="form-control" name="opt<?=$n?>" value="<?=e($editQ['opt'.$n]??'')?>" placeholder="Answer <?=$n?><?=$n>2?' (optional)':''?>"></div></div><?php endfor;?>
                    <div class="col-md-4"><label class="small text-muted mb-0">Number of the right answer</label><input type="number" min="1" max="4" class="form-control" name="correct" value="<?=e($editQ['correct']??'')?>"></div>
                    <div class="col-md-8 d-flex align-items-end gap-2"><button class="btn btn-primary"><?=$editQ?'Save changes':'Add question'?></button><?php if($editQ):?><a class="btn btn-outline-secondary" href="?page=ussd_quiz&quiz=<?=urlencode($key)?>">Cancel</a><?php endif;?><small class="text-muted">A question with its answers must fit one phone screen (<?=USSD_QUIZ_BLOCK_MAX?> characters).</small></div>
                </form>
                <details class="mt-3"><summary class="small">Add many at once</summary>
                    <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="import_questions"><input type="hidden" name="quiz_key" value="<?=e($key)?>">
                        <p class="small text-muted mb-1">One per line: <code>Question | answer 1 | answer 2 | answer 3 | answer 4 | number of the right answer</code> (2 to 4 answers). Any bad line stops the whole import and says which.</p>
                        <textarea class="form-control code mb-2" rows="5" name="lines" placeholder="Capital of The Gambia? | Dakar | Banjul | Serrekunda | 2"></textarea><button class="btn btn-outline-primary btn-sm">Add these questions</button></form></details>
            </div>
            <div class="cardx mt-3"><h3>Questions <small class="text-muted"><?=$activeQ?> active of <?=count($qs)?><?=$activeQ<(int)$quiz['per_game']?' — need at least '.(int)$quiz['per_game'].' active for a full game':''?></small></h3>
                <?php if(!$qs):?><p class="text-muted mb-0">No questions yet.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>Question</th><th>Answers</th><th>Right</th><th></th></tr></thead><tbody>
                <?php foreach($qs as $q2):?><tr class="<?=$q2['status']==='inactive'?'text-muted':''?>"><td><?=e($q2['question'])?></td><td class="small"><?php foreach([1,2,3,4] as $n) if($q2['opt'.$n]!==null&&$q2['opt'.$n]!=='') echo $n.'. '.e($q2['opt'.$n]).'<br>';?></td><td><?=e($q2['correct'])?></td>
                    <td class="text-nowrap"><a class="btn btn-sm btn-outline-warning py-0" href="?page=ussd_quiz&quiz=<?=urlencode($key)?>&edit=<?=e($q2['id'])?>">Edit</a>
                    <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="toggle_question"><input type="hidden" name="id" value="<?=e($q2['id'])?>"><button class="btn btn-sm btn-outline-secondary py-0"><?=$q2['status']==='active'?'Turn off':'Turn on'?></button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
            </div>
            <div class="row g-3 mt-1"><div class="col-md-6"><div class="cardx"><h3>Last 7 days</h3>
                <?php if(!$stats||!$stats['n']):?><p class="text-muted mb-0">No games played yet.</p><?php else:?><p class="mb-1"><b><?=number_format((int)$stats['n'])?></b> games by <b><?=number_format((int)$stats['players'])?></b> players — average score <b><?=e($stats['avg_score'])?></b> — <b><?=number_format((int)$stats['winners'])?></b> winners.</p>
                <?php if($top):?><table class="table table-sm mb-0"><thead><tr><th>Top players</th><th>Points</th><th>Games</th></tr></thead><tbody><?php foreach($top as $t0):?><tr><td><?=e($t0['msisdn'])?></td><td><?=e($t0['pts'])?></td><td><?=e($t0['games'])?></td></tr><?php endforeach;?></tbody></table><?php endif;?><?php endif;?></div></div>
            <div class="col-md-6"><div class="cardx"><h3>Recent games</h3><?php if(!$plays):?><p class="text-muted mb-0">Nothing yet.</p><?php else:?><div class="table-scroll" style="max-height:260px;overflow-y:auto"><table class="table table-sm mb-0"><tbody><?php foreach($plays as $p0):?><tr><td class="text-nowrap small"><?=e($p0['created_at'])?></td><td><?=e($p0['msisdn'])?></td><td><?=e($p0['score'])?>/<?=e($p0['total'])?></td><td><?=$p0['won']?'<span class="badge bg-success">won</span>':''?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div></div>
        <?php else:?><div class="cardx"><p class="text-muted mb-0">Create a quiz on the left, then add questions here.</p></div><?php endif;?>
        </div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='status') {
    require_perm('manage_api_keys');
    $sections=system_status();
    $bad=0; $warn=0; foreach($sections as $sec) foreach($sec['rows'] as $r0){ if($r0['state']==='bad') $bad++; elseif($r0['state']==='warn') $warn++; }
    layout_start('System Status');
    ?>
    <div class="cardx">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h3 class="mb-0"><i class="fa-solid fa-server me-2"></i>System Status</h3>
            <span><?php if($bad):?><span class="badge bg-danger fs-6"><?=$bad?> problem<?=$bad===1?'':'s'?></span><?php elseif($warn):?><span class="badge bg-warning text-dark fs-6"><?=$warn?> to look at</span><?php else:?><span class="badge bg-success fs-6">everything is fine</span><?php endif;?>
            <a class="btn btn-sm btn-outline-primary ms-2" href="?page=status"><i class="fa-solid fa-rotate me-1"></i>Check again</a></span></div>
        <p class="text-muted small mb-0 mt-1">Read-only. Nothing here sends anything to a customer or changes data. For uptime monitors and Kubernetes the portal also answers <code>/health.php</code> (200 = database reachable).</p>
    </div>
    <?php foreach($sections as $sec):?>
    <div class="cardx mt-3"><h3><?=e($sec['title'])?></h3>
        <table class="table table-sm mb-0"><tbody><?php foreach($sec['rows'] as $r0):?><tr>
            <td style="width:1%" class="text-nowrap"><span class="badge <?=['ok'=>'bg-success','warn'=>'bg-warning text-dark','bad'=>'bg-danger','info'=>'bg-secondary'][$r0['state']]?>"><?=['ok'=>'OK','warn'=>'check','bad'=>'down','info'=>'info'][$r0['state']]?></span></td>
            <td class="fw-semibold text-nowrap"><?=e($r0['label'])?></td><td><?=e($r0['detail'])?></td>
            <td class="text-end"><?php if($r0['link']):?><a class="small" href="<?=e($r0['link'])?>">open</a><?php endif;?></td></tr><?php endforeach;?></tbody></table>
    </div>
    <?php endforeach; layout_end(); exit;
}

if ($page==='ussd_flows') {
    require_perm('manage_ussd_menus'); flow_tables();
    $flowUrl=fn(string $k,string $x='')=>'?page=ussd_flows&flow='.urlencode($k).$x;
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $do=(string)($_POST['do']??''); $fk=trim((string)($_POST['flow_key']??''));
        if ($do==='new_flow') { $k=save_flow($_POST); flash('success','Flow created. Add or change its steps below.'); redirect($flowUrl($k)); }
        if ($do==='flow_settings') { $k=save_flow($_POST); flash('success','Saved.'); redirect($flowUrl($k)); }
        if ($do==='save_step') { save_flow_step($fk,$_POST); flash('success','Step saved.'); redirect($flowUrl($fk)); }
        if ($do==='delete_step') { flow_delete_step($fk,trim((string)($_POST['step_key']??''))); flash('success','Step removed.'); redirect($flowUrl($fk)); }
        if ($do==='set_start') { flow_set_start($fk,trim((string)($_POST['step_key']??''))); flash('success','That is now the first step.'); redirect($flowUrl($fk)); }
        if ($do==='restore') { $k=flow_restore_version((int)($_POST['id']??0)); flash('success','That version is back.'); redirect($flowUrl($k)); }
        if ($do==='save_json') { flow_save_json($fk,(string)($_POST['json']??'')); flash('success','Flow replaced from JSON.'); redirect($flowUrl($fk)); }
        if ($do==='save_conn') { $k=save_flow_connection($_POST); flash('success','Connection "'.$k.'" saved.'); redirect('?page=ussd_flows'.($fk!==''?'&flow='.urlencode($fk):'').'#conns'); }
    }
    $flows=flow_list(); $fk=trim((string)($_GET['flow']??'')); $flow=$fk!==''?flow_get($fk):null;
    $conns=flow_connections(); $tplList=flow_templates(); $fromLog=null; $fromLogErr='';
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='from_log') { try { $fromLog=flow_body_from_log((string)($_POST['log_line']??'')); } catch(Throwable $e){ $fromLogErr=$e->getMessage(); } }
    $def=$flow?$flow['def']:null; $steps=$def['steps']??[]; $edit=null; $editKey=trim((string)($_GET['step']??'')); if($flow && $editKey!=='' && isset($steps[$editKey])) $edit=$steps[$editKey];
    $health=$flow?flow_validate($def):[]; $nErr=count(array_filter($health,fn($h)=>$h['level']==='error')); $nWarn=count(array_filter($health,fn($h)=>$h['level']==='warn'));
    $versions=[]; if($flow){ $st=portal_pdo()->prepare('SELECT id,reason,created_by,created_at FROM ussd_flow_versions WHERE flow_key=? ORDER BY id DESC LIMIT 12'); $st->execute([$fk]); $versions=$st->fetchAll(); }
    $recent=[]; if($flow){ $st=portal_pdo()->prepare('SELECT * FROM ussd_flow_calls WHERE flow_key=? ORDER BY id DESC LIMIT 10'); $st->execute([$fk]); $recent=$st->fetchAll(); }
    $typeName=['choices'=>'choices','offers'=>'offers','ask'=>'ask','lookup'=>'look up','confirm'=>'confirm','call'=>'call','message'=>'message']; $typeBadge=['choices'=>'bg-primary','offers'=>'bg-warning text-dark','ask'=>'bg-info text-dark','lookup'=>'bg-secondary','confirm'=>'bg-dark','call'=>'bg-danger','message'=>'bg-success'];
    $go=function(array $s):string{ $t=$s['type']??''; $a=[]; if($t==='choices') foreach($s['options']??[] as $o) $a[]='“'.($o['label']??'').'” → '.($o['next']??'?'); elseif($t==='confirm') { $a[]='yes → '.($s['yes']??'?'); $a[]='no → '.($s['no']??'?'); } elseif($t==='call') foreach(['success'=>'success','lowbal'=>'low balance','fail'=>'other failure'] as $k=>$l) $a[]=$l.' → '.($s['outcomes'][$k]??'?'); elseif(in_array($t,['offers','ask'],true)) $a[]='then → '.($s['next']??'?'); elseif($t==='lookup') $a[]='shows the reply, 0 = back'; elseif($t==='message') $a[]='ends the session'; return implode('  ·  ',$a); };
    $stepKeys=array_keys($steps);
    layout_start('Service Flows');
    ?>
    <div class="cardx">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h3 class="mb-0"><i class="fa-solid fa-diagram-project me-2"></i>Service Flows</h3></div>
        <p class="text-muted small mt-2 mb-2">A service flow is a special menu built from a few blocks — <b>choices</b>, <b>offers</b>, <b>ask</b> (the customer types something), <b>look up</b> (read something from Hera), <b>confirm</b>, <b>call</b> (the call that changes something; its <i>outcome</i> — success, low balance or other failure — decides the next step) and <b>message</b>. Build one here, try it right on this page, then put it in a menu with the <b>Service flow</b> item type in the <a href="?page=ussd_menu">Menu Builder</a>. Every flow starts in <b>Test</b>: calls are rehearsed, nothing is sent.</p>
        <div class="d-flex flex-wrap gap-2 mb-2"><?php foreach($flows as $f0):?><a class="btn btn-sm <?=$f0['flow_key']===$fk?'btn-primary':'btn-outline-primary'?>" href="<?=e($flowUrl($f0['flow_key']))?>"><?=e($f0['title'])?> <span class="badge <?=$f0['mode']==='live'?'bg-danger':'bg-secondary'?>"><?=e($f0['mode'])?></span><?=$f0['status']==='inactive'?' (off)':''?></a><?php endforeach;?><?php if(!$flows):?><span class="text-muted small">No flows yet.</span><?php endif;?></div>
        <details <?=$flows?'':'open'?>><summary class="small">New flow</summary>
            <form method="post" class="row g-2 mt-1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="new_flow">
                <div class="col-md-3"><input class="form-control" name="flow_key" placeholder="key, e.g. loan_offer" maxlength="40"></div>
                <div class="col-md-4"><input class="form-control" name="title" placeholder="Title, e.g. Purchase with loan" maxlength="80"></div>
                <div class="col-md-4"><select class="form-select" name="template"><option value="">Empty — I will add the steps</option><?php foreach($tplList as $tk=>$tp):?><option value="<?=e($tk)?>">Start from: <?=e($tp['title'])?></option><?php endforeach;?></select></div>
                <div class="col-md-1"><button class="btn btn-primary w-100">Create</button></div></form></details>
    </div>
    <?php if($flow):?>
    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <div class="cardx"><div class="d-flex justify-content-between align-items-center"><h3 class="mb-2">Check — <?=e($flow['title'])?></h3><span><?php if($nErr):?><span class="badge bg-danger"><?=$nErr?> to fix</span><?php elseif($nWarn):?><span class="badge bg-warning text-dark"><?=$nWarn?> to look at</span><?php else:?><span class="badge bg-success">all good</span><?php endif;?></span></div>
                <?php if(!$health):?><p class="small text-muted mb-0">Every step leads somewhere and every call is set up.</p><?php else:?><ul class="list-unstyled small mb-0"><?php foreach($health as $h):?><li class="py-1"><span class="badge <?=['error'=>'bg-danger','warn'=>'bg-warning text-dark','info'=>'bg-info text-dark'][$h['level']]?> me-1"><?=['error'=>'fix','warn'=>'check','info'=>'note'][$h['level']]?></span><?=e($h['msg'])?></li><?php endforeach;?></ul><?php endif;?></div>
            <div class="cardx mt-3"><h3>Steps</h3>
                <?php foreach($steps as $sk=>$s0): $isStart=($def['start']??'')===$sk;?>
                <div class="border rounded p-2 mb-2 <?=$isStart?'border-primary':''?>">
                    <div class="d-flex flex-wrap align-items-center gap-2"><code><?=e($sk)?></code><span class="badge <?=$typeBadge[$s0['type']]??'bg-secondary'?>"><?=e($typeName[$s0['type']]??$s0['type'])?></span><?php if($isStart):?><span class="badge bg-primary">first step</span><?php endif;?>
                        <span class="ms-auto text-nowrap"><a class="btn btn-sm btn-light border py-0 px-1" title="Edit" href="<?=e($flowUrl($fk,'&step='.urlencode($sk)))?>#stepform"><i class="fa-solid fa-pen"></i></a>
                        <?php if(!$isStart):?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="set_start"><input type="hidden" name="flow_key" value="<?=e($fk)?>"><input type="hidden" name="step_key" value="<?=e($sk)?>"><button class="btn btn-sm btn-light border py-0 px-1" title="Make this the first step"><i class="fa-solid fa-flag"></i></button></form>
                        <form method="post" class="d-inline" data-confirm="Remove the step <?=e($sk)?>? Steps that lead to it will point nowhere."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="delete_step"><input type="hidden" name="flow_key" value="<?=e($fk)?>"><input type="hidden" name="step_key" value="<?=e($sk)?>"><button class="btn btn-sm btn-light border py-0 px-1 text-danger" title="Remove"><i class="fa-solid fa-trash"></i></button></form><?php endif;?></span></div>
                    <?php if(!empty($s0['text'])):?><div class="small mt-1" style="white-space:pre-wrap"><?=e($s0['text'])?></div><?php endif;?>
                    <?php if($s0['type']==='offers'):?><div class="small text-muted">offers of: <?=e(implode('; ',(array)($s0['sub_categories']??[])))?></div><?php endif;?>
                    <?php if(in_array($s0['type'],['call','lookup'],true)):?><div class="small text-muted"><?=e(($s0['connection']??'')?:'(no connection yet)')?> / <?=e(($s0['path']??'')?:'(no path yet)')?></div><?php endif;?>
                    <div class="small text-muted"><?=e($go($s0))?></div></div>
                <?php endforeach;?>
            </div>
            <div class="cardx mt-3" id="stepform"><h3><?=$edit?'Change step “'.e($editKey).'”':'Add a step'?></h3>
                <datalist id="stepKeys"><?php foreach($stepKeys as $k0):?><option value="<?=e($k0)?>"><?php endforeach;?><option value="@exit"></datalist>
                <form method="post" class="stepform row g-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_step"><input type="hidden" name="flow_key" value="<?=e($fk)?>">
                    <div class="col-md-4"><label class="small text-muted mb-0">Step name <small>(letters, digits, _)</small></label><input class="form-control" name="step_key" value="<?=e($edit?$editKey:'')?>" <?=$edit?'readonly':''?> placeholder="e.g. confirm_buy" maxlength="30"></div>
                    <div class="col-md-8"><label class="small text-muted mb-0">What does this step do?</label><select class="form-select" name="type" id="step_type"><?php foreach(['choices'=>'Show choices (a list of options)','offers'=>'Show offers from the catalogue','ask'=>'Ask the customer to type something','lookup'=>'Look something up and show it','confirm'=>'Ask to confirm (yes / no)','call'=>'Make a call (changes something) — then branch on the result','message'=>'Show a message and end'] as $v=>$la):?><option value="<?=e($v)?>" <?=($edit['type']??'choices')===$v?'selected':''?>><?=e($la)?></option><?php endforeach;?></select></div>
                    <div class="fld t-choices t-offers t-ask t-lookup t-confirm t-message col-12"><label class="small text-muted mb-0">Screen text <small>(you can use {offer.name} {offer.price_d} {other_local} {result.…} {reply_text} …)</small></label><textarea class="form-control" rows="2" name="text" maxlength="400"><?=e($edit['text']??'')?></textarea></div>
                    <div class="fld t-choices col-12"><label class="small text-muted mb-0">Options — one per line: <code>Label -&gt; step_name</code> (<code>@exit</code> leaves the flow)</label><textarea class="form-control code" rows="3" name="options" placeholder="Purchase offer -&gt; pick&#10;Check balance -&gt; balance"><?php foreach(($edit['options']??[]) as $o0) echo e(($o0['label']??'').' -> '.($o0['next']??''))."\n";?></textarea></div>
                    <div class="fld t-offers col-md-8"><label class="small text-muted mb-0">Sub-categories <small>(one per line or separated by ;)</small></label><textarea class="form-control" rows="2" name="sub_categories"><?=e(implode("\n",(array)($edit['sub_categories']??[])))?></textarea></div>
                    <div class="fld t-offers col-md-4"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="auto_single" value="1" id="as" <?=(!$edit||($edit['auto_single']??true))?'checked':''?>><label class="form-check-label small" for="as">If only one offer, choose it for the customer</label></div></div>
                    <div class="fld t-ask col-md-4"><label class="small text-muted mb-0">Expecting</label><select class="form-select" name="kind"><?php foreach(['phone'=>'a phone number','digits'=>'digits only','text'=>'a short text'] as $v=>$la):?><option value="<?=e($v)?>" <?=($edit['kind']??'phone')===$v?'selected':''?>><?=e($la)?></option><?php endforeach;?></select></div>
                    <div class="fld t-ask col-md-4"><label class="small text-muted mb-0">Keep it as <small>({name}, {name_local}, {name_raw})</small></label><input class="form-control" name="var" value="<?=e($edit['var']??'other')?>" maxlength="21"></div>
                    <div class="fld t-offers t-ask col-md-4"><label class="small text-muted mb-0">Then go to</label><input class="form-control" name="next" list="stepKeys" value="<?=e($edit['next']??'')?>"></div>
                    <div class="fld t-confirm col-md-6"><label class="small text-muted mb-0">If they answer 1 (yes) go to</label><input class="form-control" name="yes" list="stepKeys" value="<?=e($edit['yes']??'')?>"></div>
                    <div class="fld t-confirm col-md-6"><label class="small text-muted mb-0">If they answer 0 or 2 (no) go to</label><input class="form-control" name="no" list="stepKeys" value="<?=e($edit['no']??'@exit')?>"></div>
                    <div class="fld t-lookup t-call col-md-5"><label class="small text-muted mb-0">Connection <small>(below)</small></label><select class="form-select" name="connection"><option value="">—</option><?php foreach($conns as $c0):?><option value="<?=e($c0['conn_key'])?>" <?=($edit['connection']??'')===$c0['conn_key']?'selected':''?>><?=e($c0['title'])?> (<?=e($c0['conn_key'])?>)</option><?php endforeach;?></select></div>
                    <div class="fld t-lookup t-call col-md-7"><label class="small text-muted mb-0">Path added to the connection's address</label><input class="form-control" name="path" value="<?=e($edit['path']??'')?>" placeholder="e.g. subscribe"></div>
                    <div class="fld t-lookup t-call col-12"><label class="small text-muted mb-0">Request body <small>(JSON; placeholders like {msisdn} {offer.code} {other_msisdn} {imsi} {local_address_json}. Paste a Mobius log line under “Connections” to get one.)</small></label><textarea class="form-control code" rows="4" name="body"><?=e($edit['body']??'')?></textarea></div>
                    <div class="fld t-call col-md-4"><label class="small text-muted mb-0">On success go to</label><input class="form-control" name="on_success" list="stepKeys" value="<?=e($edit['outcomes']['success']??'')?>"></div>
                    <div class="fld t-call col-md-4"><label class="small text-muted mb-0">On low balance go to</label><input class="form-control" name="on_lowbal" list="stepKeys" value="<?=e($edit['outcomes']['lowbal']??'')?>"></div>
                    <div class="fld t-call col-md-4"><label class="small text-muted mb-0">On any other failure go to</label><input class="form-control" name="on_fail" list="stepKeys" value="<?=e($edit['outcomes']['fail']??'')?>"></div>
                    <div class="col-12 d-flex gap-2 align-items-center"><button class="btn btn-primary"><?=$edit?'Save changes':'Add step'?></button><?php if($edit):?><a class="btn btn-outline-secondary" href="<?=e($flowUrl($fk))?>">Cancel</a><?php endif;?><div class="form-check ms-2"><input class="form-check-input" type="checkbox" name="make_start" value="1" id="ms"><label class="form-check-label small" for="ms">make it the first step</label></div></div>
                </form>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="cardx"><h3>Settings</h3>
                <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="flow_settings"><input type="hidden" name="flow_key" value="<?=e($fk)?>">
                    <label class="small text-muted mb-0">Title</label><input class="form-control mb-2" name="title" value="<?=e($flow['title'])?>">
                    <div class="row g-2"><div class="col-6"><label class="small text-muted mb-0">Mode</label><select class="form-select mb-2" name="mode"><option value="test" <?=$flow['mode']==='test'?'selected':''?>>Test — calls are rehearsed</option><option value="live" <?=$flow['mode']==='live'?'selected':''?>>Live — calls are real</option></select></div>
                    <div class="col-6"><label class="small text-muted mb-0">In Test, every call ends as</label><select class="form-select mb-2" name="test_outcome"><?php foreach(['success'=>'success','lowbal'=>'low balance','fail'=>'failure'] as $v=>$la):?><option value="<?=e($v)?>" <?=$flow['test_outcome']===$v?'selected':''?>><?=e($la)?></option><?php endforeach;?></select></div></div>
                    <label class="small text-muted mb-0">Status</label><select class="form-select mb-2" name="status"><option value="active" <?=$flow['status']==='active'?'selected':''?>>Active</option><option value="inactive" <?=$flow['status']==='inactive'?'selected':''?>>Off</option></select>
                    <button class="btn btn-primary w-100">Save settings</button></form>
                <p class="small text-muted mt-2 mb-0">To put it in a menu: <a href="?page=ussd_menu">Menu Builder</a> → add an item → <b>Service flow</b> → <code><?=e($fk)?></code>.</p></div>
            <?php
            $tr=array_values(array_filter(explode(',',(string)($_GET['trail']??'')),fn($x)=>$x!=='')); $rp=trim((string)($_GET['reply']??'')); if($rp!=='') $tr[]=$rp; $tr=array_slice($tr,-30);
            $tsim=(string)($_GET['fsim']??'success'); if(!in_array($tsim,['success','lowbal,success','lowbal,fail','lowbal','fail'],true)) $tsim='success';
            $tscr=flow_screen($def,$tr,['call'=>flow_sim_call_hook($tsim),'lookup'=>'flow_sim_lookup','msisdn'=>'220866000001']); $tshow=($tscr['text']??'') !== '' ? (string)$tscr['text'] : (isset($tscr['exit_at'])?'(back to the menu)':'(nothing to show)'); $tend=!empty($tscr['end'])||isset($tscr['exit_at']);
            ?>
            <div class="cardx mt-3"><h3>Try it</h3>
                <form method="get" class="mb-2"><input type="hidden" name="page" value="ussd_flows"><input type="hidden" name="flow" value="<?=e($fk)?>"><input type="hidden" name="trail" value="<?=e(implode(',',array_slice($tr,0,max(0,count($tr)-0))))?>">
                    <label class="small text-muted mb-0">If a call is made, pretend Hera says</label><select class="form-select form-select-sm" name="fsim" data-autosubmit><?php foreach(['success'=>'success','lowbal,success'=>'low balance, then success on the next call (e.g. a loan)','lowbal,fail'=>'low balance, then failure','lowbal'=>'low balance','fail'=>'failure'] as $fv=>$fl):?><option value="<?=e($fv)?>" <?=$tsim===$fv?'selected':''?>><?=e($fl)?></option><?php endforeach;?></select></form>
                <div class="ussd-phone"><div class="ussd-screen"><?=nl2br(e($tshow))?></div>
                    <?php if($tend):?><div class="alert alert-secondary py-2 mt-2 mb-2">Session ended.</div><a class="btn btn-primary" href="<?=e($flowUrl($fk,'&fsim='.urlencode($tsim)))?>">Dial again</a>
                    <?php else:?><form method="get" class="d-flex gap-2 mt-2"><input type="hidden" name="page" value="ussd_flows"><input type="hidden" name="flow" value="<?=e($fk)?>"><input type="hidden" name="trail" value="<?=e(implode(',',$tr))?>"><input type="hidden" name="fsim" value="<?=e($tsim)?>"><input class="form-control" name="reply" autocomplete="off" autofocus placeholder="Your reply"><button class="btn btn-primary">Send</button><a class="btn btn-outline-secondary" href="<?=e($flowUrl($fk,'&fsim='.urlencode($tsim)))?>">Restart</a></form><?php endif;?></div>
                <div class="small text-muted mt-2">Replies so far: <b><?=$tr?e(implode(' → ',$tr)):'(none)'?></b>. Calls and look-ups here are always simulated.</div></div>
            <?php if($recent):?><div class="cardx mt-3"><h3>Recent calls</h3><div class="table-scroll" style="max-height:240px;overflow-y:auto"><table class="table table-sm mb-0 small"><tbody><?php foreach($recent as $r0):?><tr><td class="text-nowrap"><?=e($r0['created_at'])?></td><td><?=e($r0['msisdn'])?></td><td><?=e($r0['step_key'])?></td><td><span class="badge <?=['success'=>'bg-success','lowbal'=>'bg-warning text-dark','fail'=>'bg-danger'][$r0['outcome']??'']??'bg-secondary'?>"><?=e($r0['outcome']??'…')?></span> <span class="text-muted"><?=e($r0['mode'])?></span></td><td title="<?=e((string)$r0['response'])?>"><i class="fa-solid fa-circle-info text-muted"></i></td></tr><?php endforeach;?></tbody></table></div></div><?php endif;?>
            <div class="cardx mt-3"><h3>History</h3>
                <?php if(!$versions):?><p class="small text-muted mb-0">Every change is kept here.</p><?php else:?><div class="table-scroll" style="max-height:220px;overflow-y:auto"><table class="table table-sm mb-0 small"><tbody><?php foreach($versions as $v):?><tr><td class="text-nowrap"><?=e(date('M j H:i',strtotime($v['created_at'])))?></td><td><?=e($v['created_by']??'')?></td><td><?=e($v['reason'])?></td><td class="text-end"><form method="post" data-confirm="Bring this version back?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="restore"><input type="hidden" name="id" value="<?=e($v['id'])?>"><button class="btn btn-sm btn-outline-secondary py-0">Restore</button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
                <details class="mt-2"><summary class="small">Advanced: the whole flow as JSON</summary><form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_json"><input type="hidden" name="flow_key" value="<?=e($fk)?>"><textarea class="form-control code mb-2" rows="8" name="json"><?=e(json_encode($def,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></textarea><button class="btn btn-outline-primary btn-sm">Replace the flow with this JSON</button></form></details></div>
        </div>
    </div>
    <?php endif;?>
    <div class="cardx mt-3" id="conns"><h3>Connections <small class="text-muted">— where a call goes</small></h3>
        <p class="small text-muted">A connection is an address plus its headers (kept encrypted, never shown again), what result code means success, and which words mean "low balance". A call step picks a connection and adds its own path.</p>
        <?php if($conns):?><table class="table table-sm small"><thead><tr><th>Key</th><th>Title</th><th>Address</th><th>Headers</th><th>Success code</th></tr></thead><tbody><?php foreach($conns as $c0):?><tr><td><code><?=e($c0['conn_key'])?></code></td><td><?=e($c0['title'])?></td><td><?=e($c0['base_url'])?></td><td><?=$c0['has_headers']?'saved':'none'?></td><td><?=e($c0['ok_code'])?></td></tr><?php endforeach;?></tbody></table><?php endif;?>
        <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_conn"><input type="hidden" name="flow_key" value="<?=e($fk)?>">
            <div class="col-md-3"><input class="form-control" name="conn_key" placeholder="key, e.g. hera_loans" value="<?=e((string)($_POST['conn_key']??''))?>"></div><div class="col-md-3"><input class="form-control" name="title" placeholder="Title"></div>
            <div class="col-md-6"><input class="form-control" name="base_url" placeholder="Address, e.g. https://vas-testing.comium.gm/hera/prepaid/" value="<?=e($fromLog['address']??'')?>"></div>
            <div class="col-md-6"><textarea class="form-control code" rows="2" name="headers" placeholder="Headers, one per line:  X-USERNAME: USSD  (leave empty to keep the saved ones)"></textarea></div>
            <div class="col-md-2"><input class="form-control" name="ok_code" value="0" title="Result code that means success"></div><div class="col-md-2"><input class="form-control" name="timeout" value="8" title="Timeout in seconds"></div>
            <div class="col-md-12"><input class="form-control" name="lowbal" value="insufficient,low balance,not enough" title="Words that mean low balance"></div>
            <div class="col-12"><button class="btn btn-outline-primary btn-sm">Save connection</button> <small class="text-muted">saving a key that exists updates it</small></div></form>
        <hr>
        <h3 class="fs-6">Get a request body from a Mobius log line</h3>
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="from_log"><input type="hidden" name="flow_key" value="<?=e($fk)?>">
            <textarea class="form-control code mb-2" rows="3" name="log_line" placeholder="Paste a line like:  … Sending request :{&quot;callID&quot;:… to application:https://…  (the keys in it are ignored)"><?=e((string)($_POST['log_line']??''))?></textarea><button class="btn btn-outline-secondary btn-sm">Make the body</button></form>
        <?php if($fromLogErr):?><div class="alert alert-warning py-2 mt-2 small mb-0"><?=e($fromLogErr)?></div><?php endif;?>
        <?php if($fromLog):?><div class="mt-2 small"><div><b>Address</b>: <code><?=e($fromLog['address'])?></code> · <b>path</b>: <code><?=e($fromLog['path'])?></code> (the address is pre-filled in the form above)</div><div class="mt-1"><b>Request body</b> — copy into a call or look-up step:</div><pre class="mb-1" style="white-space:pre-wrap"><?=e($fromLog['body'])?></pre><?php foreach($fromLog['notes'] as $n0):?><div class="text-muted">• <?=e($n0)?></div><?php endforeach;?></div><?php endif;?>
    </div>
    <?php layout_end(); exit;
}

if ($page==='ussd_menu') {
    require_perm('view_tables'); menu_versions_table();
    $canEdit = can('manage_ussd_menus');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_perm('manage_ussd_menus');
        $do=(string)($_POST['do']??''); $go=fn(string $sc)=>redirect('?page=ussd_menu&short_code='.urlencode($sc));
        if ($do==='import_menu') {
            if (empty($_POST['confirm_replace'])) throw new RuntimeException('Tick the box to confirm the current menu is replaced.');
            $res=import_menu_json((string)($_POST['import_short_code']??''),(string)($_POST['import_json']??''));
            flash('success','Menu imported: '.$res['nodes'].' items.'.($res['archived']?' The previous '.$res['archived'].' items were kept, inactive, as an archived copy.':''));
            $go(trim((string)($_POST['import_short_code']??'')));
        }
        if ($do==='move') $go(menu_move_node((int)($_POST['id']??0),(string)($_POST['dir']??'')));
        if ($do==='duplicate') { $sc=menu_duplicate_node((int)($_POST['id']??0)); flash('success','Copied as a Draft, just below the original. Edit it, then turn it on.'); $go($sc); }
        if ($do==='set_status') { $sc=menu_set_status((int)($_POST['id']??0),(string)($_POST['status']??''),!empty($_POST['branch'])); $go($sc); }
        if ($do==='activate_drafts') { $sc=trim((string)($_POST['short_code']??'')); $n=menu_activate_drafts($sc); flash('success',$n.' draft item'.($n===1?'':'s').' turned on — customers can see '.($n===1?'it':'them').' now.'); $go($sc); }
        if ($do==='quick_add') { $sc=trim((string)($_POST['short_code']??'')); $n=menu_quick_add($sc,($_POST['parent_id']??'')!==''?(int)$_POST['parent_id']:null,(string)($_POST['lines']??''),(string)($_POST['status']??'draft')); flash('success',$n.' item'.($n===1?'':'s').' added.'); $go($sc); }
        if ($do==='copy_menu') { $to=trim((string)($_POST['to']??'')); $n=menu_copy_to(trim((string)($_POST['short_code']??'')),$to,!empty($_POST['confirm_replace'])); flash('success','Copied '.$n.' items to '.$to.' as Drafts. Open it, check it, then "Activate all drafts".'); $go($to); }
        if ($do==='restore') { $sc=menu_restore_version((int)($_POST['id']??0)); flash('success','That saved version is back. The menu it replaced is kept (inactive) as an archive.'); $go($sc); }
        save_menu_node($_POST['data']??[], !empty($_POST['id'])?(int)$_POST['id']:null);
        flash('success','Saved.');
        $go((string)($_POST['data']['short_code']??''));
    }
    $shortCode = trim((string)($_GET['short_code'] ?? ''));
    if ($shortCode === '') $shortCode = trim((string)($_GET['new_short_code'] ?? ''));
    $known = menu_shortcodes();
    if ($shortCode==='' && $known) $shortCode = $known[0];
    $edit=null; if(isset($_GET['id'])){ $edit=menu_node((int)$_GET['id']); }
    $addParent = $edit ? ($edit['parent_id']??'') : ($_GET['parent']??'');
    $tree = $shortCode!=='' ? menu_tree($shortCode) : [];
    $flatNodes = $shortCode!=='' ? menu_nodes_flat($shortCode) : [];
    $labelOf=[]; foreach($flatNodes as $fn0) $labelOf[(int)$fn0['id']]=$fn0;
    $pathLabel=function(int $id) use (&$pathLabel,$labelOf){ $n=$labelOf[$id]??null; if(!$n) return ''; $pp=$n['parent_id']!==null?$pathLabel((int)$n['parent_id']):''; return ($pp!==''?$pp.' › ':'').$n['prompt_text']; };
    $offers = table_exists(current_schema(),'vas_offers') ? pdo(current_schema())->query("SELECT offer_code, name FROM vas_offers WHERE ".OFFER_ACTIVE_SQL." ORDER BY name")->fetchAll() : [];
    $subCats=[]; try { if(table_exists(USSD_OFFER_SCHEMA,'vas_offers')) $subCats=pdo(USSD_OFFER_SCHEMA)->query("SELECT sub_category, SUM(".OFFER_ACTIVE_SQL.") act FROM vas_offers WHERE sub_category IS NOT NULL AND sub_category<>'' AND (deleted_at IS NULL OR deleted_at='') GROUP BY sub_category HAVING act>0 ORDER BY sub_category")->fetchAll(); } catch(Throwable $e){}
    ussd_quiz_tables(); $quizOpts=portal_pdo()->query('SELECT quiz_key,title FROM ussd_quizzes ORDER BY title')->fetchAll(); $flowOpts=flow_list();
    $health = ($shortCode!=='' && $flatNodes) ? menu_health($shortCode) : [];
    $nErr=count(array_filter($health,fn($h)=>$h['level']==='error')); $nWarn=count(array_filter($health,fn($h)=>$h['level']==='warn'));
    $versions=[]; if($shortCode!==''){ $st=portal_pdo()->prepare('SELECT id,reason,nodes,created_by,created_at FROM ussd_menu_versions WHERE short_code=? ORDER BY id DESC LIMIT 12'); $st->execute([$shortCode]); $versions=$st->fetchAll(); }
    $drafts=count(array_filter($flatNodes,fn($n)=>$n['status']==='draft'));
    layout_start('USSD Menu Builder');
    ?>
    <div class="cardx">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h3 class="mb-0"><i class="fa-solid fa-sitemap me-2"></i>USSD Menu Builder</h3>
            <?php if($shortCode!==''):?><div class="d-flex gap-2"><a class="btn btn-sm btn-primary" href="?page=ussd_sim&sc=<?=urlencode($shortCode)?>&draft=1"><i class="fa-solid fa-mobile-screen me-1"></i>Try it</a><a class="btn btn-sm btn-outline-secondary" href="?page=ussd_menu_export&short_code=<?=urlencode($shortCode)?>">Export JSON</a></div><?php endif;?>
        </div>
        <form method="get" class="row g-2 mt-1"><input type="hidden" name="page" value="ussd_menu">
            <div class="col-md-4"><select class="form-select" name="short_code" data-autosubmit><?php foreach($known as $sc):?><option value="<?=e($sc)?>" <?=$shortCode===$sc?'selected':''?>><?=e($sc)?></option><?php endforeach;?></select></div>
            <div class="col-md-5"><input class="form-control" name="new_short_code" placeholder="Or start a new short code, e.g. *123#"></div>
            <div class="col-md-3"><button class="btn btn-outline-primary w-100">Switch / Start</button></div>
        </form>
        <details class="mt-2"><summary class="small">How building a menu works (30 seconds)</summary>
            <div class="small mt-2">
                <ol class="mb-2"><li><b>Add items</b> — use <b>Quick add</b> to type a whole list at once, or the form for one. New items start as <b>Draft</b>: only you see them in the Simulator.</li>
                <li><b>Check</b> — the <b>Menu check</b> below tells you what would break on a phone (too long, empty list, offer not available…). <b>Try it</b> opens the Simulator.</li>
                <li><b>Turn on</b> — <b>Activate all drafts</b> (or the power button on one item). Customers see Active items straight away, nothing else to restart.</li>
                <li><b>Changed your mind?</b> Every change is kept in <b>History</b> — press Restore on any earlier version.</li></ol>
                <b>Item types:</b> <b>Submenu</b> (a list of more items) · <b>Catalogue list</b> (offers from the catalogue, kept up to date by itself) · <b>Offer</b> (sells one offer) · <b>End</b> (just a message) · <b>Buy for another number</b> · <b>Shared Bundle</b> · <b>Quiz</b>. The <b>label</b> is the line in the parent's menu; the optional <b>screen text</b> is what shows when the customer opens it. The code must also exist as a PROXY menu in Mobius, pointing at the portal address on the USSD Proxy page.
            </div></details>
    </div>
    <?php if($shortCode!==''):?>
    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <div class="cardx">
                <div class="d-flex justify-content-between align-items-center"><h3 class="mb-2">Menu check — <?=e($shortCode)?></h3>
                    <span><?php if(!$flatNodes):?><span class="badge bg-secondary">empty</span><?php elseif($nErr):?><span class="badge bg-danger"><?=$nErr?> to fix</span><?php elseif($nWarn):?><span class="badge bg-warning text-dark"><?=$nWarn?> to look at</span><?php else:?><span class="badge bg-success">all good</span><?php endif;?></span></div>
                <?php if(!$health):?><p class="text-muted mb-0 small">Nothing to check yet.</p><?php else:?>
                <ul class="list-unstyled mb-0 small"><?php foreach($health as $h):?><li class="py-1"><span class="badge <?=['error'=>'bg-danger','warn'=>'bg-warning text-dark','info'=>'bg-info text-dark'][$h['level']]?> me-1"><?=['error'=>'fix','warn'=>'check','info'=>'note'][$h['level']]?></span><?=e($h['msg'])?><?php if($h['node']):?> <a href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>&id=<?=e($h['node'])?>">open</a><?php endif;?></li><?php endforeach;?></ul><?php endif;?>
                <?php if($canEdit && $drafts):?><form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="activate_drafts"><input type="hidden" name="short_code" value="<?=e($shortCode)?>"><button class="btn btn-sm btn-success"><i class="fa-solid fa-power-off me-1"></i>Activate all <?=$drafts?> draft<?=$drafts===1?'':'s'?></button> <small class="text-muted">turns them on for customers</small></form><?php endif;?>
            </div>
            <div class="cardx mt-3"><h3>Menu — <?=e($shortCode)?></h3>
                <?php if(!$tree):?><p class="text-muted mb-0">No items yet. Use <b>Quick add</b> (right) to type the first list.</p><?php else:?>
                <?php
                $badgeOf=['flow'=>'bg-primary','menu'=>'bg-primary','offer'=>'bg-success','catalog'=>'bg-warning text-dark','recipient'=>'bg-dark','quiz'=>'bg-danger','sharedbundle'=>'bg-success','action'=>'bg-info text-dark','end'=>'bg-secondary'];
                $nameOf=['flow'=>'service flow','menu'=>'submenu','offer'=>'offer','catalog'=>'catalogue list','recipient'=>'buy for other','quiz'=>'quiz','sharedbundle'=>'shared bundle','action'=>'action','end'=>'message'];
                $btn=function(string $do,int $id,string $icon,string $title,array $extra=[]) use ($canEdit){ if(!$canEdit) return ''; $h='<form method="post" class="d-inline"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="do" value="'.e($do).'"><input type="hidden" name="id" value="'.$id.'">'; foreach($extra as $k=>$v) $h.='<input type="hidden" name="'.e($k).'" value="'.e($v).'">'; return $h.'<button class="btn btn-sm btn-light border py-0 px-1" title="'.e($title).'"><i class="fa-solid '.$icon.'"></i></button></form>'; };
                $renderTree = function($nodes, $depth=0) use (&$renderTree, $shortCode, $badgeOf, $nameOf, $btn, $canEdit) { $last=count($nodes)-1; foreach($nodes as $i=>$n): $id=(int)$n['id']; $on=$n['status']==='active'; ?>
                    <div style="margin-left:<?=$depth*22?>px" class="menu-row d-flex align-items-center gap-2 py-1 <?=$on?'':'text-muted'?>">
                        <span class="badge <?=$badgeOf[$n['node_type']]??'bg-secondary'?>" style="min-width:5.2rem"><?=e($nameOf[$n['node_type']]??$n['node_type'])?></span>
                        <span class="flex-grow-1"><?=e($n['prompt_text'])?>
                            <?php if($n['node_type']==='offer'):?><small class="text-muted">(<?=e($n['offer_code'])?>)</small><?php elseif($n['node_type']==='catalog'||$n['node_type']==='sharedbundle'):?><small class="text-muted">[<?=e(str_replace("\n",', ',(string)($n['catalog_filter']??'')))?>]</small><?php elseif($n['node_type']==='quiz' || $n['node_type']==='flow'):?><small class="text-muted">(<?=e($n['offer_code'])?>)</small><?php endif;?>
                            <?php if(!$on):?><span class="badge status-<?=e($n['status'])?>"><?=e($n['status'])?></span><?php endif;?></span>
                        <span class="text-nowrap"><?php if($canEdit):?><?=$btn('move',$id,'fa-arrow-up','Move up',['dir'=>'up'])?><?=$btn('move',$id,'fa-arrow-down','Move down',['dir'=>'down'])?><?php endif;?>
                            <a class="btn btn-sm btn-light border py-0 px-1" title="Edit" href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>&id=<?=$id?>#nodeform"><i class="fa-solid fa-pen"></i></a>
                            <?php if($canEdit):?><?php if($n['node_type']==='menu'):?><a class="btn btn-sm btn-light border py-0 px-1" title="Add an item inside" href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>&parent=<?=$id?>#nodeform"><i class="fa-solid fa-plus"></i></a><?php endif;?><?=$btn('duplicate',$id,'fa-copy','Copy this item (and what is inside it) as a draft')?><?=$btn('set_status',$id,'fa-power-off',$on?'Turn off':'Turn on',['status'=>$on?'inactive':'active'])?><?php endif;?></span>
                    </div>
                    <?php if(!empty($n['children'])) $renderTree($n['children'],$depth+1); endforeach; }; $renderTree($tree); ?>
                <?php endif;?>
            </div>
            <div class="cardx mt-3"><div class="d-flex justify-content-between align-items-center"><h3>What a customer sees</h3></div>
                <?php if(!$tree):?><p class="text-muted mb-0">Nothing to preview yet.</p><?php else:?><pre class="mb-0"><?=render_menu_preview($tree)?></pre><?php endif;?>
            </div>
        </div>
        <div class="col-lg-5">
            <?php if($canEdit):?>
            <div class="cardx" id="nodeform"><h3><?= $edit?'Edit item':'Add one item' ?></h3>
                <form method="post" class="nodeform"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><input type="hidden" name="data[short_code]" value="<?=e($shortCode)?>">
                    <label class="small text-muted mb-0">Where it goes</label><select class="form-select mb-2" name="data[parent_id]"><option value="">The first menu (top level)</option><?php foreach($flatNodes as $n): if($edit && (int)$n['id']===(int)$edit['id']) continue; if($n['node_type']!=='menu') continue; ?><option value="<?=e($n['id'])?>" <?=(string)$addParent===(string)$n['id']?'selected':''?>>inside: <?=e($pathLabel((int)$n['id']))?></option><?php endforeach;?></select>
                    <label class="small text-muted mb-0">Label <small>(the line in the menu)</small></label><input class="form-control mb-2" name="data[prompt_text]" value="<?=e($edit['prompt_text']??'')?>" placeholder="e.g. Sakan Bundles" maxlength="120">
                    <label class="small text-muted mb-0">What is it?</label><select class="form-select mb-1" name="data[node_type]" id="node_type"><?php foreach(['menu'=>'A list of more items (submenu)','catalog'=>'Offers from the catalogue (kept up to date by itself)','offer'=>'One offer to buy','end'=>'Just a message (ends the session)','recipient'=>'Buy for another number','sharedbundle'=>'Shared Bundle service (Seddo)','flow'=>'Service flow (a special menu built on the Service Flows page)','quiz'=>'Quiz game','action'=>'Action (not connected yet)'] as $v=>$la):?><option value="<?=e($v)?>" <?=($edit['node_type']??'menu')===$v?'selected':''?>><?=e($la)?></option><?php endforeach;?></select>
                    <div class="fld help t-menu small text-muted mb-2">Opens a screen listing the Active items you put inside it.</div>
                    <div class="fld help t-catalog small text-muted mb-2">Lists the <b>Active</b> offers of the sub-categories you choose, cheapest first, 5 per screen. Add or switch off an offer in the catalogue and the menu follows.</div>
                    <div class="fld help t-offer small text-muted mb-2">Shows the offer's name and price and asks <i>1. Confirm / 2. Cancel</i> before buying.</div>
                    <div class="fld help t-end small text-muted mb-2">Shows its text and ends the session — good for help or "coming soon" screens.</div>
                    <div class="fld help t-recipient small text-muted mb-2">Asks for the other person's number, then shows the main menu again so they can buy for them.</div>
                    <div class="fld help t-sharedbundle small text-muted mb-2">The whole Shared Bundle flow: buy, add a sharing number, balance, numbers. Set it up on the <a href="?page=ussd_proxy">USSD Proxy</a> page.</div>
                    <div class="fld help t-flow small text-muted mb-2">Opens a special menu you built on the <a href="?page=ussd_flows">Service Flows</a> page — ask, look something up, confirm, make a call, and branch on the result.</div>
                    <div class="fld help t-quiz small text-muted mb-2">Plays one of the quizzes from the <a href="?page=ussd_quiz">USSD Quiz</a> page.</div>
                    <div class="fld help t-action small text-muted mb-2">Reserved for things like "check balance". Today it shows a placeholder, so avoid it in a live menu.</div>
                    <div class="fld t-menu t-catalog t-end"><label class="small text-muted mb-0">Screen text <small>(optional — what shows when it opens; otherwise the label is used)</small></label><textarea class="form-control mb-2" rows="2" name="data[body_text]" maxlength="400" placeholder="e.g. Choose your Sakan bundle:"><?=e($edit['body_text']??'')?></textarea></div>
                    <div class="fld t-offer"><label class="small text-muted mb-0">Offer</label><select class="form-select mb-2" name="data[offer_code]"><option value="">—</option><?php foreach($offers as $o):?><option value="<?=e($o['offer_code'])?>" <?=($edit['offer_code']??'')===$o['offer_code']?'selected':''?>><?=e($o['name'])?> (<?=e($o['offer_code'])?>)</option><?php endforeach;?></select></div>
                    <div class="fld t-flow"><label class="small text-muted mb-0">Service flow <small>(<a href="?page=ussd_flows">manage flows</a>)</small></label><select class="form-select mb-2" name="data[flow_key]"><option value="">—</option><?php foreach($flowOpts as $fo):?><option value="<?=e($fo['flow_key'])?>" <?=(($edit['node_type']??'')==='flow' && ($edit['offer_code']??'')===$fo['flow_key'])?'selected':''?>><?=e($fo['title'])?> (<?=e($fo['flow_key'])?>)<?=$fo['status']==='inactive'?' — off':''?></option><?php endforeach;?></select></div>
                    <div class="fld t-quiz"><label class="small text-muted mb-0">Quiz <small>(<a href="?page=ussd_quiz">manage quizzes</a>)</small></label><select class="form-select mb-2" name="data[quiz_key]"><option value="">—</option><?php foreach($quizOpts as $qo):?><option value="<?=e($qo['quiz_key'])?>" <?=(($edit['node_type']??'')==='quiz' && ($edit['offer_code']??'')===$qo['quiz_key'])?'selected':''?>><?=e($qo['title'])?> (<?=e($qo['quiz_key'])?>)</option><?php endforeach;?></select></div>
                    <div class="fld t-catalog t-sharedbundle"><label class="small text-muted mb-0">Sub-categories <small>(one per line — Shared Bundle uses <code>Seddo</code>)</small></label><textarea class="form-control mb-1" rows="3" name="data[catalog_filter]" placeholder="Sakan 7 days&#10;Sakan 30 days"><?=e($edit['catalog_filter']??'')?></textarea>
                        <?php if($subCats):?><details class="mb-2"><summary class="small text-muted">Sub-categories available (with active offers)</summary><div class="small"><?php foreach($subCats as $sc2):?><span class="badge bg-light text-dark border me-1 mb-1"><?=e($sc2['sub_category'])?> · <?=(int)$sc2['act']?></span><?php endforeach;?></div></details><?php endif;?></div>
                    <div class="fld t-action"><label class="small text-muted mb-0">Action key</label><input class="form-control mb-2" name="data[action_key]" value="<?=e($edit['action_key']??'')?>" placeholder="e.g. check_balance"></div>
                    <div class="row g-2"><div class="col-6"><label class="small text-muted mb-0">Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['draft'=>'Draft (only in Simulator)','active'=>'Active (customers see it)','inactive'=>'Off'] as $v=>$la):?><option value="<?=e($v)?>" <?=($edit['status']??'draft')===$v?'selected':''?>><?=e($la)?></option><?php endforeach;?></select></div>
                    <div class="col-6"><label class="small text-muted mb-0">Position <small>(0 = last)</small></label><input type="number" min="0" class="form-control mb-2" name="data[display_order]" value="<?=e($edit['display_order']??0)?>"></div></div>
                    <button class="btn btn-primary w-100"><?=$edit?'Save changes':'Add item'?></button>
                    <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=ussd_menu&short_code=<?=urlencode($shortCode)?>">Cancel</a><?php endif;?>
                </form>
            </div>
            <div class="cardx mt-3"><h3>Quick add — many at once</h3>
                <p class="small text-muted mb-2">One item per line. Just a name makes a submenu; add <code>| type | detail</code> for the rest:</p>
                <pre class="small mb-2" style="white-space:pre-wrap">KAA Bundle
Sakan 7 days | catalog | Sakan 7 days
Sakan 30 days | catalog | Sakan 30 days; Sakan Data 30 days
Daily 1GB | offer | 40154
How to play | end | Answer 5 questions, score 4+ to win
Comium Quiz | quiz | comium_trivia
Shared Bundle | shared | Seddo
Buy for other | other</pre>
                <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="quick_add"><input type="hidden" name="short_code" value="<?=e($shortCode)?>">
                    <label class="small text-muted mb-0">Add them inside</label><select class="form-select mb-2" name="parent_id"><option value="">The first menu (top level)</option><?php foreach($flatNodes as $n): if($n['node_type']!=='menu') continue; ?><option value="<?=e($n['id'])?>" <?=(string)($_GET['parent']??'')===(string)$n['id']?'selected':''?>>inside: <?=e($pathLabel((int)$n['id']))?></option><?php endforeach;?></select>
                    <textarea class="form-control code mb-2" rows="5" name="lines" placeholder="One item per line"></textarea>
                    <div class="d-flex gap-2"><select class="form-select" name="status" style="max-width:12rem"><option value="draft">As Drafts (safe)</option><option value="active">Active straight away</option></select><button class="btn btn-outline-primary">Add them</button></div></form>
            </div>
            <?php endif;?>
            <div class="cardx mt-3"><h3>History</h3>
                <?php if(!$versions):?><p class="small text-muted mb-0">Changes from now on are kept here, so any earlier version can be brought back.</p><?php else:?>
                <div class="table-scroll" style="max-height:260px;overflow-y:auto"><table class="table table-sm mb-0 small"><tbody><?php foreach($versions as $v):?><tr><td class="text-nowrap"><?=e(date('M j H:i',strtotime($v['created_at'])))?></td><td><?=e($v['created_by']??'')?></td><td><?=e($v['reason'])?> <span class="text-muted">· <?=(int)$v['nodes']?> items</span></td>
                    <td class="text-end"><?php if($canEdit):?><form method="post" data-confirm="Bring this version back? The current menu is kept as an archive."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="restore"><input type="hidden" name="id" value="<?=e($v['id'])?>"><button class="btn btn-sm btn-outline-secondary py-0">Restore</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
            </div>
            <?php if($canEdit):?>
            <div class="cardx mt-3"><h3>More tools</h3>
                <form method="post" class="mb-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="copy_menu"><input type="hidden" name="short_code" value="<?=e($shortCode)?>">
                    <label class="small text-muted mb-0">Copy this whole menu to another short code (as Drafts)</label>
                    <div class="input-group mb-1"><input class="form-control" name="to" placeholder="e.g. *9606*90912#"><button class="btn btn-outline-primary">Copy</button></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm_replace" value="1" id="cp"><label class="form-check-label small" for="cp">Replace it if that code already has a menu (the old one is kept as an archive)</label></div></form>
                <details><summary class="small">Import a menu from JSON</summary>
                    <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="import_menu"><input type="hidden" name="import_short_code" value="<?=e($shortCode)?>">
                        <p class="small text-muted mb-1">Same shape as <b>Export JSON</b>. It <b>replaces</b> this code's menu; the old items stay as an inactive archive.</p>
                        <textarea class="form-control code mb-2" rows="4" name="import_json" placeholder='[{"prompt_text":"Comium Menu","node_type":"menu","status":"active","children":[ … ]}]'></textarea>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm_replace" value="1" id="cr"><label class="form-check-label small" for="cr">Replace the current menu of <b><?=e($shortCode)?></b></label></div>
                        <button class="btn btn-outline-primary btn-sm">Import menu</button></form></details>
            </div>
            <?php endif;?>
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
            require_perm('edit_records'); // a live probe of an external system — not for read-only users
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
                    <?php if(can('edit_records')):?><a class="btn btn-sm btn-warning" href="?page=integrations&id=<?=e($c['id'])?>">Edit</a><?php endif;?>
                    <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=e($c['id'])?>"><?php if(can('edit_records')):?><button class="btn btn-sm btn-outline-dark">Test</button><?php endif;?></form>
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
        $chosen=array_values(array_intersect(allowed_schemas(), (array)($data['schemas']??[])));
        $allowedCsv=(!$chosen || count($chosen)===count(allowed_schemas())) ? null : implode(',',$chosen);
        try {
            if ($chosen && !in_array($data['default_schema_name']??'',$chosen,true)) throw new RuntimeException('The default database must be one of the databases this user can open.');
            if (!$chosen) throw new RuntimeException('Tick at least one database for this user.');
            if (!in_array($data['role']??'',['admin','manager','operator','viewer'],true) || !in_array($data['status']??'',['active','disabled'],true) || !in_array($data['default_schema_name']??'',allowed_schemas(),true)) throw new RuntimeException('Invalid role, status or default schema.');
            if (trim((string)($data['username']??''))==='' || trim((string)($data['full_name']??''))==='') throw new RuntimeException('Full name and username are required.');
            if (!empty($data['password']) && strlen((string)$data['password'])<10) throw new RuntimeException('Password must be at least 10 characters.');
            if (!$id && !$hash) throw new RuntimeException('A password (at least 10 characters) is required for a new user.');
            if ($id && $id===(int)user()['id'] && ($data['status']!=='active' || $data['role']!=='admin')) throw new RuntimeException('You cannot disable or demote your own account — ask another admin.');
            if ($id) {
                if ($hash) {
                    portal_pdo()->prepare('UPDATE portal_users SET full_name=?,username=?,password_hash=?,role=?,status=?,default_schema_name=?,allowed_schemas=? WHERE id=?')
                        ->execute([$data['full_name'],$data['username'],$hash,$data['role'],$data['status'],$data['default_schema_name'],$allowedCsv,$id]);
                } else {
                    portal_pdo()->prepare('UPDATE portal_users SET full_name=?,username=?,role=?,status=?,default_schema_name=?,allowed_schemas=? WHERE id=?')
                        ->execute([$data['full_name'],$data['username'],$data['role'],$data['status'],$data['default_schema_name'],$allowedCsv,$id]);
                }
                audit('update','vas_portal','portal_users',(string)$id,json_encode(['username'=>$data['username'],'role'=>$data['role'],'status'=>$data['status'],'databases'=>$allowedCsv ?? 'all','password_changed'=>(bool)$hash]));
            } else {
                portal_pdo()->prepare('INSERT INTO portal_users(full_name,username,password_hash,role,status,default_schema_name,allowed_schemas) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$data['full_name'],$data['username'],$hash,$data['role'],$data['status'],$data['default_schema_name'],$allowedCsv]);
                audit('insert','vas_portal','portal_users',null,json_encode(['username'=>$data['username'],'databases'=>$allowedCsv ?? 'all']));
            }
            flash('success','User saved.');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        } catch (PDOException $e) {
            flash('danger', str_contains($e->getMessage(),'Duplicate') ? 'That username is already taken.' : 'Could not save user: '.$e->getMessage());
        }
        redirect('?page=users');
    }
    $edit=null; if(isset($_GET['id'])){ $st=portal_pdo()->prepare('SELECT id,full_name,username,role,status,default_schema_name,allowed_schemas FROM portal_users WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit=$st->fetch(); }
    layout_start('User & Access Control');
    $users=portal_pdo()->query('SELECT id,full_name,username,role,status,default_schema_name,allowed_schemas,last_login,created_at FROM portal_users ORDER BY id DESC')->fetchAll();
    ?><div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit User':'Create User' ?></h3><form method="post" data-confirm="Confirm saving this user?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><label class="form-label">Full name</label><input class="form-control mb-2" name="full_name" value="<?=e($edit['full_name']??'')?>" placeholder="Full name"><label class="form-label">Username</label><input class="form-control mb-2" name="username" value="<?=e($edit['username']??'')?>" placeholder="Username"><label class="form-label">Password <?php if($edit):?><small class="text-muted">(leave blank to keep current)</small><?php endif;?></label><input class="form-control mb-2" name="password" type="password" autocomplete="new-password" placeholder="<?=$edit?'Leave blank to keep current':'At least 10 characters'?>"><label class="form-label">Role</label><select name="role" class="form-select mb-2"><?php foreach(['viewer','operator','manager','admin'] as $r):?><option value="<?=e($r)?>" <?=($edit['role']??'viewer')===$r?'selected':''?>><?=e(ucfirst($r))?></option><?php endforeach;?></select><label class="form-label">Status</label><select name="status" class="form-select mb-2"><?php foreach(['active','disabled'] as $s):?><option value="<?=e($s)?>" <?=($edit['status']??'active')===$s?'selected':''?>><?=e(ucfirst($s))?></option><?php endforeach;?></select><label class="form-label">Default database</label><select name="default_schema_name" class="form-select mb-2"><?php foreach(allowed_schemas() as $s):?><option value="<?=e($s)?>" <?=($edit['default_schema_name']??'HeraTesting')===$s?'selected':''?>><?=e($s)?></option><?php endforeach;?></select><label class="form-label">Databases this user can open</label><div class="db-access mb-1"><?php $mine=($edit && ($edit['allowed_schemas']??null)!==null && trim((string)$edit['allowed_schemas'])!=='') ? explode(',',$edit['allowed_schemas']) : allowed_schemas(); foreach(allowed_schemas() as $s):?><label class="db-check env-<?=schema_kind($s)?>"><input type="checkbox" name="schemas[]" value="<?=e($s)?>" <?=in_array($s,$mine,true)?'checked':''?>><span class="dot"></span><span><strong><?=e(schema_label($s))?></strong><small><?=e($s)?></small></span></label><?php endforeach;?></div><p class="text-muted small mb-3">Admins can always open every database. Anyone else only sees the ones ticked here.</p><button class="btn btn-primary w-100">Save User</button><?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=users">Cancel Edit</a><?php endif;?></form></div></div><div class="col-lg-8"><div class="cardx"><h3>Users</h3><div class="table-scroll"><table class="table table-hover"><thead><tr><th>Name</th><th>User</th><th>Role</th><th>Status</th><th>Default</th><th>Databases</th><th>Last Login</th><th></th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><?=e($u['full_name'])?></td><td><?=e($u['username'])?></td><td><span class="badge bg-<?=role_badge($u['role'])?>"><?=e($u['role'])?></span></td><td><span class="badge <?=$u['status']==='active'?'bg-success':'bg-secondary'?>"><?=e($u['status'])?></span></td><td><?=e($u['default_schema_name'])?></td><td class="cell-full"><?php if($u['role']==='admin' || $u['allowed_schemas']===null || trim((string)$u['allowed_schemas'])===''):?><span class="badge bg-light text-dark border">All</span><?php else: foreach(explode(',',$u['allowed_schemas']) as $ds):?><span class="badge bg-light text-dark border me-1"><?=e(schema_label(trim($ds)))?></span><?php endforeach; endif;?></td><td class="text-nowrap"><?=e($u['last_login'] ? substr($u['last_login'],0,16) : 'never')?></td><td><a class="btn btn-sm btn-warning" href="?page=users&id=<?=e($u['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div></div></div><?php layout_end(); exit; }

if ($page==='offer_health') {
    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'vas_offers')) throw new RuntimeException('vas_offers does not exist in '.$schema);
    $days=(int)($_GET['days']??30); if(!in_array($days,[7,30,90],true)) $days=30;
    $h=offer_health($schema,$days); $canEdit=can('edit_records');
    $q=trim((string)($_GET['q']??'')); $vendorF=trim((string)($_GET['vendor']??'')); $problem=(string)($_GET['problem']??'issues'); $statusF=(string)($_GET['status']??'');
    $sort=(string)($_GET['sort']??'severity'); $dir=(($_GET['dir']??'')==='asc')?'asc':'desc';
    $problems=['issues'=>'Any problem','dup'=>'Repeated code','other'=>'Buy-for-other clash','quiet'=>'No recent purchases','missing'=>'Missing price / validity / name','invisible'=>'Invisible to reports','all'=>'All offers (no filter)'];
    if(!isset($problems[$problem])) $problem='issues';
    $count=function(string $key) use ($h){ return count(array_filter($h['items'],fn($it)=>in_array($key,array_column($it['issues'],'key'),true))); };
    $vendors=array_values(array_unique(array_filter(array_map(fn($it)=>trim((string)$it['vendor']),$h['items'])))); sort($vendors);
    $rows=array_values(array_filter($h['items'],function($it) use ($q,$vendorF,$problem,$statusF){
        if($q!=='' && stripos($it['offer_code'].' '.$it['name'].' '.$it['offer_code_for_other'],$q)===false) return false;
        if($vendorF!=='' && trim((string)$it['vendor'])!==$vendorF) return false;
        if($statusF==='active' && !offer_is_active($it['status'])) return false;
        if($statusF==='inactive' && offer_is_active($it['status'])) return false;
        $keys=array_column($it['issues'],'key');
        if($problem==='issues') return (bool)$keys;
        if($problem!=='all') return in_array($problem,$keys,true);
        return true;
    }));
    usort($rows,function($a,$b) use ($sort,$dir){
        $va=['code'=>strtolower((string)$a['offer_code']),'name'=>strtolower((string)$a['name']),'vendor'=>strtolower((string)$a['vendor']),'last'=>(string)$a['last'],'severity'=>$a['score'],'issues'=>count($a['issues'])][$sort]??$a['score'];
        $vb=['code'=>strtolower((string)$b['offer_code']),'name'=>strtolower((string)$b['name']),'vendor'=>strtolower((string)$b['vendor']),'last'=>(string)$b['last'],'severity'=>$b['score'],'issues'=>count($b['issues'])][$sort]??$b['score'];
        $c=$va<=>$vb; return $dir==='asc'?$c:-$c;
    });
    if(($_GET['format']??'')==='csv'){
        audit('offer_health_export',$schema,'vas_offers',null,json_encode(['q'=>$q,'vendor'=>$vendorF,'problem'=>$problem,'status'=>$statusF]));
        header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="offer_health_'.date('Ymd').'.csv"');
        $out=fopen('php://output','w'); fputcsv($out,['offer_code','name','vendor','status','problems','last_purchase_attempt']);
        foreach($rows as $it) fputcsv($out,csv_safe_row([$it['offer_code'],$it['name'],$it['vendor'],offer_is_active($it['status'])?'Active':'Inactive',implode('; ',array_column($it['issues'],'label')),$it['last']??'']));
        exit;
    }
    $perPage=50; $pageNo=max(1,(int)($_GET['p']??1)); $pages=max(1,(int)ceil(count($rows)/$perPage)); $pageNo=min($pageNo,$pages);
    $slice=array_slice($rows,($pageNo-1)*$perPage,$perPage);
    $qs=$_GET; unset($qs['p'],$qs['page']);
    $link=fn(array $over)=>'?'.http_build_query(array_merge(['page'=>'offer_health'],$qs,$over));
    $sortLink=function(string $key,string $label) use ($sort,$dir,$link){ $next=($sort===$key && $dir==='asc')?'desc':'asc'; return '<a class="text-reset text-decoration-none" href="'.e($link(['sort'=>$key,'dir'=>$next,'p'=>1])).'">'.e($label).($sort===$key?($dir==='asc'?' ▲':' ▼'):'').'</a>'; };
    $tile=function(string $key,string $label,int $n,string $cls,string $sub) use ($link,$problem){ return '<a class="metric text-decoration-none '.($problem===$key?'metric-on':'').'" href="'.e($link(['problem'=>$key,'p'=>1])).'"><span>'.e($label).'</span><strong class="'.$cls.'">'.number_format($n).'</strong><small class="text-muted">'.e($sub).'</small></a>'; };
    layout_start('Offer Health');
    ?>
    <div class="metric-grid">
        <?=$tile('all','Offers',$h['total'],'',number_format($h['active']).' active')?>
        <?=$tile('dup','Repeated codes',$count('dup'),$count('dup')?'text-danger':'text-success','offers sharing a code')?>
        <?=$tile('quiet','No recent purchases',$count('quiet'),$count('quiet')?'text-warning':'text-success','active, last '.$days.' days')?>
        <?=$tile('missing','Missing details',$count('missing'),$count('missing')?'text-warning':'text-success','price, validity or name')?>
        <?=$tile('invisible','Invisible to reports',$count('invisible'),$count('invisible')?'text-info':'text-success','code not 5 characters')?>
        <?=$tile('other','Buy-for-other clashes',$count('other'),$count('other')?'text-warning':'text-success','ambiguous codes')?>
    </div>
    <div class="cardx mt-3">
        <form method="get" class="row g-2 align-items-end"><input type="hidden" name="page" value="offer_health">
            <div class="col-lg-3"><label class="small text-muted mb-0">Search code or name</label><input class="form-control" name="q" value="<?=e($q)?>" placeholder="e.g. 41012 or Sakan"></div>
            <div class="col-lg-2"><label class="small text-muted mb-0">Problem</label><select class="form-select" name="problem" data-autosubmit><?php foreach($problems as $k=>$lbl):?><option value="<?=e($k)?>" <?=$problem===$k?'selected':''?>><?=e($lbl)?></option><?php endforeach;?></select></div>
            <div class="col-lg-2"><label class="small text-muted mb-0">Vendor</label><select class="form-select" name="vendor" data-autosubmit><option value="">Any</option><?php foreach($vendors as $v):?><option <?=$vendorF===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></div>
            <div class="col-lg-2"><label class="small text-muted mb-0">Status</label><select class="form-select" name="status" data-autosubmit><?php foreach([''=>'Any','active'=>'Active','inactive'=>'Inactive'] as $k=>$lbl):?><option value="<?=$k?>" <?=$statusF===$k?'selected':''?>><?=$lbl?></option><?php endforeach;?></select></div>
            <div class="col-lg-1"><label class="small text-muted mb-0">No buys in</label><select class="form-select" name="days" data-autosubmit><?php foreach([7,30,90] as $dd):?><option value="<?=$dd?>" <?=$dd===$days?'selected':''?>><?=$dd?>d</option><?php endforeach;?></select></div>
            <div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-fill"><i class="fa fa-search"></i></button><a class="btn btn-outline-secondary" href="?page=offer_health" title="Reset filters">Reset</a>
                <a class="btn btn-outline-primary" title="Export the filtered list" href="<?=e($link(['format'=>'csv']))?>"><i class="fa fa-file-csv"></i></a></div>
        </form>
        <p class="text-muted small mt-2 mb-0">Click a tile to filter by that problem, click a column heading to sort. Nothing here changes data — the pen icon opens the offer (changes ask for confirmation and appear in Offer history). "Purchases" means any attempt in <b>subscription</b>, successful or not, for the offer's code or its buy-for-other code; dates are cached 30 minutes.<?php if($h['partial']):?> <b>The purchase lookup hit its time limit, so some offers weren't checked.</b><?php endif;?></p>
    </div>
    <div class="cardx table-card mt-3">
        <p class="text-muted px-3 pt-3 mb-0"><?=number_format(count($rows))?> offer<?=count($rows)===1?'':'s'?> shown</p>
        <?php if(!$rows):?><p class="px-3 pb-3 mb-0 <?=$problem==='issues'?'text-success':''?>"><?=$problem==='issues'&&$q===''&&$vendorF===''&&$statusF===''?'No problems found — every offer passes the checks.':'Nothing matches these filters.'?></p><?php else:?>
        <div class="table-scroll"><table class="table table-sm table-hover align-middle"><thead><tr><th><?=$sortLink('code','Code')?></th><th><?=$sortLink('name','Offer')?></th><th><?=$sortLink('vendor','Vendor')?></th><th>Status</th><th><?=$sortLink('severity','Problems')?></th><th><?=$sortLink('last','Last purchase attempt')?></th><th></th></tr></thead><tbody>
        <?php foreach($slice as $it): $act=offer_is_active($it['status']);?>
        <tr class="<?=array_filter($it['issues'],fn($x)=>$x['sev']==='danger')?'table-danger':''?>">
            <td class="text-nowrap"><b><?=e($it['offer_code'])?></b><?php if(trim((string)$it['offer_code_for_other'])!==''):?><div class="small text-muted">other: <?=e($it['offer_code_for_other'])?></div><?php endif;?></td>
            <td class="cell-full"><?=e($it['name'])?></td><td><?=e($it['vendor'])?></td>
            <td><span class="badge <?=$act?'bg-success':'bg-secondary'?>"><?=$act?'Active':'Inactive'?></span></td>
            <td class="cell-full"><?php if(!$it['issues']):?><span class="text-success">OK</span><?php else: foreach($it['issues'] as $x):?><span class="badge bg-<?=e($x['sev'])?> <?=in_array($x['sev'],['warning','info'],true)?'text-dark':''?> me-1 mb-1"><?=e($x['label'])?></span><?php endforeach; endif;?></td>
            <td class="text-nowrap"><?=$it['last']?e(substr($it['last'],0,10)).' <small class="text-muted">('.e(time_ago($it['last'])).')</small>':'<span class="text-muted">—</span>'?></td>
            <td class="text-nowrap"><?php if($canEdit):?><a class="btn btn-sm btn-warning" title="Edit offer" href="?page=offers&id=<?=e($it['id'])?>"><i class="fa-solid fa-pen"></i></a> <?php endif;?><a class="btn btn-sm btn-outline-secondary" title="History" href="?page=offer_history&id=<?=e($it['id'])?>"><i class="fa-solid fa-clock-rotate-left"></i></a></td></tr>
        <?php endforeach;?></tbody></table></div>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div>
            <?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="<?=e($link(['p'=>$pageNo-1]))?>">Prev</a><?php endif;?>
            <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="<?=e($link(['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div><?php endif;?>
    </div>
    <details class="cardx mt-3"><summary class="fw-semibold">What the badges mean</summary>
        <ul class="mt-2 mb-0 text-muted small">
            <li><span class="badge bg-danger">Repeated code — N active rows</span> several active offers share one code; a purchase can only belong to one of them. Deactivate or recode the old one.</li>
            <li><span class="badge bg-secondary">Repeated code (old version)</span> an inactive row shares a code with the live offer — safe, but worth cleaning up. Offer Performance counts these correctly now (it used to multiply them, up to 9×).</li>
            <li><span class="badge bg-warning text-dark">"Buy for other" code…</span> the other-network code points at several offers or is another offer's own code, so reports can't tell which offer it was.</li>
            <li><span class="badge bg-warning text-dark">No purchases / Never bought</span> an active offer nobody has tried to buy in the chosen period — seasonal, hidden, or not wired up; decide whether to deactivate it.</li>
            <li><span class="badge bg-warning text-dark">No price / No validity / No name</span> an active offer missing details (a 0 price is fine for free offers).</li>
            <li><span class="badge bg-info text-dark">Code not 5 characters</span> <b>subscription</b> keeps only the last 5 characters of the transaction as the offer code, so Offer Performance can never match this offer.</li>
        </ul></details>
    <?php layout_end(); exit;
}

if ($page==='retention') {
    require_perm('manage_api_keys'); $schema=current_schema();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='save_retention') { save_retention_config($_POST); flash('success','Retention window saved.'); redirect('?page=retention'); }
    $keep=retention_months(); $rep=audit_log_partition_report($schema); $plan=audit_log_retention_plan($rep,$keep,null,$schema);
    $gb=fn($b)=>$b>=1073741824?number_format($b/1073741824,1).' GB':number_format($b/1048576,1).' MB'; $dates=null; $datesFor=null; $prune=null;
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='check_dates') { $datesFor=(string)($_POST['partition']??''); $dates=audit_log_partition_dates($schema,$datesFor,$rep); audit('retention_check_dates',$schema,AUDIT_LOG_TABLE,$datesFor,null); }
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='check_pruning') { $prune=audit_log_pruning_check($schema); }
    layout_start('Data Retention');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-database me-2"></i>audit_log retention — <?=e($schema)?></h3>
        <p class="text-muted mb-2">This table is split by <b>calendar month</b> (January … December) and those 12 partitions are <b>reused every year</b>. So nothing is "dropped": a month's data stays until that month is emptied with <code>TRUNCATE PARTITION</code>, and if it isn't emptied before the same month comes round again, last year's rows mix with this year's. This page shows what each month holds and writes the exact statement to run — <b>the portal never runs it for you</b> (run it in your DBA session, off-peak; a truncate cannot be undone).</p>
        <form method="post" class="d-flex flex-wrap align-items-end gap-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_retention">
            <div><label class="small text-muted mb-0">Keep this many previous months <small>(plus the current one)</small></label><input class="form-control" type="number" min="1" max="10" name="retention_months" value="<?=$keep?>" style="width:140px"></div>
            <button class="btn btn-primary">Save</button>
            <small class="text-muted">Complaints are usually raised within 30–60 days, and older outcomes stay available in <b>subscription</b>. Pick the number whose peak size (below) fits comfortably on your disk.</small>
        </form>
        <?php if($plan['avg_month_bytes']>0):?><div class="table-scroll mt-3"><table class="table table-sm mb-0" style="max-width:640px"><thead><tr><th>If you keep</th><?php foreach($plan['options'] as $n=>$bytes):?><th class="text-end"><?=$n?> + current</th><?php endforeach;?></tr></thead><tbody><tr><td>audit_log at its peak <small class="text-muted">(a finished month ≈ <?=$gb($plan['avg_month_bytes'])?>)</small></td><?php foreach($plan['options'] as $n=>$bytes):?><td class="text-end <?=$n===$keep?'fw-bold':''?>"><?=$gb($bytes)?></td><?php endforeach;?></tr></tbody></table></div><?php endif;?>
    </div>
    <?php if(!$rep['partitioned']):?>
    <div class="alert alert-info mt-3">audit_log in <?=e($schema)?> isn't partitioned (<?=$gb($rep['total_bytes'])?>, ≈<?=number_format($rep['total_rows'])?> rows), so there are no month partitions to empty here. This page is meant for HeraProduction.</div>
    <?php else:?>
    <div class="metric-grid mt-3">
        <div class="metric"><span>audit_log size now</span><strong><?=$gb($rep['total_bytes'])?></strong><small class="text-muted">≈<?=number_format($rep['total_rows'])?> rows</small></div>
        <div class="metric"><span>Can be freed</span><strong class="<?=$plan['stale_bytes']>0?'text-danger':'text-success'?>"><?=$gb($plan['stale_bytes'])?></strong><small class="text-muted"><?=count($plan['stale'])?> month(s) outside the window</small></div>
        <div class="metric"><span>After cleaning</span><strong><?=$gb($plan['kept_bytes'])?></strong><small class="text-muted">the months you keep</small></div>
        <div class="metric"><span>Steady state at <?=$keep?>+1 months</span><strong>≈ <?=$gb($plan['steady_state_bytes'])?></strong><small class="text-muted">average month <?=$gb($plan['avg_month_bytes'])?></small></div>
    </div>
    <div class="cardx table-card mt-3"><h3 class="px-3 pt-3">By calendar month</h3>
        <div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th>Month</th><th>Status</th><th class="text-end">Rows (est.)</th><th class="text-end">Size</th><th>Days holding data</th><th></th></tr></thead><tbody>
        <?php foreach($plan['rows'] as $m=>$r): $cls=['current'=>'bg-primary','kept'=>'bg-success','stale'=>'bg-danger','empty'=>'bg-secondary'][$r['status']]; $lbl=['current'=>'Current month','kept'=>'Kept ('.(int)$r['age'].' mo ago)'.($r['rows']==0?' — empty':''),'stale'=>'Outside window — can be emptied','empty'=>'Empty'][$r['status']];?>
        <tr><td><b><?=e($r['name'])?></b></td><td><span class="badge <?=$cls?>"><?=e($lbl)?></span></td><td class="text-end"><?=number_format($r['rows'])?></td><td class="text-end"><?=$gb($r['bytes'])?></td><td><?=$r['nonempty']?>/<?=$r['subs']?></td>
            <td class="text-nowrap"><?php if($r['status']==='current'||$r['status']==='kept'):?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="check_dates"><input type="hidden" name="partition" value="<?=e($r['name'])?>"><button class="btn btn-sm btn-outline-secondary" title="Find the oldest and newest record in this month's partition (up to 30s)">Check dates</button></form><?php endif;?></td></tr>
        <?php if($datesFor===$r['name'] && $dates):?><tr><td colspan="6" class="bg-light"><?php if($dates['timeout']):?>Too slow to check from here (over 30s) — run <code>SELECT MIN(create_date), MAX(create_date) FROM audit_log PARTITION (<?=e($r['name'])?>);</code> in your DBA session.
            <?php else: $oldest=$dates['mn']?:null; $windowStart=date('Y-m-01',strtotime('-'.$keep.' months')); ?>
            <?=e($r['name'])?> holds records from <b><?=e($dates['mn']??'—')?></b> to <b><?=e($dates['mx']??'—')?></b>.
            <?php if($oldest && $oldest<$windowStart):?><div class="text-danger mt-1">This month still contains records older than <?=e($windowStart)?> (last year's <?=e($r['name'])?>). They are outside your window. To remove just those rows (in your DBA session, repeat until it affects 0 rows):<pre class="mb-0 mt-1">DELETE FROM `<?=e($schema)?>`.audit_log PARTITION (<?=e($r['name'])?>) WHERE create_date &lt; '<?=e($windowStart)?>' LIMIT 100000;</pre></div><?php else:?><span class="text-success">Nothing older than your window.</span><?php endif;?><?php endif;?></td></tr><?php endif;?>
        <?php endforeach;?></tbody></table></div>
    </div>
    <div class="cardx mt-3"><h3>Statement to run</h3>
        <?php if($plan['sql']):?>
        <p class="text-muted">Run in your DBA session (the portal's own login can't and won't). It empties only the months marked red. Take your usual backup first if you want a copy; this cannot be undone.</p>
        <pre id="retSql" class="mb-2"><?=e($plan['sql'])?></pre><button class="btn btn-sm btn-outline-primary" data-copy="<?=e($plan['sql'])?>"><i class="fa-regular fa-copy me-1"></i>Copy</button>
        <p class="text-muted small mt-2 mb-0">Each month that falls out of the window should be emptied as the new month begins — this page lists it as soon as it's outside the window, and the daily summary email mentions it once more than 0.5 GB can be freed.</p>
        <?php else:?><p class="text-success mb-0">Nothing to empty — every month outside the window is already empty.</p><?php endif;?>
    </div>
    <?php endif;?>
    <div class="cardx mt-3"><h3>How are date searches served?</h3>
        <p class="text-muted">Runs <code>EXPLAIN</code> (read-only) for a one-day search like Complaint Investigation's, to show how many partitions it has to look at and which index it uses.</p>
        <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="check_pruning"><button class="btn btn-sm btn-outline-primary">Run check</button></form>
        <?php if($prune):?><div class="mt-3"><b><?=e($prune['day'])?></b>: touches <b><?=$prune['partitions']?: 'all (not partitioned)'?></b> partition(s), index used: <b><?=e($prune['key']?:'none — full scan!')?></b>, about <?=number_format((int)$prune['rows'])?> rows examined.
            <?php if($prune['partitions']>30):?><div class="text-muted small mt-1">A date range can't narrow this table to one month (the partitions are by month number, which MySQL can't prune on a range), so every search relies on the index above and the table's total size — one more reason to keep the window short.</div><?php endif;?>
            <?php if(!$prune['key']):?><div class="text-danger small mt-1">No index serves a date search — Complaint Investigation will be slow however you filter. Ask your DBA about an index on <code>create_date</code> (or <code>(msisdn, create_date)</code>).</div><?php endif;?></div><?php endif;?>
    </div>
    <?php layout_end(); exit;
}

if ($page==='vendor') {
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
    <script src="txviewer.js?v=<?=e((string)(@filemtime(__DIR__.'/txviewer.js') ?: time()))?>"></script>
    <?php layout_end(); exit;
}

if ($page==='timeline') {
    require_perm('view_reports'); $schema=current_schema();
    $msisdn=trim((string)($_GET['msisdn']??'')); $to=trim((string)($_GET['date_to']??date('Y-m-d'))); $from=trim((string)($_GET['date_from']??date('Y-m-d',strtotime('-6 days'))));
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='add_note') {
        require_perm('create_records');
        add_complaint_note($schema,(string)($_POST['msisdn']??''),(string)($_POST['note']??''),(string)($_POST['transaction_id']??''));
        flash('success','Note saved.'); redirect('?page=timeline&'.http_build_query(['msisdn'=>$_POST['msisdn']??'','date_from'=>$_POST['date_from']??'','date_to'=>$_POST['date_to']??'']));
    }
    $tl=null; $notes=[];
    if($msisdn!=='') {
        $tl=customer_timeline($schema,$msisdn,$from,$to); $notes=complaint_notes_for($schema,$msisdn);
        if(($_GET['format']??'')==='csv'){
            audit('timeline_export',$schema,null,$tl['msisdn'],"$from..$to");
            header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="timeline_'.$tl['msisdn'].'_'.$from.'_to_'.$to.'.csv"');
            $out=fopen('php://output','w'); fputcsv($out,['time','source','what','channel','result','success','transaction_id']);
            foreach($tl['events'] as $ev) fputcsv($out,csv_safe_row([$ev['when'],$ev['source'],$ev['what'],$ev['channel'],$ev['result'],$ev['ok']?'yes':'no',$ev['transaction_id']]));
            exit;
        }
        audit('timeline_view',$schema,null,$tl['msisdn'],"$from..$to");
    }
    layout_start('Customer Timeline');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-timeline me-2"></i>Customer Timeline</h3>
        <p class="text-muted">Everything we have for one subscriber in one list, newest first — transactions from <b>audit_log</b> and subscription attempts from <b>subscription</b> side by side, so you can see what happened in order.</p>
        <form method="get" class="row g-2"><input type="hidden" name="page" value="timeline">
            <div class="col-md-4"><label>MSISDN</label><input class="form-control" name="msisdn" value="<?=e($msisdn)?>" required placeholder="e.g. 6201234"></div>
            <div class="col-md-2"><label>Date from</label><input type="date" class="form-control" name="date_from" value="<?=e($from)?>"></div>
            <div class="col-md-2"><label>Date to</label><input type="date" class="form-control" name="date_to" value="<?=e($to)?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="fa fa-search"></i> Show timeline</button></div>
        </form>
    </div>
    <?php if($tl):?>
    <?php foreach($tl['notes'] as $n):?><div class="alert alert-info py-2 mt-3 mb-0 small"><?=e($n)?></div><?php endforeach;?>
    <div class="cardx mt-3"><h3><i class="fa-regular fa-note-sticky me-2"></i>Case notes <small class="text-muted">for <?=e($tl['msisdn'])?></small></h3>
        <?php if(can('create_records')):?><form method="post" class="row g-2 mb-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="add_note"><input type="hidden" name="msisdn" value="<?=e($tl['msisdn'])?>"><input type="hidden" name="date_from" value="<?=e($from)?>"><input type="hidden" name="date_to" value="<?=e($to)?>">
            <div class="col-md-7"><textarea class="form-control" name="note" rows="2" maxlength="2000" required placeholder="What the customer reported, what you found, what was done…"></textarea></div>
            <div class="col-md-3"><input class="form-control" name="transaction_id" placeholder="Transaction ID (optional)"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Add note</button></div></form><?php endif;?>
        <?php if(!$notes):?><p class="text-muted mb-0">No notes yet for this number.</p><?php else: foreach($notes as $nt):?>
        <div class="border-start border-3 ps-3 mb-2"><div class="small text-muted"><?=e($nt['created_at'])?> · <?=e($nt['created_by'])?><?php if($nt['transaction_id']):?> · <span class="txid"><?=e($nt['transaction_id'])?></span><?php endif;?></div><div style="white-space:pre-wrap"><?=e($nt['note'])?></div></div><?php endforeach; endif;?>
    </div>
    <div class="cardx table-card mt-3">
        <div class="d-flex justify-content-between align-items-center px-3 pt-3"><span class="text-muted"><?=count($tl['events'])?> events for <?=e($tl['msisdn'])?></span><a class="btn btn-sm btn-outline-primary" href="?<?=e(http_build_query(['page'=>'timeline','msisdn'=>$msisdn,'date_from'=>$from,'date_to'=>$to,'format'=>'csv']))?>"><i class="fa fa-file-csv"></i> Export CSV</a></div>
        <?php if(!$tl['events']):?><p class="px-3 pb-3 mb-0">Nothing found for that number in this range.</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm table-hover align-middle"><thead><tr><th>Details</th><th>Time</th><th>Source</th><th>What</th><th>Channel</th><th>Result</th><th>Transaction ID</th></tr></thead><tbody>
        <?php foreach($tl['events'] as $ev):?><tr>
            <td><button type="button" class="btn btn-sm btn-outline-primary" data-tx-view <?=$ev['source']==='subscription'?'data-source="subscription" ':''?>data-id="<?=e($ev['id'])?>" data-date="<?=e($ev['date'])?>"><i class="fa-solid fa-eye me-1"></i>View</button></td>
            <td class="text-nowrap"><?=e($ev['when'])?></td>
            <td><span class="badge <?=$ev['source']==='audit_log'?'bg-secondary':'bg-info text-dark'?>"><?=e($ev['source'])?></span></td>
            <td><?=e($ev['what'])?></td><td><?=e($ev['channel'])?></td>
            <td class="cell-full"><span class="badge <?=$ev['ok']?'bg-success':'bg-danger'?> me-1"><?=$ev['ok']?'Success':'Failed'?></span><?=e(mb_strimwidth($ev['result'],0,80,'...'))?></td>
            <td class="cell-full" style="min-width:230px"><button type="button" class="btn btn-sm btn-link p-0 me-1 align-baseline" title="Copy transaction ID" data-copy="<?=e($ev['transaction_id'])?>"><i class="fa-regular fa-copy"></i></button><span class="txid"><?=e($ev['transaction_id'])?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <?php include_once __DIR__.'/../lib/txviewer_modal.php'; ?>
    <script src="txviewer.js?v=<?=e((string)(@filemtime(__DIR__.'/txviewer.js') ?: time()))?>"></script>
    <?php endif; layout_end(); exit;
}

if ($page==='offer_history') {
    require_perm('view_tables'); $schema=current_schema(); $id=(int)($_GET['id']??0);
    $offer=fetch_record($schema,'vas_offers',['id'=>$id]) ?: throw new RuntimeException('Offer not found');
    $hist=offer_history($schema,$id);
    layout_start('Offer history');
    ?>
    <div class="cardx">
        <a href="?page=offers">&larr; Offer Management</a>
        <h3 class="mt-2"><?=e($offer['name'])?> <small class="text-muted"><?=e($offer['offer_code'])?></small></h3>
        <p class="text-muted mb-0">Who changed this offer, when, and what changed. Only changes made through this portal are recorded; entries made before change-tracking was added show the submitted values without the previous ones.</p>
    </div>
    <div class="cardx mt-3">
        <?php if(!$hist):?><p class="text-muted mb-0">No changes recorded for this offer in this portal yet.</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Changes</th></tr></thead><tbody>
        <?php foreach($hist as $h):?><tr>
            <td class="text-nowrap"><?=e($h['at'])?></td><td><?=e($h['user'])?></td><td><span class="badge <?=$h['action']==='insert'?'bg-success':'bg-warning text-dark'?>"><?=$h['action']==='insert'?'Created':'Edited'?></span></td>
            <td class="cell-full"><?php if(!$h['changes']):?><span class="text-muted">no field changed</span><?php else: foreach($h['changes'] as $col=>$c):?><div><b><?=e($col)?></b>:
                <?php if($h['legacy'] || $h['action']==='insert'):?><?=e((string)$c['to'])?><?php else:?><span class="text-danger"><del><?=e((string)($c['from']??'(empty)'))?></del></span> → <span class="text-success"><?=e((string)($c['to']??'(empty)'))?></span><?php endif;?></div><?php endforeach; endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <?php layout_end(); exit;
}

if ($page==='audit') {
    require_perm('view_audit');
    $fu=trim((string)($_GET['user']??'')); $fa=trim((string)($_GET['action']??'')); $fq=trim((string)($_GET['q']??''));
    $fd=trim((string)($_GET['date_from']??'')); $ft=trim((string)($_GET['date_to']??''));
    $where=['1=1']; $params=[];
    if($fu!==''){ $where[]='username=?'; $params[]=$fu; }
    if($fa!==''){ $where[]='action=?'; $params[]=$fa; }
    if($fq!==''){ $where[]='(details LIKE ? OR target_table LIKE ? OR target_key LIKE ? OR ip_address LIKE ?)'; array_push($params,"%$fq%","%$fq%","%$fq%","%$fq%"); }
    if($fd!=='' && strtotime($fd)!==false){ $where[]='created_at >= ?'; $params[]=date('Y-m-d',strtotime($fd)).' 00:00:00'; }
    if($ft!=='' && strtotime($ft)!==false){ $where[]='created_at < ?'; $params[]=date('Y-m-d',strtotime($ft.' +1 day')).' 00:00:00'; }
    $w=implode(' AND ',$where); $pdb=portal_pdo();
    if(($_GET['format']??'')==='csv'){
        audit('audit_export',null,'portal_audit_trail',null,json_encode(['user'=>$fu,'action'=>$fa,'q'=>$fq,'from'=>$fd,'to'=>$ft]));
        $st=$pdb->prepare("SELECT id,created_at,username,action,schema_name,target_table,target_key,ip_address,details FROM portal_audit_trail WHERE $w ORDER BY id DESC LIMIT 50000"); $st->execute($params);
        header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="audit_trail_'.date('Ymd_His').'.csv"');
        $out=fopen('php://output','w'); fputcsv($out,['id','time','user','action','schema','table','key','ip','details']);
        foreach($st->fetchAll() as $row) fputcsv($out,csv_safe_row($row)); exit;
    }
    $perPage=50; $pageNo=max(1,(int)($_GET['p']??1));
    $c=$pdb->prepare("SELECT COUNT(*) FROM portal_audit_trail WHERE $w"); $c->execute($params); $total=(int)$c->fetchColumn();
    $st=$pdb->prepare("SELECT * FROM portal_audit_trail WHERE $w ORDER BY id DESC LIMIT $perPage OFFSET ".(($pageNo-1)*$perPage)); $st->execute($params); $rows=$st->fetchAll();
    $users=$pdb->query('SELECT DISTINCT username FROM portal_audit_trail WHERE username IS NOT NULL ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
    $actions=$pdb->query('SELECT DISTINCT action FROM portal_audit_trail ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    $qs=$_GET; unset($qs['p'],$qs['page']); $pages=max(1,(int)ceil($total/$perPage));
    layout_start('Audit Trail');
    ?>
    <div class="cardx"><h3>Audit Trail</h3>
        <form method="get" class="row g-2"><input type="hidden" name="page" value="audit">
            <div class="col-md-2"><label>User</label><select class="form-select" name="user"><option value="">Anyone</option><?php foreach($users as $u):?><option <?=$fu===$u?'selected':''?>><?=e($u)?></option><?php endforeach;?></select></div>
            <div class="col-md-2"><label>Action</label><select class="form-select" name="action"><option value="">Any</option><?php foreach($actions as $ac):?><option <?=$fa===$ac?'selected':''?>><?=e($ac)?></option><?php endforeach;?></select></div>
            <div class="col-md-2"><label>From</label><input type="date" class="form-control" name="date_from" value="<?=e($fd)?>"></div>
            <div class="col-md-2"><label>To</label><input type="date" class="form-control" name="date_to" value="<?=e($ft)?>"></div>
            <div class="col-md-4"><label>Contains <small class="text-muted">(details, table, key, IP)</small></label><input class="form-control" name="q" value="<?=e($fq)?>"></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="fa fa-search"></i> Filter</button><a class="btn btn-outline-secondary" href="?page=audit">Reset</a>
                <a class="btn btn-outline-primary ms-auto" href="?<?=e(http_build_query(array_merge($_GET,['page'=>'audit','format'=>'csv'])))?>"><i class="fa fa-file-csv"></i> Export CSV</a></div>
        </form>
    </div>
    <div class="cardx table-card mt-3"><p class="text-muted px-3 pt-3 mb-0"><?=number_format($total)?> entries</p>
        <div class="table-scroll"><table class="table table-sm"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Schema</th><th>Table</th><th>IP</th><th>Details</th></tr></thead><tbody>
        <?php foreach($rows as $r):?><tr><td class="text-nowrap"><?=e($r['created_at'])?></td><td><?=e($r['username'])?></td><td><span class="badge bg-secondary"><?=e($r['action'])?></span></td><td><?=e($r['schema_name'])?></td><td><?=e($r['target_table'])?></td><td class="text-nowrap"><?=e($r['ip_address'])?></td><td title="<?=e((string)$r['details'])?>"><?=e(mb_strimwidth((string)$r['details'],0,140,'...'))?></td></tr><?php endforeach;?>
        <?php if(!$rows):?><tr><td colspan="7" class="text-muted">Nothing matches these filters.</td></tr><?php endif;?></tbody></table></div>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div>
            <?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=e(http_build_query(array_merge($qs,['page'=>'audit','p'=>$pageNo-1])))?>">Prev</a><?php endif;?>
            <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=e(http_build_query(array_merge($qs,['page'=>'audit','p'=>$pageNo+1])))?>">Next</a><?php endif;?></div></div>
    </div>
    <?php layout_end(); exit;
}

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
        <p class="text-muted">Searches <code><?=e($schema)?>.<?=e(AUDIT_LOG_TABLE)?></code>. A date range is required (max <?=AUDIT_LOG_MAX_RANGE_DAYS?> days) — this table is very large. It keeps roughly the last <?=retention_months()?> months; for an older complaint use the <b>subscription</b> source.</p>
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
        foreach($rows as $row) fputcsv($out,csv_safe_row($row));
        exit;
    }
    $f=audit_log_filters_from_request($_GET);
    audit('export',$schema,AUDIT_LOG_TABLE,null,'Complaint investigation CSV export: '.json_encode($f));
    $rows=export_audit_log($schema,$f);
    header('Content-Type:text/csv');
    header('Content-Disposition: attachment; filename="'.$schema.'_audit_log_'.$f['date_from'].'_to_'.$f['date_to'].'.csv"');
    $out=fopen('php://output','w');
    fputcsv($out, ['id','transaction_id','create_date','msisdn','vendor_entity_name','channel','result_status','result_description','response_time','input_text','output_text']);
    foreach($rows as $row) fputcsv($out,csv_safe_row($row));
    exit;
}

throw new RuntimeException('Page not found');
} catch(Throwable $e){ layout_start('Something went wrong'); ?><div class="cardx"><h3>Something went wrong</h3><div class="alert alert-danger"><?=e($e->getMessage())?></div><a class="btn btn-primary" href="?page=dashboard">Back to dashboard</a></div><?php layout_end(); }
