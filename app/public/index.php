<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
try { verify_csrf(); } catch(Throwable $e){ flash('danger',$e->getMessage()); redirect('?'); }
$page=$_GET['page'] ?? 'dashboard';
if ($page==='switch_schema' && isset($_GET['schema'])) { set_current_schema($_GET['schema']); redirect($_SERVER['HTTP_REFERER'] ?? '?'); }
if ($page==='logout') { logout(); redirect('?page=login'); }
if ($page==='login') {
    if ($_SERVER['REQUEST_METHOD']==='POST') { if(login_attempt($_POST['username']??'', $_POST['password']??'')) redirect('?page=dashboard'); flash('danger','Invalid username or password.'); }
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login - VAS Cloud</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="style.css" rel="stylesheet"></head><body class="login-page"><div class="login-wrap"><div class="brand-panel"><div class="brand-mark">VAS</div><h1>VAS Cloud Control Center</h1><p>Secure operations portal for HeraProduction and HeraTesting.</p><ul><li>Controlled testing-to-production workflows</li><li>Audit trail and role-based access</li><li>Advanced search, reporting and SQL tools</li></ul></div><form method="post" class="login-card"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><h2>Welcome back</h2><p class="text-muted">Sign in to manage VAS operations.</p><?php foreach(flashes() as $f):?><div class="alert alert-<?=e($f['type'])?>"><?=e($f['msg'])?></div><?php endforeach;?><label>Username</label><input class="form-control" name="username" autofocus autocomplete="username"><label>Password</label><input class="form-control" name="password" type="password" autocomplete="current-password"><button class="btn btn-primary w-100 mt-3">Sign in</button></form></div></body></html><?php exit;
}
require_login();

function layout_start(string $title): void { $u=user(); $schema=current_schema(); ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> - VAS Cloud</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"><link href="style.css" rel="stylesheet"></head><body><aside class="sidebar"><div class="sidebar-brand"><div class="logo">VC</div><div><h4>VAS Cloud</h4><span>Control Center</span></div></div><nav class="nav flex-column">
<?php $items=[['dashboard','fa-gauge','Dashboard'],['subscriptions','fa-user-check','Subscriptions'],['offers','fa-tags','Offer Management'],['tables','fa-database','Database Tables'],['investigate','fa-headset','Complaint Investigation'],['alerts','fa-triangle-exclamation','Alerts'],['sql','fa-code','SQL Console'],['reports','fa-chart-line','Reports'],['projects','fa-diagram-project','Projects'],['shortcodes','fa-hashtag','Short Codes'],['audit','fa-shield-halved','Audit Trail'],['users','fa-users-gear','Users']]; foreach($items as $it): if(in_array($it[0],['sql'])&&!can('run_sql')) continue; if($it[0]==='users'&&!can('manage_users')) continue; if($it[0]==='audit'&&!can('view_audit')) continue; if(in_array($it[0],['investigate','reports','alerts'],true)&&!can('view_reports')) continue; if(in_array($it[0],['subscriptions','offers'],true)&&!can('view_tables')) continue; ?><a class="nav-link <?=($_GET['page']??'dashboard')===$it[0]?'active':''?>" href="?page=<?=$it[0]?>"><i class="fa-solid <?=$it[1]?>"></i><?=$it[2]?></a><?php endforeach; ?></nav><div class="env-switch"><span>Environment</span><div class="btn-group w-100"><a class="btn btn-sm <?=$schema==='HeraTesting'?'btn-warning':'btn-outline-light'?>" href="?page=switch_schema&schema=HeraTesting">Testing</a><a class="btn btn-sm <?=$schema==='HeraProduction'?'btn-danger':'btn-outline-light'?>" href="?page=switch_schema&schema=HeraProduction">Production</a></div></div><div class="user-box"><strong><?=e($u['full_name'])?></strong><small><?=e($u['role'])?> • <?=e($u['username'])?></small><a href="?page=logout" class="btn btn-sm btn-light w-100 mt-2">Logout</a></div></aside><main><div class="topbar"><div><h1><?=e($title)?></h1><p><?=e($schema)?> • Request <?=e(request_id())?></p></div><span class="pill <?=$schema==='HeraProduction'?'prod':'test'?>"><?=e($schema)?></span></div><?php foreach(flashes() as $f):?><div class="alert alert-<?=e($f['type'])?> shadow-sm"><?=e($f['msg'])?></div><?php endforeach; ?>
<?php }
function layout_end(): void { ?></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script nonce="<?=e(csp_nonce())?>">
function addFilter(){const box=document.getElementById('filters'); const tpl=document.getElementById('filter-template').innerHTML; box.insertAdjacentHTML('beforeend',tpl);}
function confirmAction(msg){return confirm(msg||'Please confirm before saving this operation.');}
// CSP blocks inline onclick/onsubmit attributes even with a script nonce (nonces only cover <script>
// tags), so every interactive hook is wired here instead of inline in the markup.
document.getElementById('add-filter-btn')?.addEventListener('click', addFilter);
document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => { if (!confirmAction(f.dataset.confirm)) e.preventDefault(); }));
</script></body></html><?php }
function render_form(string $schema,string $table,array $values=[],string $mode='add',array $keys=[]): void { $cols=editable_columns($schema,$table,$mode==='duplicate'); ?><div class="form-grid"><?php foreach($cols as $c): $name=$c['name']; if($mode==='duplicate' && is_auto_col($c)) continue; ?><div class="field"><label><?=e($name)?> <small><?=e($c['type'])?></small></label><?php $val=$values[$name]??''; if(str_contains(strtolower($c['type']),'text') || str_contains(strtolower($c['type']),'blob')): ?><textarea name="data[<?=e($name)?>]" class="form-control" rows="3"><?=e($val)?></textarea><?php else: ?><input name="data[<?=e($name)?>]" class="form-control" value="<?=e($val)?>"><?php endif;?></div><?php endforeach;?></div><?php foreach($keys as $k=>$v):?><input type="hidden" name="keys[<?=e($k)?>]" value="<?=e($v)?>"><?php endforeach; }

try {
if ($page==='dashboard') {
    require_perm('view_dashboard'); $schema=current_schema();
    $tables=table_names($schema); $counts=[]; foreach($tables as $t) $counts[$t]=approx_table_count($schema,$t);
    $kpis=dashboard_kpis($schema); $topVendors=top_vendors_today($schema);
    $alerts = can('view_reports') ? compute_alerts($schema) : [];
    layout_start('Executive Dashboard');
    ?>
    <?php if ($alerts): foreach($alerts as $a):?><div class="alert alert-<?=e($a['level'])?> shadow-sm">⚠ <?=e($a['message'])?> <a class="alert-link" href="?page=alerts">View alerts</a></div><?php endforeach; endif;?>
    <div class="metric-grid">
        <div class="metric"><span>Transactions Today</span><strong><?=number_format($kpis['tx_today'])?></strong></div>
        <div class="metric"><span>Success Today</span><strong><?=number_format($kpis['tx_today_success'])?></strong></div>
        <div class="metric"><span>Failed Today</span><strong><?=number_format($kpis['tx_today_failed'])?></strong></div>
        <div class="metric"><span>Active Offers</span><strong><?=number_format($kpis['offers_active'])?></strong></div>
        <div class="metric"><span>Environment</span><strong><?=e($schema==='HeraProduction'?'PROD':'TEST')?></strong></div>
        <div class="metric"><span>Subscription Rows (est.)</span><strong><?=number_format($kpis['subscription_rows_est'])?></strong></div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-lg-4"><div class="cardx"><h3>Top Vendors Today</h3><?php if(!$topVendors):?><p class="text-muted mb-0">No transactions yet today.</p><?php else:?><table class="table table-sm mb-0"><thead><tr><th>Vendor</th><th>Total</th><th>Failed</th></tr></thead><tbody><?php foreach($topVendors as $v):?><tr><td><?=e($v['vendor_entity_name'])?></td><td><?=number_format((int)$v['total'])?></td><td><?=number_format((int)$v['failed'])?></td></tr><?php endforeach;?></tbody></table><?php endif;?></div></div>
        <div class="col-lg-4"><div class="cardx"><h3>Quick Links</h3><div class="d-grid gap-2"><a class="btn btn-outline-primary" href="?page=investigate"><i class="fa fa-headset me-2"></i>Complaint Investigation</a><a class="btn btn-outline-primary" href="?page=subscriptions"><i class="fa fa-user-check me-2"></i>Subscriptions</a><a class="btn btn-outline-primary" href="?page=offers"><i class="fa fa-tags me-2"></i>Offer Management</a><a class="btn btn-outline-primary" href="?page=alerts"><i class="fa fa-triangle-exclamation me-2"></i>Alerts</a></div></div></div>
        <div class="col-lg-4"><div class="cardx"><h3>Safety Rules</h3><p class="text-muted mb-1">Delete is disabled everywhere. HeraProduction is read-only in the SQL Console and cannot be full-table-synced. All writes require confirmation and are audited.</p></div></div>
    </div>
    <div class="cardx mt-3"><h3>Database Modules</h3><p class="text-muted">Row counts are estimates (InnoDB statistics) — exact on the largest tables would mean scanning hundreds of millions of rows on every dashboard load.</p><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Table</th><th>Rows (est.)</th><th></th></tr></thead><tbody><?php foreach($counts as $t=>$c):?><tr><td><b><?=e($t)?></b></td><td><?=number_format($c)?></td><td><a class="btn btn-sm btn-primary" href="?page=table&table=<?=urlencode($t)?>">Open</a></td></tr><?php endforeach;?></tbody></table></div></div>
    <?php layout_end(); exit;
}

if ($page==='tables') { require_perm('view_tables'); layout_start('Database Tables'); $schema=current_schema(); ?><div class="cardx"><div class="d-flex justify-content-between align-items-center"><h3>Tables in <?=e($schema)?></h3><a class="btn btn-outline-primary" href="?page=reports">View Reports</a></div><div class="module-grid mt-3"><?php foreach(table_names($schema) as $t):?><a class="module-card" href="?page=table&table=<?=urlencode($t)?>"><i class="fa-solid fa-table"></i><strong><?=e($t)?></strong><span><?=number_format(approx_table_count($schema,$t))?> records (est.)</span></a><?php endforeach;?></div></div><?php layout_end(); exit; }

if ($page==='table') { require_perm('view_tables'); $schema=current_schema(); $table=$_GET['table']??''; if(!table_exists($schema,$table)) throw new RuntimeException('Table not found'); $pageNo=max(1,(int)($_GET['p']??1)); $cols=columns($schema,$table); $filters=parse_table_filters($_GET); $data=list_records($schema,$table,$filters,$pageNo,25); $data['rows']=array_map('redact_row',$data['rows']); $exportQs=$_GET; $exportQs['page']='export'; layout_start('Table: '.$table); ?><div class="cardx"><div class="d-flex flex-wrap gap-2 justify-content-between"><div><h3><?=e($table)?></h3><p class="text-muted mb-0"><?=number_format($data['total'])?> matching records in <?=e($schema)?></p></div><div class="d-flex gap-2"><a class="btn btn-success" href="?page=form&mode=add&table=<?=urlencode($table)?>"><i class="fa fa-plus"></i> Add</a><a class="btn btn-outline-primary" href="?<?=http_build_query($exportQs)?>">Export CSV<?=$filters?' (filtered)':''?></a><a class="btn btn-outline-dark" href="?page=sync&table=<?=urlencode($table)?>">Copy/Sync</a></div></div><form class="search-panel mt-3" method="get"><input type="hidden" name="page" value="table"><input type="hidden" name="table" value="<?=e($table)?>"><div id="filters"><?php $show=$filters ?: [['col'=>'','op'=>'contains','val'=>'']]; foreach($show as $f):?><div class="filter-row"><select name="fcol[]" class="form-select"><option value="">Select column</option><?php foreach($cols as $c):?><option value="<?=e($c['name'])?>" <?=$f['col']===$c['name']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select><select name="fop[]" class="form-select"><option value="contains" <?=$f['op']==='contains'?'selected':''?>>contains</option><option value="equals" <?=$f['op']==='equals'?'selected':''?>>equals</option><option value="starts" <?=$f['op']==='starts'?'selected':''?>>starts with</option><option value="ends" <?=$f['op']==='ends'?'selected':''?>>ends with</option><option value="gt" <?=$f['op']==='gt'?'selected':''?>>&gt;</option><option value="lt" <?=$f['op']==='lt'?'selected':''?>>&lt;</option></select><input name="fval[]" class="form-control" value="<?=e($f['val'])?>" placeholder="Search value"></div><?php endforeach;?></div><template id="filter-template"><div class="filter-row"><select name="fcol[]" class="form-select"><option value="">Select column</option><?php foreach($cols as $c):?><option value="<?=e($c['name'])?>"><?=e($c['name'])?></option><?php endforeach;?></select><select name="fop[]" class="form-select"><option value="contains">contains</option><option value="equals">equals</option><option value="starts">starts with</option><option value="ends">ends with</option><option value="gt">&gt;</option><option value="lt">&lt;</option></select><input name="fval[]" class="form-control" placeholder="Search value"></div></template><div class="d-flex gap-2 mt-2"><button class="btn btn-primary">Search</button><button type="button" id="add-filter-btn" class="btn btn-outline-primary">Add Filter</button><a href="?page=table&table=<?=urlencode($table)?>" class="btn btn-outline-secondary">Reset</a></div></form></div><div class="cardx table-card mt-3"><div class="table-scroll"><table class="table table-hover table-sm align-middle"><thead><tr><?php foreach($cols as $c):?><th><?=e($c['name'])?></th><?php endforeach;?><th class="sticky-actions">Actions</th></tr></thead><tbody><?php foreach($data['rows'] as $r): $key=row_key_query($schema,$table,$r);?><tr><?php foreach($cols as $c): $v=$r[$c['name']]??'';?><td title="<?=e($v)?>"><?=e(mb_strimwidth((string)$v,0,80,'...'))?></td><?php endforeach;?><td class="sticky-actions"><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary" href="?page=view&table=<?=urlencode($table)?>&<?=$key?>">View</a><?php if(can('edit_records')):?><a class="btn btn-outline-warning" href="?page=form&mode=edit&table=<?=urlencode($table)?>&<?=$key?>">Edit</a><?php endif;?><?php if(can('duplicate_records')):?><a class="btn btn-outline-success" href="?page=form&mode=duplicate&table=<?=urlencode($table)?>&<?=$key?>">Duplicate</a><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div><?php $pages=max(1,ceil($data['total']/25));?><div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="<?=e(str_replace('p='.$pageNo,'p='.($pageNo-1),$_SERVER['REQUEST_URI'].(str_contains($_SERVER['REQUEST_URI'],'?')?'':'?')))?>">Prev</a><?php endif;?><?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div></div><?php layout_end(); exit; }

if ($page==='view') { $schema=current_schema(); $table=$_GET['table']??''; $r=fetch_record($schema,$table,$_GET); if(!$r) throw new RuntimeException('Record not found'); $display=redact_row($r); layout_start('Record Details'); ?><div class="cardx"><div class="d-flex justify-content-between"><h3><?=e($table)?> Record</h3><div class="d-flex gap-2"><a class="btn btn-warning" href="?page=form&mode=edit&table=<?=urlencode($table)?>&<?=row_key_query($schema,$table,$r)?>">Edit</a><a class="btn btn-success" href="?page=form&mode=duplicate&table=<?=urlencode($table)?>&<?=row_key_query($schema,$table,$r)?>">Duplicate</a><a class="btn btn-outline-dark" href="?page=copy_record&table=<?=urlencode($table)?>&<?=row_key_query($schema,$table,$r)?>">Copy to <?=e(opposite_schema($schema))?></a></div></div><div class="detail-grid mt-3"><?php foreach($display as $k=>$v):?><div><label><?=e($k)?></label><pre><?=e($v)?></pre></div><?php endforeach;?></div><div class="alert alert-secondary mt-3">Delete is disabled by system policy. Use status fields such as disabled/retired/deleted_at where available.</div></div><?php layout_end(); exit; }

if ($page==='form') { $schema=current_schema(); $table=$_GET['table']??''; $mode=$_GET['mode']??'add'; if(!table_exists($schema,$table)) throw new RuntimeException('Table not found'); require_perm($mode==='edit'?'edit_records':($mode==='duplicate'?'duplicate_records':'create_records')); $values=[];$keys=[]; if($mode!=='add'){ $values=fetch_record($schema,$table,$_GET) ?: throw new RuntimeException('Record not found'); foreach(primary_columns($schema,$table) as $pk) $keys[$pk]=$values[$pk]; } if($_SERVER['REQUEST_METHOD']==='POST'){ $action=$mode==='edit'?'update':($mode==='duplicate'?'duplicate':'insert'); $token=make_confirmation($action,['schema'=>$schema,'table'=>$table,'mode'=>$mode,'keys'=>$_POST['keys']??[],'data'=>$_POST['data']??[]]); redirect('?page=confirm&token='.$token); } layout_start(ucfirst($mode).' Record'); ?><div class="cardx"><h3><?=ucfirst(e($mode))?> in <?=e($schema.'.'.$table)?></h3><p class="text-muted">You will preview and confirm before saving.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><?php render_form($schema,$table,$values,$mode,$keys);?><div class="mt-3"><button class="btn btn-primary">Preview & Confirm</button><a class="btn btn-outline-secondary" href="?page=table&table=<?=urlencode($table)?>">Cancel</a></div></form></div><?php layout_end(); exit; }

if ($page==='copy_record') { $from=current_schema(); $to=opposite_schema($from); $table=$_GET['table']??''; require_perm('copy_records'); $r=fetch_record($from,$table,$_GET) ?: throw new RuntimeException('Record not found'); if($_SERVER['REQUEST_METHOD']==='POST'){ $token=make_confirmation('copy_record',['from'=>$from,'to'=>$_POST['to_schema']??$to,'table'=>$table,'keys'=>array_intersect_key($_GET,array_flip(primary_columns($from,$table))),'data'=>$_POST['data']??[]]); redirect('?page=confirm&token='.$token); } layout_start('Copy Record'); ?><div class="cardx"><h3>Copy record</h3><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Destination</label><select class="form-select w-auto" name="to_schema"><option value="<?=e($to)?>"><?=e($to)?></option><option value="<?=e($from)?>"><?=e($from)?></option></select><p class="text-muted mt-2">Adjust values before copying. Save uses REPLACE to update existing primary keys.</p><?php render_form($from,$table,$r,'duplicate',[]);?><button class="btn btn-primary mt-3">Preview & Confirm Copy</button></form></div><?php layout_end(); exit; }

if ($page==='sync') { $schema=current_schema(); $table=$_GET['table']??''; require_perm('copy_records'); if($_SERVER['REQUEST_METHOD']==='POST'){ $mode=$_POST['mode']??'merge'; $to=$_POST['to_schema']??''; if($mode==='sync' && $to==='HeraProduction') throw new RuntimeException('Full-table sync cannot target HeraProduction. Choose Merge instead.'); $action=$mode==='sync'?'sync_table':'merge_table'; $token=make_confirmation($action,['from'=>$_POST['from_schema'],'to'=>$to,'table'=>$table]); redirect('?page=confirm&token='.$token); } layout_start('Copy / Sync Table'); ?><div class="cardx"><h3>Copy full table data</h3><div class="alert alert-info"><b>Merge</b> upserts by primary key and never deletes existing rows — safe for HeraProduction. <b>Full sync</b> truncates the destination table first and can only target HeraTesting.</div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="row g-3"><div class="col-md-3"><label>Mode</label><select name="mode" class="form-select"><option value="merge">Merge (safe, upsert)</option><option value="sync">Full sync (truncate + reload, HeraTesting only)</option></select></div><div class="col-md-3"><label>From</label><select name="from_schema" class="form-select"><option>HeraTesting</option><option>HeraProduction</option></select></div><div class="col-md-3"><label>To</label><select name="to_schema" class="form-select"><option>HeraProduction</option><option>HeraTesting</option></select></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-danger w-100">Preview & Confirm</button></div></div></form></div><?php layout_end(); exit; }

if ($page==='confirm') { $token=$_GET['token']??''; $c=get_confirmation($token) ?: throw new RuntimeException('Confirmation expired or already used.'); $payload=json_decode($c['payload'],true); if($_SERVER['REQUEST_METHOD']==='POST'){ if(($_POST['decision']??'')==='cancel'){ mark_confirmation($token,'cancelled'); flash('info','Operation cancelled.'); redirect('?'); } $a=$c['action']; if($a==='insert') insert_record($payload['schema'],$payload['table'],$payload['data']); elseif($a==='update') update_record($payload['schema'],$payload['table'],$payload['keys'],$payload['data']); elseif($a==='duplicate') insert_record($payload['schema'],$payload['table'],$payload['data']); elseif($a==='copy_record') copy_record($payload['from'],$payload['to'],$payload['table'],$payload['keys'],$payload['data']); elseif($a==='sync_table') sync_table($payload['from'],$payload['to'],$payload['table']); elseif($a==='merge_table') merge_table($payload['from'],$payload['to'],$payload['table']); elseif($a==='sql') run_sql($payload['schema'],$payload['sql']); elseif($a==='save_project') save_project($payload['data'],$payload['channels']??[],!empty($payload['id'])?(int)$payload['id']:null); elseif($a==='save_shortcode') save_shortcode($payload['data'],!empty($payload['id'])?(int)$payload['id']:null); else throw new RuntimeException('Unknown action'); mark_confirmation($token,'confirmed'); flash('success','Operation completed successfully.'); redirect($payload['return_to'] ?? '?page=dashboard'); } layout_start('Confirm Operation'); ?><div class="cardx confirm-box"><h3>Confirm <?=e($c['action'])?></h3><p>This action will change data. Please review before saving.</p><pre><?=e(json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button name="decision" value="confirm" class="btn btn-danger">Yes, Confirm & Save</button><button name="decision" value="cancel" class="btn btn-outline-secondary">Cancel</button></form></div><?php layout_end(); exit; }

if ($page==='export') { $schema=current_schema(); $table=$_GET['table']??''; require_perm('view_tables'); if(!table_exists($schema,$table)) throw new RuntimeException('Table not found'); $filters=parse_table_filters($_GET); audit('export',$schema,$table,null,'CSV export'.($filters?' (filtered)':'')); $rows=export_records($schema,$table,$filters,10000); header('Content-Type:text/csv'); header('Content-Disposition: attachment; filename="'.$schema.'_'.$table.'_export.csv"'); $out=fopen('php://output','w'); $first=true; foreach($rows as $row){ $row=redact_row($row); if($first){fputcsv($out,array_keys($row));$first=false;} fputcsv($out,$row);} exit; }

if ($page==='sql') { require_perm('run_sql'); $schema=current_schema(); $result=null; if($_SERVER['REQUEST_METHOD']==='POST'){ $sql=trim($_POST['sql']??''); $kind=safe_sql_kind($sql); if(in_array($kind,SQL_READONLY_KINDS,true)) $result=run_sql($schema,$sql); else { if($schema==='HeraProduction') throw new RuntimeException('HeraProduction is read-only in the SQL Console. Use the record forms (Add/Edit/Copy) for production writes.'); $token=make_confirmation('sql',['schema'=>$schema,'sql'=>$sql]); redirect('?page=confirm&token='.$token); } if(!empty($_POST['save_name'])) portal_pdo()->prepare('INSERT INTO saved_queries(name,schema_name,sql_text,created_by) VALUES(?,?,?,?)')->execute([$_POST['save_name'],$schema,$sql,user()['username']]); } layout_start('SQL Console'); ?><div class="cardx"><h3>Safe SQL Console</h3><p class="text-muted">DELETE, DROP and TRUNCATE are blocked. Write queries require confirmation.<?php if($schema==='HeraProduction'):?> <strong>HeraProduction is read-only here</strong> — SELECT/SHOW/DESCRIBE/EXPLAIN only; use the record forms for production writes.<?php endif;?></p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><textarea name="sql" class="form-control code" rows="8" placeholder="SELECT * FROM subscription LIMIT 20"><?=e($_POST['sql']??'')?></textarea><div class="row g-2 mt-2"><div class="col-md-8"><input class="form-control" name="save_name" placeholder="Optional name to save this query"></div><div class="col-md-4"><button class="btn btn-primary w-100">Run / Preview</button></div></div></form></div><?php if($result):?><div class="cardx mt-3"><h3>Result</h3><?php if($result['affected']!==null):?><p>Affected rows: <?=e($result['affected'])?></p><?php else:?><div class="table-scroll"><table class="table table-sm"><thead><tr><?php foreach(array_keys($result['rows'][0]??[]) as $h):?><th><?=e($h)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($result['rows'] as $row):?><tr><?php foreach($row as $v):?><td><?=e(mb_strimwidth((string)$v,0,90,'...'))?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php endif;?></div><?php endif; layout_end(); exit; }

if ($page==='shortcodes') { require_perm('manage_shortcodes'); $db=portal_pdo(); $edit=null; if(isset($_GET['id'])){ $st=$db->prepare('SELECT * FROM portal_short_codes WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit=$st->fetch(); } if($_SERVER['REQUEST_METHOD']==='POST'){ $token=make_confirmation('save_shortcode',['id'=>$_POST['id']??null,'data'=>$_POST['data']??[],'return_to'=>'?page=shortcodes']); redirect('?page=confirm&token='.$token); } layout_start('Channel / Short Code Management'); $rows=$db->query('SELECT * FROM portal_short_codes ORDER BY short_code, channel_type, service_name')->fetchAll(); ?><div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit Channel':'Add Channel' ?></h3><p class="text-muted">Manage USSD and IVR channels. Saving requires confirmation.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><label>Channel Type</label><select class="form-select mb-2" name="data[channel_type]"><?php foreach(['USSD','IVR'] as $v):?><option value="<?=e($v)?>" <?=($edit['channel_type']??'USSD')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select><label>Short Code</label><input class="form-control mb-2" name="data[short_code]" value="<?=e($edit['short_code']??'')?>" placeholder="*123# or 141"><label>Service Name</label><input class="form-control mb-2" name="data[service_name]" value="<?=e($edit['service_name']??'')?>" placeholder="VAS Main Menu"><label>Provider</label><input class="form-control mb-2" name="data[provider]" value="<?=e($edit['provider']??'')?>" placeholder="Comium"><label>Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['Active','Inactive','Pending','Suspended'] as $v):?><option value="<?=e($v)?>" <?=($edit['status']??'Pending')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select><label>Description</label><textarea class="form-control mb-2" name="data[description]" rows="3"><?=e($edit['description']??'')?></textarea><button class="btn btn-primary w-100">Preview & Confirm Save</button><?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=shortcodes">Cancel Edit</a><?php endif;?></form></div></div><div class="col-lg-8"><div class="cardx"><div class="d-flex justify-content-between align-items-center"><h3>Channel Register</h3><span class="badge bg-primary"><?=count($rows)?> channels</span></div><div class="table-scroll"><table class="table table-hover"><thead><tr><th>Type</th><th>Short Code</th><th>Service Name</th><th>Provider</th><th>Status</th><th>Linked Projects</th><th></th></tr></thead><tbody><?php foreach($rows as $r): $st=$db->prepare('SELECT COUNT(*) c FROM portal_project_channels WHERE channel_id=?'); $st->execute([$r['id']]); $linked=(int)$st->fetch()['c']; ?><tr><td><span class="badge bg-dark"><?=e($r['channel_type'])?></span></td><td><strong><?=e($r['short_code'])?></strong></td><td><?=e($r['service_name'])?></td><td><?=e($r['provider'])?></td><td><span class="badge status-<?=e(strtolower($r['status']))?>"><?=e($r['status'])?></span></td><td><?=e($linked)?></td><td class="sticky-actions"><a class="btn btn-sm btn-warning" href="?page=shortcodes&id=<?=e($r['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div></div></div><?php layout_end(); exit; }

if ($page==='offers') {
    require_perm('view_tables'); $schema=current_schema();
    if (!table_exists($schema,'vas_offers')) throw new RuntimeException('vas_offers does not exist in '.$schema);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);
        if (($_POST['action']??'')==='toggle') {
            require_perm('edit_records');
            $row=fetch_record($schema,'vas_offers',['id'=>$id]) ?: throw new RuntimeException('Offer not found');
            $newStatus = ($row['status']??'')==='active' ? 'inactive' : 'active';
            $token=make_confirmation('update',['schema'=>$schema,'table'=>'vas_offers','keys'=>['id'=>$id],'data'=>['status'=>$newStatus]]);
        } else {
            require_perm($id ? 'edit_records' : 'create_records');
            $data=$_POST['data']??[];
            $token=make_confirmation($id?'update':'insert',['schema'=>$schema,'table'=>'vas_offers','keys'=>['id'=>$id],'data'=>$data]);
        }
        redirect('?page=confirm&token='.$token);
    }
    $edit=null; if(isset($_GET['id'])){ $edit=fetch_record($schema,'vas_offers',['id'=>(int)$_GET['id']]); }
    $filters=[];
    foreach(['name'=>'name','offer_code'=>'offer_code','vendor'=>'vendor','category'=>'category'] as $qp=>$col) if(trim((string)($_GET[$qp]??''))!=='') $filters[]=['col'=>$col,'op'=>'contains','val'=>trim((string)$_GET[$qp])];
    if (trim((string)($_GET['status']??''))!=='') $filters[]=['col'=>'status','op'=>'equals','val'=>$_GET['status']];
    $pageNo=max(1,(int)($_GET['p']??1));
    $data=list_records($schema,'vas_offers',$filters,$pageNo,25);
    layout_start('Offer Management');
    ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="cardx">
                <h3><?= $edit?'Edit Offer':'Add Offer' ?></h3>
                <p class="text-muted">Saving requires confirmation. Toggling status also requires confirmation.</p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                    <label>Vendor</label><input class="form-control mb-2" name="data[vendor]" value="<?=e($edit['vendor']??'')?>">
                    <label>Offer Code</label><input class="form-control mb-2" name="data[offer_code]" value="<?=e($edit['offer_code']??'')?>">
                    <label>Offer Code (other network)</label><input class="form-control mb-2" name="data[offer_code_for_other]" value="<?=e($edit['offer_code_for_other']??'')?>">
                    <label>PCRF Offer Code</label><input class="form-control mb-2" name="data[pcrf_offer_code]" value="<?=e($edit['pcrf_offer_code']??'')?>">
                    <div class="row g-2"><div class="col-6"><label>Category</label><input class="form-control mb-2" name="data[category]" value="<?=e($edit['category']??'')?>"></div><div class="col-6"><label>Sub-category</label><input class="form-control mb-2" name="data[sub_category]" value="<?=e($edit['sub_category']??'')?>"></div></div>
                    <label>Name</label><input class="form-control mb-2" name="data[name]" value="<?=e($edit['name']??'')?>">
                    <label>Description</label><textarea class="form-control mb-2" name="data[description]" rows="2"><?=e($edit['description']??'')?></textarea>
                    <div class="row g-2">
                        <div class="col-4"><label>Price</label><input class="form-control mb-2" name="data[one_time_price]" value="<?=e($edit['one_time_price']??'')?>"></div>
                        <div class="col-4"><label>Rental</label><input class="form-control mb-2" name="data[rental_price]" value="<?=e($edit['rental_price']??'')?>"></div>
                        <div class="col-4"><label>Discount</label><input class="form-control mb-2" name="data[discount]" value="<?=e($edit['discount']??'')?>"></div>
                    </div>
                    <div class="row g-2">
                        <div class="col-4"><label>Free Data (MB)</label><input class="form-control mb-2" name="data[free_data]" value="<?=e($edit['free_data']??'')?>"></div>
                        <div class="col-4"><label>Validity (days)</label><input class="form-control mb-2" name="data[validity_amount]" value="<?=e($edit['validity_amount']??'')?>"></div>
                        <div class="col-4"><label>Speed limit</label><input class="form-control mb-2" name="data[speed_limit]" value="<?=e($edit['speed_limit']??'NA')?>"></div>
                    </div>
                    <label>Status</label>
                    <select class="form-select mb-2" name="data[status]"><?php foreach(['active','inactive'] as $v):?><option value="<?=e($v)?>" <?=($edit['status']??'inactive')===$v?'selected':''?>><?=e(ucfirst($v))?></option><?php endforeach;?></select>
                    <button class="btn btn-primary w-100">Preview & Confirm Save</button>
                    <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=offers">Cancel Edit</a><?php endif;?>
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
                    <div class="col-md-2"><select class="form-select" name="status"><option value="">Any status</option><?php foreach(['active','inactive'] as $v):?><option value="<?=e($v)?>" <?=($_GET['status']??'')===$v?'selected':''?>><?=e(ucfirst($v))?></option><?php endforeach;?></select></div>
                    <div class="col-12"><button class="btn btn-outline-primary">Search</button> <a class="btn btn-outline-secondary" href="?page=offers">Reset</a></div>
                </form>
                <div class="table-scroll mt-3"><table class="table table-hover"><thead><tr><th>Name</th><th>Offer Code</th><th>Vendor</th><th>Category</th><th>Price</th><th>Validity</th><th>Status</th><th></th></tr></thead><tbody>
                <?php foreach($data['rows'] as $r):?><tr>
                    <td><strong><?=e($r['name'])?></strong><div class="text-muted small"><?=e(mb_strimwidth((string)$r['description'],0,60,'...'))?></div></td>
                    <td><?=e($r['offer_code'])?></td>
                    <td><?=e($r['vendor'])?></td>
                    <td><?=e($r['category'])?></td>
                    <td><?=e($r['one_time_price'])?></td>
                    <td><?=e($r['validity_amount'])?> day(s)</td>
                    <td><span class="badge <?=$r['status']==='active'?'bg-success':'bg-secondary'?>"><?=e($r['status'])?></span></td>
                    <td class="sticky-actions d-flex gap-1">
                        <a class="btn btn-sm btn-warning" href="?page=offers&id=<?=e($r['id'])?>">Edit</a>
                        <?php if(can('edit_records')):?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="btn btn-sm btn-outline-dark">Toggle</button></form><?php endif;?>
                    </td>
                </tr><?php endforeach;?>
                </tbody></table></div>
                <?php $pages=max(1,(int)ceil($data['total']/25));?>
                <div class="d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($_GET,['p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
            </div>
        </div>
    </div>
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

if ($page==='alerts') {
    require_perm('view_reports'); $schema=current_schema();
    $alerts=compute_alerts($schema);
    layout_start('Alerts & Monitoring');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-triangle-exclamation me-2"></i>Alerts</h3>
        <p class="text-muted">Computed on page load from <?=e(AUDIT_LOG_TABLE)?> — high failure rate in the last hour, and vendors that were active this time yesterday but silent in the last hour. This is not a push notification; visit this page (or the dashboard) to see current state.</p>
        <?php if(!$alerts):?><div class="alert alert-success mb-0">No active alerts.</div><?php else: foreach($alerts as $a):?><div class="alert alert-<?=e($a['level'])?>"><?=e($a['message'])?></div><?php endforeach; endif;?>
    </div>
    <?php layout_end(); exit;
}

if ($page==='projects') { require_perm('manage_projects'); $db=portal_pdo(); $edit=null; $selected=[]; if(isset($_GET['id'])){ $st=$db->prepare('SELECT * FROM portal_projects WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit=$st->fetch(); if($edit) $selected=project_channel_ids((int)$edit['id']); } if($_SERVER['REQUEST_METHOD']==='POST'){ $token=make_confirmation('save_project',['id'=>$_POST['id']??null,'data'=>$_POST['data']??[],'channels'=>$_POST['channels']??[],'return_to'=>'?page=projects']); redirect('?page=confirm&token='.$token); } layout_start('Project Management'); $channels=all_channels(); $rows=$db->query('SELECT * FROM portal_projects ORDER BY id DESC')->fetchAll(); ?><div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit Project':'Add Project' ?></h3><p class="text-muted">Link each project to one or more USSD/IVR channels through the short code.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><label>Project Name</label><input class="form-control mb-2" name="data[project_name]" value="<?=e($edit['project_name']??'')?>" placeholder="VAS Main Menu Upgrade"><label>Primary Short Code</label><input class="form-control mb-2" name="data[short_code]" value="<?=e($edit['short_code']??'')?>" placeholder="*123#"><label>Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['Planning','Development','Testing','Launched','Completed','Suspended'] as $v):?><option value="<?=e($v)?>" <?=($edit['status']??'Planning')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select><div class="row g-2"><div class="col-md-6"><label>Start Date</label><input type="date" class="form-control mb-2" name="data[start_date]" value="<?=e($edit['start_date']??'') ?>"></div><div class="col-md-6"><label>Launch Date</label><input type="date" class="form-control mb-2" name="data[launch_date]" value="<?=e($edit['launch_date']??'') ?>"></div></div><label>Description</label><textarea class="form-control mb-2" name="data[description]" rows="3"><?=e($edit['description']??'')?></textarea><label>Linked Channels</label><div class="channel-picker mb-3"><?php foreach($channels as $c):?><label class="channel-choice"><input type="checkbox" name="channels[]" value="<?=e($c['id'])?>" <?=in_array((int)$c['id'],$selected,true)?'checked':''?>> <span><b><?=e($c['channel_type'])?> <?=e($c['short_code'])?></b><small><?=e($c['service_name'])?> • <?=e($c['provider'])?></small></span></label><?php endforeach;?></div><button class="btn btn-primary w-100">Preview & Confirm Save</button><?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=projects">Cancel Edit</a><?php endif;?></form></div></div><div class="col-lg-8"><div class="cardx"><div class="d-flex justify-content-between align-items-center"><h3>Project Register</h3><span class="badge bg-primary"><?=count($rows)?> projects</span></div><div class="table-scroll"><table class="table table-hover"><thead><tr><th>Project Name</th><th>Primary Short Code</th><th>Status</th><th>Start</th><th>Launch</th><th>Linked Channels</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=e($r['project_name'])?></strong><div class="text-muted small"><?=e(mb_strimwidth((string)$r['description'],0,90,'...'))?></div></td><td><?=e($r['short_code'])?></td><td><span class="badge status-<?=e(strtolower($r['status']))?>"><?=e($r['status'])?></span></td><td><?=e($r['start_date'])?></td><td><?=e($r['launch_date'])?></td><td><?=e(project_channels_label((int)$r['id']))?></td><td class="sticky-actions"><a class="btn btn-sm btn-warning" href="?page=projects&id=<?=e($r['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div></div></div><?php layout_end(); exit; }

if ($page==='users') { require_perm('manage_users'); if($_SERVER['REQUEST_METHOD']==='POST'){ $data=$_POST; if(!empty($data['password'])) $hash=password_hash($data['password'],PASSWORD_DEFAULT); else $hash=password_hash('ChangeMe123',PASSWORD_DEFAULT); portal_pdo()->prepare('INSERT INTO portal_users(full_name,username,password_hash,role,status,default_schema_name) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),role=VALUES(role),status=VALUES(status),default_schema_name=VALUES(default_schema_name)')->execute([$data['full_name'],$data['username'],$hash,$data['role'],$data['status'],$data['default_schema_name']]); flash('success','User saved.'); redirect('?page=users'); } layout_start('User & Access Control'); $users=portal_pdo()->query('SELECT id,full_name,username,role,status,default_schema_name,last_login,created_at FROM portal_users ORDER BY id DESC')->fetchAll(); ?><div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3>Create / Update User</h3><form method="post" data-confirm="Confirm saving this user?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input class="form-control mb-2" name="full_name" placeholder="Full name"><input class="form-control mb-2" name="username" placeholder="Username"><input class="form-control mb-2" name="password" placeholder="Password"><select name="role" class="form-select mb-2"><option>viewer</option><option>operator</option><option>manager</option><option>admin</option></select><select name="status" class="form-select mb-2"><option>active</option><option>disabled</option></select><select name="default_schema_name" class="form-select mb-2"><option>HeraTesting</option><option>HeraProduction</option></select><button class="btn btn-primary">Save User</button></form></div></div><div class="col-lg-8"><div class="cardx"><h3>Users</h3><table class="table"><thead><tr><th>Name</th><th>User</th><th>Role</th><th>Status</th><th>Default</th><th>Last Login</th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><?=e($u['full_name'])?></td><td><?=e($u['username'])?></td><td><span class="badge bg-<?=role_badge($u['role'])?>"><?=e($u['role'])?></span></td><td><?=e($u['status'])?></td><td><?=e($u['default_schema_name'])?></td><td><?=e($u['last_login'])?></td></tr><?php endforeach;?></tbody></table></div></div></div><?php layout_end(); exit; }

if ($page==='audit') { require_perm('view_audit'); layout_start('Audit Trail'); $rows=portal_pdo()->query('SELECT * FROM portal_audit_trail ORDER BY id DESC LIMIT 300')->fetchAll(); ?><div class="cardx"><h3>Latest activity</h3><div class="table-scroll"><table class="table table-sm"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Schema</th><th>Table</th><th>Details</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['created_at'])?></td><td><?=e($r['username'])?></td><td><?=e($r['action'])?></td><td><?=e($r['schema_name'])?></td><td><?=e($r['target_table'])?></td><td><?=e(mb_strimwidth((string)$r['details'],0,150,'...'))?></td></tr><?php endforeach;?></tbody></table></div></div><?php layout_end(); exit; }

if ($page==='reports') { require_perm('view_reports'); layout_start('Reports & Monitoring'); $schema=current_schema(); $tables=table_names($schema); ?><div class="cardx mb-3"><h3><i class="fa-solid fa-headset me-2"></i>Complaint / Transaction Investigation</h3><p class="text-muted mb-2">Look up what happened for a specific subscriber or transaction — filter <?=e(AUDIT_LOG_TABLE)?> by MSISDN, transaction ID, date range, vendor or result, view the full vendor request/response, and export the filtered results to CSV.</p><a class="btn btn-primary" href="?page=investigate">Open Investigation Tool</a></div><div class="metric-grid"><?php foreach(array_slice($tables,0,8) as $t):?><div class="metric"><span><?=e($t)?></span><strong><?=number_format(approx_table_count($schema,$t))?></strong></div><?php endforeach;?></div><div class="cardx"><h3>Operational Monitoring</h3><p class="text-muted">Use this page for quick health checks across VAS tables. Future integration can include API latency, transaction success rates, partner dashboards and alerts.</p></div><?php layout_end(); exit; }

if ($page==='investigate') {
    require_perm('view_reports');
    $schema=current_schema();
    $f=audit_log_filters_from_request($_GET);
    $pageNo=max(1,(int)($_GET['p']??1));
    $data=search_audit_log($schema,$f,$pageNo,25);
    layout_start('Complaint Investigation');
    $qs=$_GET; unset($qs['p']);
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-headset me-2"></i>Complaint / Transaction Investigation</h3>
        <p class="text-muted">Searches <code><?=e($schema)?>.<?=e(AUDIT_LOG_TABLE)?></code>. A date range is required (max <?=AUDIT_LOG_MAX_RANGE_DAYS?> days) — this table is very large and partitioned by date.</p>
        <form method="get" class="row g-2">
            <input type="hidden" name="page" value="investigate">
            <div class="col-md-2"><label>Date from</label><input type="date" name="date_from" class="form-control" value="<?=e($f['date_from'])?>" required></div>
            <div class="col-md-2"><label>Date to</label><input type="date" name="date_to" class="form-control" value="<?=e($f['date_to'])?>" required></div>
            <div class="col-md-2"><label>MSISDN</label><input class="form-control" name="msisdn" value="<?=e($f['msisdn'])?>"></div>
            <div class="col-md-2"><label>Transaction ID</label><input class="form-control" name="transaction_id" value="<?=e($f['transaction_id'])?>"></div>
            <div class="col-md-2"><label>Result status</label><input class="form-control" name="result_status" value="<?=e($f['result_status'])?>" placeholder="SUCCESS / FAILED"></div>
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
            <thead><tr><th>Date</th><th>Transaction ID</th><th>MSISDN</th><th>Vendor</th><th>Channel</th><th>Status</th><th>Result</th><th>Response (ms)</th><th>Request / Response</th></tr></thead>
            <tbody><?php foreach($data['rows'] as $r):?><tr>
                <td><?=e($r['create_date'])?></td>
                <td><?=e($r['transaction_id'])?></td>
                <td><?=e($r['msisdn'])?></td>
                <td><?=e($r['vendor_entity_name'])?></td>
                <td><?=e($r['channel'])?></td>
                <td><span class="badge <?=strtoupper((string)$r['result_status'])==='SUCCESS'?'bg-success':'bg-danger'?>"><?=e($r['result_status'])?></span></td>
                <td><?=e(mb_strimwidth((string)$r['result_description'],0,80,'...'))?></td>
                <td><?=e($r['response_time'])?></td>
                <td>
                    <details><summary>input</summary><pre class="mb-1"><?=e($r['input_text'])?></pre></details>
                    <details><summary>output</summary><pre class="mb-0"><?=e($r['output_text'])?></pre></details>
                </td>
            </tr><?php endforeach;?></tbody>
        </table></div>
        <?php $pages=max(1,(int)ceil($data['total']/25));?>
        <div class="p-3 d-flex justify-content-between"><span>Page <?=$pageNo?> of <?=$pages?></span><div><?php if($pageNo>1):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo-1]))?>">Prev</a><?php endif;?> <?php if($pageNo<$pages):?><a class="btn btn-sm btn-outline-primary" href="?<?=http_build_query(array_merge($qs,['page'=>'investigate','p'=>$pageNo+1]))?>">Next</a><?php endif;?></div></div>
    </div>
    <?php layout_end(); exit;
}

if ($page==='investigate_export') {
    require_perm('view_reports');
    $schema=current_schema();
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
