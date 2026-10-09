<?php
declare(strict_types=1);
// Page: ?page=refunds — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_api_keys'); $schema=current_schema(); $cfg=ussd_proxy_config(); $mode=in_array($cfg['refund_mode'],['test','live'],true)?$cfg['refund_mode']:'off'; $by=(string)(user()['username']??'');
    $F=['offer_code'=>trim((string)($_GET['offer']??'')),'vendor'=>trim((string)($_GET['vendor']??'')),'msisdn'=>trim((string)($_GET['msisdn']??'')),'channel'=>'REF','date'=>trim((string)($_GET['date']??''))]; $purchaseId=(int)($_GET['purchase']??0)?:null;
    $preview=null;
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $F=['offer_code'=>trim((string)($_POST['offer_code']??'')),'vendor'=>trim((string)($_POST['vendor']??'')),'msisdn'=>trim((string)($_POST['msisdn']??'')),'channel'=>trim((string)($_POST['channel']??'REF')),'date'=>trim((string)($_POST['date']??''))]; $purchaseId=(int)($_POST['purchase_id']??0)?:null;
        $doR=(string)($_POST['do']??'');
        try {
            if ($doR==='send') {
                $rr=refund_send($cfg,$F,$by,$purchaseId);
                flash($rr['status']==='ok'?'success':($rr['status']==='test'?'info':($rr['status']==='sent'?'warning':'danger')),'Refund '.['ok'=>'accepted by Hera','test'=>'recorded as a TEST (nothing was sent)','failed'=>'was NOT accepted','sent'=>'was sent, but Hera\'s answer is unclear'][$rr['status']].': '.$rr['note']);
                redirect('?page=refunds&msisdn='.urlencode($F['msisdn']));
            }
            $n=refund_normalize($F); refund_tables(); $pv=portal_pdo()->prepare("SELECT status, created_at, note FROM refund_requests WHERE msisdn=? AND offer_code=? AND sub_date=? ORDER BY id DESC LIMIT 3"); $pv->execute([$n['msisdn'],$n['offer_code'],$n['date']]);
            $preview=['n'=>$n,'body'=>refund_body($n),'earlier'=>$pv->fetchAll()]; $F['msisdn']=$n['msisdn']; $F['channel']=$n['channel'];
        } catch (RuntimeException $e) { flash('danger',$e->getMessage()); }
    }
    $okD=fn($d)=>is_string($d)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)&&strtotime($d)!==false;
    $dTo=$okD($_GET['to']??null)?min((string)$_GET['to'],date('Y-m-d')):date('Y-m-d'); $dFrom=$okD($_GET['from']??null)?(string)$_GET['from']:date('Y-m-d',strtotime($dTo.' -6 days')); if($dFrom>$dTo) $dFrom=$dTo;
    $lookupFull=refund_full_msisdn(trim((string)($_GET['msisdn']??$F['msisdn'])));
    $look=null; if($lookupFull!=='' && ($_SERVER['REQUEST_METHOD']==='GET' || $preview!==null) && strlen($lookupFull)>=9){ $look=refund_lookup($schema,$lookupFull,$dFrom,$dTo); }
    if ($F['offer_code']!=='' && $F['vendor']==='') $F['vendor']=refund_vendor_for($schema,$F['offer_code']);
    $missFrom=$okD($_GET['mfrom']??null)?(string)$_GET['mfrom']:date('Y-m-d'); $missTo=$okD($_GET['mto']??null)?(string)$_GET['mto']:date('Y-m-d'); $miss=null; $missErr='';
    if(isset($_GET['miss'])){ try { $miss=refund_missing($schema,$missFrom,$missTo,100); } catch (Throwable $e) { $missErr=$e->getMessage(); } }
    $hist=refund_history(25);
    layout_start('Refunds'); ussd_subnav('refunds');
    $fill=fn(array $x)=>e(json_encode($x,JSON_UNESCAPED_SLASHES));
    ?>
    <?php if($mode==='off'):?><div class="alert alert-secondary py-2">Refunds are <b>switched off</b>: you can investigate, but nothing can be sent. Turn on <b>Test</b> or <b>Live</b> under <a href="?page=ussd_proxy">USSD Proxy → Buying offers → Refunds</a>.</div>
    <?php elseif($mode==='test'):?><div class="alert alert-info py-2">Refunds are in <b>Test</b> mode: a refund is recorded and <b>nothing is sent to Hera</b>.</div>
    <?php else:?><div class="alert alert-warning py-2">Refunds are <b>Live</b>: <b>Send refund</b> asks Hera now (<?=e(parse_url((string)$cfg['refund_url'],PHP_URL_HOST))?>), and a refund that goes through cannot be sent again.</div><?php endif;?>
    <div class="cardx mb-3"><h3 class="mb-2"><i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>Deducted but not received</h3>
        <p class="small text-muted mb-2">Everyone whose balance was charged but whose bundle was refused by the policy system, newest first (the platform log of <b><?=e($schema)?></b>, up to <?=REFUND_MISSING_MAX_DAYS?> days at a time). Press <b>Use</b> on a row to open its refund preview.</p>
        <form method="get" class="row g-2 align-items-end"><input type="hidden" name="page" value="refunds"><input type="hidden" name="miss" value="1">
            <div class="col-md-3"><label class="small text-muted mb-0">From</label><input type="date" class="form-control" name="mfrom" value="<?=e($missFrom)?>" max="<?=e(date('Y-m-d'))?>"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">To</label><input type="date" class="form-control" name="mto" value="<?=e($missTo)?>" max="<?=e(date('Y-m-d'))?>"></div>
            <div class="col-md-3"><button class="btn btn-danger w-100"><i class="fa-solid fa-rotate me-1"></i><?=$miss!==null?'Refresh the list':'Find them'?></button></div></form>
        <?php if($missErr!==''):?><div class="alert alert-warning py-1 mt-2 mb-0 small"><?=e($missErr)?></div><?php endif;?>
        <?php if($miss!==null):?><?php $todo=array_values(array_filter($miss,fn($m)=>!$m['refunded']));?>
        <p class="small mt-2 mb-1"><b><?=count($todo)?></b> still to refund<?=count($miss)-count($todo)?', <span class="text-muted">'.(count($miss)-count($todo)).' already refunded</span>':''?>.</p>
        <?php if(!$miss):?><p class="small text-muted mb-0">None found for these dates.</p><?php else:?><div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>Charged at</th><th>Number</th><th>Offer</th><th>Channel</th><th>Taken</th><th>Why the bundle was refused</th><th></th></tr></thead><tbody>
            <?php foreach($miss as $m):?><tr class="<?=$m['refunded']?'text-muted':''?>"><td class="text-nowrap"><?=e($m['date'])?></td><td><?=e($m['msisdn'])?></td><td><?=e($m['offer_code'])?></td><td><?=e($m['channel'])?></td><td class="text-nowrap" title="<?=$m['taken_d']!==null?'balance '.e(number_format((float)$m['old_d'],2)).' → '.e(number_format((float)$m['new_d'],2)).', deduction serial '.e($m['serial']):''?>"><?=$m['taken_d']!==null?'D'.e(rtrim(rtrim(number_format($m['taken_d'],2,'.',''),'0'),'.')):'—'?></td><td class="small"><?=e($m['reason'])?></td>
                <td><?php if($m['refunded']):?><span class="badge bg-success">refunded</span><?php else:?><button type="button" class="btn btn-sm btn-danger py-0" data-fill="<?=$fill(['offer_code'=>$m['offer_code'],'vendor'=>refund_vendor_for($schema,$m['offer_code']),'msisdn'=>ussd_local_number($m['msisdn']),'date'=>$m['date']])?>">Use</button><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
        <?php endif;?></div>
    <div class="cardx"><h3 class="mb-2"><span class="badge bg-primary me-2">1</span>Investigate</h3>
        <form method="get" class="row g-2 align-items-end"><input type="hidden" name="page" value="refunds">
            <div class="col-md-4"><label class="small text-muted mb-0">Customer's number</label><input class="form-control" name="msisdn" value="<?=e($lookupFull!==''?ussd_local_number($lookupFull):'')?>" placeholder="e.g. 6704843" inputmode="numeric"></div>
            <div class="col-md-2"><label class="small text-muted mb-0">From</label><input type="date" class="form-control" name="from" value="<?=e($dFrom)?>" max="<?=e(date('Y-m-d'))?>"></div>
            <div class="col-md-2"><label class="small text-muted mb-0">To</label><input type="date" class="form-control" name="to" value="<?=e($dTo)?>" max="<?=e(date('Y-m-d'))?>"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="fa-solid fa-magnifying-glass me-1"></i>Look</button></div>
            <div class="col-md-2 small text-muted">Reading <b><?=e($schema)?></b>. <b>Use</b> on a row fills the form below.</div></form>
        <div class="small text-muted mt-1">Set <b>From</b> to the day the bundle was bought — for an old purchase, go back as far as needed (subscriptions up to <?=REFUND_SUBSCRIPTION_MAX_DAYS?> days; the transaction log shows its newest 31 days of the range).</div>
        <?php if($look):?>
        <?php foreach($look['notes'] as $nt):?><div class="alert alert-info py-1 mt-2 mb-0 small"><?=e($nt)?></div><?php endforeach;?>
        <?php foreach($look['errors'] as $part=>$msg):?><div class="alert alert-warning py-1 mt-2 mb-0 small"><b><?=e(ucfirst($part))?>:</b> <?=e($msg)?></div><?php endforeach;?>
        <h6 class="mt-3">Purchases through the USSD menu <small class="text-muted">(this portal's ledger — as buyer or as the other number)</small></h6>
        <?php if(!$look['purchases']):?><p class="small text-muted mb-0">None.</p><?php else:?><div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th>Time</th><th>Buyer → other</th><th>Offer</th><th>Mode</th><th>Result</th><th>Refund</th><th></th></tr></thead><tbody>
            <?php foreach($look['purchases'] as $pu): $target=!empty($pu['recipient'])?$pu['recipient']:$pu['msisdn'];?><tr><td class="text-nowrap"><?=e($pu['created_at'])?></td><td><?=e(ussd_local_number((string)$pu['msisdn']))?><?=!empty($pu['recipient'])?' → '.e(ussd_local_number((string)$pu['recipient'])):''?></td><td><?=e($pu['offer_code'])?> <small class="text-muted"><?=e($pu['offer_name'])?></small></td><td><?=e($pu['mode'])?></td>
                <td><span class="badge <?=['ok'=>'bg-success','sent'=>'bg-primary','failed'=>'bg-danger','lowbal'=>'bg-warning text-dark','test'=>'bg-info text-dark'][$pu['status']]??'bg-secondary'?>"><?=e($pu['status'])?></span> <small class="text-muted"><?=e(mb_strimwidth((string)$pu['reply_text'],0,60,'…'))?></small><?=ussd_refund_not_charged($pu)?' <span class="badge bg-light text-dark border" title="Hera said nothing was taken">not charged</span>':''?></td>
                <td><?=e((string)($pu['refund_status']??''))?:'—'?></td><td><button type="button" class="btn btn-sm btn-outline-primary py-0" data-fill="<?=$fill(['offer_code'=>$pu['offer_code'],'vendor'=>refund_vendor_for($schema,(string)$pu['offer_code']),'msisdn'=>ussd_local_number((string)$target),'date'=>$pu['created_at'],'purchase_id'=>(int)$pu['id']])?>">Use</button></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
        <h6 class="mt-3">Subscriptions <small class="text-muted">(as the buyer)</small></h6>
        <?php if(!$look['subscriptions']):?><p class="small text-muted mb-0">None between <?=e($dFrom)?> and <?=e($dTo)?><?=isset($look['errors']['subscriptions'])?' (could not be read)':''?>.</p><?php else:?><div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th>Date</th><th>Receiver</th><th>Transaction</th><th>Type</th><th>Channel</th><th>Result</th><th></th></tr></thead><tbody>
            <?php foreach($look['subscriptions'] as $su):?><tr><td class="text-nowrap"><?=e($su['date'])?></td><td><?=e(ussd_local_number((string)$su['receiver_msisdn']))?></td><td class="small"><?=e($su['transaction_id'])?></td><td><?=e($su['subscription_type'])?></td><td><?=e($su['channel'])?></td><td class="small"><?=e(mb_strimwidth((string)$su['result_desc'],0,50,'…'))?></td>
                <td><button type="button" class="btn btn-sm btn-outline-primary py-0" data-fill="<?=$fill(['msisdn'=>ussd_local_number((string)(($su['receiver_msisdn']??'')!==''?$su['receiver_msisdn']:$su['subscriber_msisdn'])),'date'=>$su['date'],'offer_code'=>preg_match('/^\d+$/',(string)$su['subscription_type'])?(string)$su['subscription_type']:''])?>">Use</button></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
        <h6 class="mt-3">Purchases in the platform log <small class="text-muted">(was it deducted? did the bundle arrive?)</small></h6>
        <?php if($look['assessed']):?><div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th>Date</th><th>Offer</th><th>Channel</th><th>Deducted</th><th>Bundle received</th><th>What happened</th><th></th></tr></thead><tbody>
            <?php $vb=['deducted_not_received'=>['bg-danger','Deducted — NOT received'],'received'=>['bg-success','Received'],'deducted'=>['bg-secondary','Deducted'],'not_deducted'=>['bg-light text-dark border','Not deducted'],'refund'=>['bg-info text-dark','Refund']];
            foreach($look['assessed'] as $as):?><tr class="<?=$as['verdict']==='deducted_not_received'&&!$as['refunded']?'table-danger':''?>"><td class="text-nowrap"><?=e($as['date'])?></td><td><?=e($as['offer_code'])?></td><td><?=e($as['channel'])?></td>
                <td><?php if($as['deducted']===null):?>—<?php elseif(!$as['deducted']):?>no<?php else: $pr=refund_offer_row($schema,$as['offer_code'])['price'];?><b class="text-danger">yes</b><?php if($as['taken_d']!==null):?> <span title="balance <?=e(number_format((float)$as['old_d'],2))?> → <?=e(number_format((float)$as['new_d'],2))?>, deduction serial <?=e($as['serial'])?>">D<?=e(rtrim(rtrim(number_format($as['taken_d'],2,'.',''),'0'),'.'))?></span><?=($pr!==null&&abs($as['taken_d']-$pr)>0.009)?' <span class="badge bg-warning text-dark" title="The offer costs D'.e(rtrim(rtrim(number_format($pr,2,'.',''),'0'),'.')).' but the balance went down by a different amount">≠ price</span>':''?><?php endif;?><?php endif;?></td><td><?=$as['received']===null?'<span class="text-muted">not recorded</span>':($as['received']?'yes':'<b class="text-danger">NO</b>')?></td>
                <td><span class="badge <?=$vb[$as['verdict']][0]?>"><?=e($vb[$as['verdict']][1])?></span><?=$as['refunded']?' <span class="badge bg-success">refunded</span>':''?> <small class="text-muted"><?=e(mb_strimwidth($as['reason']!==''?$as['reason']:$as['ocs_result'],0,60,'…'))?></small></td>
                <td><?php if(in_array($as['verdict'],['deducted_not_received','deducted'],true)&&!$as['refunded']):?><button type="button" class="btn btn-sm <?=$as['verdict']==='deducted_not_received'?'btn-danger':'btn-outline-primary'?> py-0" data-fill="<?=$fill(['offer_code'=>$as['offer_code'],'vendor'=>refund_vendor_for($schema,$as['offer_code']),'msisdn'=>ussd_local_number($as['msisdn']),'date'=>$as['date']])?>">Use</button><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
        <p class="small text-muted">Deducted = the charging system took the money. Received = the policy system gave the bundle in the same second. <b>Use</b> opens the refund preview with everything filled in.</p>
        <?php endif;?>
        <h6 class="mt-3">All transactions <small class="text-muted">(the platform's own log; the offer and vendor come from the request it carried)</small></h6>
        <?php if(!$look['transactions']):?><p class="small text-muted mb-0">None between <?=e($dFrom)?> and <?=e($dTo)?><?=isset($look['errors']['transactions'])?' (could not be read)':''?>.</p><?php else:?><div class="table-scroll"><table class="table table-sm align-middle"><thead><tr><th>Date</th><th>Channel</th><th>Offer</th><th>Vendor</th><th>Result</th><th class="small">Transaction</th><th></th></tr></thead><tbody>
            <?php foreach($look['transactions'] as $tx):?><tr><td class="text-nowrap"><?=e($tx['create_date'])?></td><td><?=e($tx['channel'])?></td><td><?=e($tx['offer_code'])?:'—'?></td><td><?=e($tx['vendor'])?:'—'?></td><td><span class="badge <?=is_success_status($tx['result_status'])?'bg-success':'bg-danger'?>"><?=e($tx['result_status'])?></span> <small class="text-muted"><?=e(mb_strimwidth((string)$tx['result_description'],0,40,'…'))?></small></td><td class="small"><?=e($tx['transaction_id'])?></td>
                <td><?php if($tx['offer_code']!==''):?><button type="button" class="btn btn-sm btn-outline-primary py-0" data-fill="<?=$fill(['offer_code'=>$tx['offer_code'],'vendor'=>$tx['vendor']!==''?$tx['vendor']:refund_vendor_for($schema,$tx['offer_code']),'msisdn'=>ussd_local_number($lookupFull),'date'=>$tx['create_date']])?>">Use</button><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
        <?php endif;?></div>
    <div class="cardx mt-3" id="refundForm"><h3 class="mb-2"><span class="badge bg-primary me-2">2</span>Refund</h3>
        <form method="post" id="rf" class="row g-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="preview"><input type="hidden" name="purchase_id" value="<?=e((string)($purchaseId??''))?>">
            <div class="col-md-2"><label class="small text-muted mb-0">Offer code</label><input class="form-control" name="offer_code" value="<?=e($F['offer_code'])?>" placeholder="40004" required></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Vendor</label><input class="form-control" name="vendor" value="<?=e($F['vendor'])?>" placeholder="huawei" required></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Number (who got the bundle)</label><input class="form-control" name="msisdn" value="<?=e($F['msisdn'])?>" placeholder="6704843" inputmode="numeric" required></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Channel</label><input class="form-control" name="channel" value="<?=e($F['channel']?:'REF')?>" required></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Date it was bought</label><input class="form-control" name="date" value="<?=e($F['date'])?>" placeholder="2025-08-08 15:30:18" required></div>
            <div class="col-md-1 d-flex align-items-end"><button class="btn btn-outline-primary w-100">Preview</button></div></form>
        <div class="small text-muted mt-1">For a purchase made for another number, use the <b>other</b> number — the one that received the bundle.</div>
        <?php if($preview):?>
        <div class="border rounded p-3 mt-3 bg-light"><div class="fw-semibold mb-1">This is exactly what will be sent</div>
            <div class="small text-muted">POST <code><?=e($cfg['refund_url'])?></code> with your saved headers</div><pre class="mb-2 mt-1"><?=e($preview['body'])?></pre>
            <?php foreach($preview['earlier'] as $ev):?><div class="small"><span class="badge <?=['ok'=>'bg-success','test'=>'bg-info text-dark','failed'=>'bg-danger','pending'=>'bg-warning text-dark'][$ev['status']]??'bg-secondary'?>"><?=e($ev['status'])?></span> an earlier request for this number, offer and time — <?=e($ev['created_at'])?> <span class="text-muted"><?=e((string)$ev['note'])?></span></div><?php endforeach;?>
            <?php if($mode==='off'):?><div class="text-danger mt-2">Refunds are switched off — it cannot be sent.</div>
            <?php else:?><form method="post" class="mt-2" data-confirm="<?=e(($mode==='live'?'Ask Hera NOW to refund ':'Record a TEST refund of ').$preview['n']['offer_code'].' for '.$preview['n']['msisdn'].' (bought '.$preview['n']['date'].')? '.($mode==='live'?'A refund that goes through cannot be sent again.':'Nothing is sent.'))?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="send"><input type="hidden" name="purchase_id" value="<?=e((string)($purchaseId??''))?>">
                <?php foreach(['offer_code','vendor','msisdn','channel','date'] as $k):?><input type="hidden" name="<?=$k?>" value="<?=e($preview['n'][$k])?>"><?php endforeach;?>
                <button class="btn btn-danger"><i class="fa-solid fa-rotate-left me-1"></i><?=$mode==='live'?'Send refund to Hera':'Record test refund'?></button></form><?php endif;?></div>
        <?php endif;?></div>
    <div class="cardx table-card mt-3"><h3 class="px-3 pt-3 mb-2">Refund history</h3>
        <?php if(!$hist):?><p class="text-muted px-3 pb-3 mb-0">No refunds yet.</p><?php else:?><div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>By</th><th>Number</th><th>Offer</th><th>Vendor</th><th>Bought</th><th>Mode</th><th>Result</th><th>Hera said</th></tr></thead><tbody>
            <?php foreach($hist as $h):?><tr><td class="text-nowrap"><?=e($h['created_at'])?></td><td><?=e($h['created_by'])?></td><td><?=e($h['msisdn'])?></td><td><?=e($h['offer_code'])?></td><td><?=e($h['vendor'])?></td><td class="text-nowrap"><?=e($h['sub_date'])?></td><td><?=e($h['mode'])?></td><td><span class="badge <?=['ok'=>'bg-success','test'=>'bg-info text-dark','failed'=>'bg-danger','pending'=>'bg-warning text-dark','sent'=>'bg-warning text-dark'][$h['status']]??'bg-secondary'?>"><?=e($h['status'])?></span></td><td class="small" title="<?=e((string)$h['request_body'])?> → <?=e((string)$h['response'])?>"><?=e((string)$h['note'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div>
    <script nonce="<?=e(csp_nonce())?>">
    document.querySelectorAll('[data-fill]').forEach(function(b){b.addEventListener('click',function(){var d=JSON.parse(b.dataset.fill),f=document.getElementById('rf');['offer_code','vendor','msisdn','date'].forEach(function(k){if(d[k]!==undefined&&d[k]!=='')f.elements[k].value=d[k];});f.elements['purchase_id'].value=d.purchase_id||'';f.elements['channel'].value='REF';f.submit();});});
    </script>
    <?php layout_end(); exit;
