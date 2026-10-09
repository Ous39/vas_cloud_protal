<?php
declare(strict_types=1);
// Page: ?page=offer_health — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

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
