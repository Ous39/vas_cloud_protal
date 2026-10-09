<?php
declare(strict_types=1);
// Page: ?page=retention — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

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
