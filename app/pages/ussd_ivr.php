<?php
declare(strict_types=1);
// Page: ?page=ussd_ivr — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

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
    $offerRows = ussd_route_offers($schema, array_column($routes, 'offer_code')); $review = ussd_route_review($routes, $offerRows);
    $nBad = count(array_filter($review, fn($p) => in_array('error', array_column($p, 'level'), true))); $nWarn = count($review) - $nBad;
    $q = trim((string)($_GET['q'] ?? '')); $onlyProblems = ($_GET['problems'] ?? '') === '1';
    $shown = array_values(array_filter($routes, function ($r) use ($q, $onlyProblems, $review, $offerRows) {
        if ($onlyProblems && empty($review[(int)$r['id']])) return false;
        if ($q === '') return true; $o = $offerRows[trim((string)$r['offer_code'])] ?? null;
        return stripos(implode(' ', [$r['shortcode'], $r['service_code'], $r['offer_code'], $o['name'] ?? '']), $q) !== false;
    }));
    $qs = fn(array $x) => '?'.http_build_query(array_filter(array_merge(['page' => 'ussd_ivr', 'type' => $typeFilter, 'q' => $q, 'problems' => $onlyProblems ? '1' : ''], $x), fn($v) => $v !== null && $v !== ''));
    $queue = agent_queue_snapshot($schema);
    $ussdToday = channel_activity_today($schema, ['USSD']);
    $ivrToday = channel_activity_today($schema, ['IVR']);
    layout_start('USSD & IVR'); ussd_subnav('ussd_ivr');
    ?>
    <div class="metric-grid">
        <div class="metric"><span>USSD Transactions Today</span><strong><?=number_format($ussdToday['total'])?></strong></div>
        <div class="metric"><span>USSD Failed Today</span><strong><?=number_format($ussdToday['failed'])?></strong><?php if($ussdToday['total']>0):?><small class="text-muted"><?=round($ussdToday['failed']*100/$ussdToday['total'],1)?>% of USSD</small><?php endif;?></div>
        <div class="metric"><span>IVR Transactions Today</span><strong><?=number_format($ivrToday['total'])?></strong></div>
        <div class="metric"><span>IVR Failed Today</span><strong><?=number_format($ivrToday['failed'])?></strong><?php if($ivrToday['total']>0):?><small class="text-muted"><?=round($ivrToday['failed']*100/$ivrToday['total'],1)?>% of IVR</small><?php endif;?></div>
    </div>
    <p class="text-muted mt-2">Counted from <?=e(AUDIT_LOG_TABLE)?> where channel = USSD/IVR. Channel registration/ownership (short codes, providers) lives on the <a href="?page=shortcodes">Short Codes</a> page; this page is the operational routing and live-queue view.</p>
    <div class="row g-3 mt-1">
        <div class="col-lg-5"><div class="cardx"><h3><?= $edit?'Edit Route':'Add Route' ?></h3><p class="text-muted">Maps a short code + service code to the offer it triggers. Saving requires confirmation.</p>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
                <label>Type</label><select class="form-select mb-2" name="data[type]"><?php foreach(['USSD','IVR'] as $v):?><option value="<?=e($v)?>" <?=($edit['type']??'USSD')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select>
                <label>Short Code</label><input class="form-control mb-2" name="data[shortcode]" value="<?=e($edit['shortcode']??'')?>" placeholder="*123#">
                <label>Service Code</label><input class="form-control mb-2" name="data[service_code]" value="<?=e($edit['service_code']??'')?>">
                <label>Offer Code <small class="text-muted">(digits only — checked against the catalogue below)</small></label><input class="form-control mb-2" name="data[offer_code]" value="<?=e($edit['offer_code']??'')?>" inputmode="numeric" pattern="[0-9]*">
                <label>Status</label><select class="form-select mb-2" name="data[status]"><option value="1" <?=!$edit||ussd_route_on($edit['status'])?'selected':''?>>On — customers can use it</option><option value="0" <?=$edit&&!ussd_route_on($edit['status'])?'selected':''?>>Off</option></select>
                <button class="btn btn-primary w-100">Preview & Confirm Save</button>
                <?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=ussd_ivr">Cancel Edit</a><?php endif;?>
            </form>
        </div></div>
        <div class="col-lg-7"><div class="cardx">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h3 class="mb-0">Routing Table</h3><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary <?=!$typeFilter?'active':''?>" href="<?=e($qs(['type'=>null]))?>">All</a><a class="btn btn-outline-primary <?=$typeFilter==='USSD'?'active':''?>" href="<?=e($qs(['type'=>'USSD']))?>">USSD</a><a class="btn btn-outline-primary <?=$typeFilter==='IVR'?'active':''?>" href="<?=e($qs(['type'=>'IVR']))?>">IVR</a></div></div>
            <form method="get" class="d-flex flex-wrap gap-2 mt-2"><input type="hidden" name="page" value="ussd_ivr"><?php if($typeFilter):?><input type="hidden" name="type" value="<?=e($typeFilter)?>"><?php endif;?>
                <input class="form-control form-control-sm w-auto" name="q" value="<?=e($q)?>" placeholder="Search short code, service code, offer…" style="min-width:16rem">
                <div class="form-check align-self-center"><input class="form-check-input" type="checkbox" name="problems" value="1" id="onlyp" <?=$onlyProblems?'checked':''?> data-autosubmit><label class="form-check-label small" for="onlyp">Only rows with a problem</label></div>
                <button class="btn btn-sm btn-outline-primary">Search</button><?php if($q!==''||$onlyProblems):?><a class="btn btn-sm btn-link" href="<?=e($qs(['q'=>null,'problems'=>null]))?>">Clear</a><?php endif;?></form>
            <div class="small mt-2"><?php if($nBad||$nWarn):?><?php if($nBad):?><span class="badge bg-danger"><?=$nBad?> to fix</span> <?php endif;?><?php if($nWarn):?><span class="badge bg-warning text-dark"><?=$nWarn?> to look at</span> <?php endif;?><span class="text-muted">Only rows that are switched on can fail a customer; hover a badge for the reason.</span><?php else:?><span class="badge bg-success">all good</span> <span class="text-muted">Every route that is on points to an offer that is on.</span><?php endif;?> <span class="text-muted ms-1">Showing <?=count($shown)?> of <?=count($routes)?>.</span></div>
            <?php if(!$shown):?><p class="text-muted mb-0 mt-2"><?=$routes?'Nothing matches.':'No routes configured'.($typeFilter?" for $typeFilter":'').'.'?></p><?php else:?>
            <div class="table-scroll"><table class="table table-hover table-sm mt-2"><thead><tr><th>Type</th><th>Short Code</th><th>Service Code</th><th>Offer</th><th>Status</th><th>Check</th><th></th></tr></thead><tbody>
            <?php foreach($shown as $r): $o=$offerRows[trim((string)$r['offer_code'])]??null; $pp=$review[(int)$r['id']]??[]; $on=ussd_route_on($r['status']);?><tr class="<?=$on?'':'text-muted'?>"><td><span class="badge bg-dark"><?=e($r['type'])?></span></td><td><?=e($r['shortcode'])?></td><td><?=e($r['service_code'])?></td>
                <td><?=e($r['offer_code'])?><?php if($o):?> <small class="text-muted"><?=e($o['name'])?> · D<?=e(rtrim(rtrim(number_format((float)$o['one_time_price'],2,'.',''),'0'),'.'))?></small><?php endif;?></td>
                <td><span class="badge <?=$on?'bg-success':'bg-secondary'?>"><?=$on?'On':'Off'?></span></td>
                <td><?php foreach($pp as $p):?><span class="badge <?=$p['level']==='error'?'bg-danger':'bg-warning text-dark'?>" title="<?=e($p['msg'])?>"><?=$p['level']==='error'?'fix':'check'?></span> <?php endforeach;?></td>
                <td><a class="btn btn-sm btn-warning" href="?page=ussd_ivr&id=<?=e($r['id'])?>">Edit</a></td></tr><?php endforeach;?>
            </tbody></table></div><?php endif;?>
        </div>
        <div class="cardx mt-3"><h3>Live Agent Queue</h3><p class="text-muted">Current USSD/IVR sessions held in <code>agent_queue</code>.</p>
            <?php if(!$queue):?><p class="text-muted mb-0">Queue is empty.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>MSISDN</th><th>Service Code</th><th>Status</th></tr></thead><tbody><?php foreach($queue as $q):?><tr><td><?=e($q['msisdn'])?></td><td><?=e($q['service_code'])?></td><td><span class="badge bg-info text-dark"><?=e($q['status'])?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
        </div></div>
    </div>
    <?php layout_end(); exit;
