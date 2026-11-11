<?php
declare(strict_types=1);
// Page: ?page=ussd_trace — "Trace a dial": a phone number's USSD sessions as Mobius recorded them (code dialled, menu, screens, answers,
// result) with what went wrong in plain words, plus the portal's purchases for that number — instead of searching the gateway logs.
// Included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_reports'); $cfg=ussd_proxy_config();
    $num=trim((string)($_GET['msisdn']??'')); $code=trim((string)($_GET['code']??'')); $period=(string)($_GET['period']??'1d');
    $periods=['1h'=>['last hour',3600],'1d'=>['last 24 hours',86400],'7d'=>['last 7 days',604800],'30d'=>['last 30 days',2592000]]; if(!isset($periods[$period])) $period='1d';
    $since=time()-$periods[$period][1]; $res=null; $buys=[];
    if ($num!=='') {
        if ($cfg['mobius_user']==='') $res=['ok'=>false,'error'=>'Mobius is not connected (USSD → Settings → Proxy → Connection).'];
        else {
            $res=mobius_number_calls($cfg,$num,$code,$since,100);
            $n=$res['msisdn']??mobius_norm_msisdn($num);
            audit('ussd_trace',null,'mobius_calls',substr($n,0,5).'…'.substr($n,-3),'Traced USSD dials'.($code!==''?' on '.$code:'').' ('.$periods[$period][0].')');
            try { ussd_purchase_table(); $local=preg_replace('/^220/','',$n);
                $st=portal_pdo()->prepare('SELECT * FROM ussd_purchases WHERE msisdn IN (?,?) AND created_at>=? ORDER BY id DESC LIMIT 50'); $st->execute([$n,$local,date('Y-m-d H:i:s',$since)]); $buys=$st->fetchAll(); } catch (Throwable $e) {}
        }
    }
    // One line for the whole search.
    $calls=$res['calls']??[]; $verdict=null;
    if ($res && $res['ok']) {
        if (!$calls) $verdict=['warning','No dial from this number reached Mobius in the '.$periods[$period][0].($code!==''?' on '.$code:'').'.',
            ($res['note']??'')!==''?$res['note']:'Check the number and the time. If the customer did dial, the network (MSC / HLR) did not send the dial to Mobius — the menu is not the problem.'];
        else { $last=$calls[0]['why']; $bad=count(array_filter($calls,fn($c)=>$c['why'][0]==='danger'));
            $verdict=[$last[0],'Last dial '.date('d M H:i',(int)$calls[0]['time']).': '.$last[1],$last[2].($bad>1?' ('.$bad.' of the '.count($calls).' sessions here had a problem.)':'')]; }
    }
    layout_start('Trace a dial'); ussd_subnav('ussd_trace');
    ?>
    <div class="cardx" style="max-width:1100px">
        <h3 class="mb-1"><i class="fa-solid fa-magnifying-glass-location me-2"></i>Trace a dial</h3>
        <p class="text-muted small mb-3">A customer says a USSD code did not work? Type their number: this shows every session Mobius recorded for it — the code dialled, the menu that answered, the screens and answers — and says in plain words what went wrong and where to look. No need to search the gateway logs. Every search is kept in the audit trail.</p>
        <form method="get" class="row g-2 align-items-end"><input type="hidden" name="page" value="ussd_trace">
            <div class="col-md-4"><label class="small text-muted mb-0">Phone number</label><input class="form-control form-control-lg font-monospace" name="msisdn" value="<?=e($num)?>" placeholder="220 3xx xxxx" inputmode="tel" autocomplete="off" required autofocus></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Short code they dialled <span class="text-muted">(better results)</span></label><input class="form-control form-control-lg font-monospace" name="code" value="<?=e($code)?>" placeholder="*606#" autocomplete="off"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">When</label><select class="form-select form-select-lg" name="period"><?php foreach($periods as $k=>[$lb]):?><option value="<?=$k?>" <?=$k===$period?'selected':''?>><?=e($lb)?></option><?php endforeach;?></select></div>
            <div class="col-md-2"><button class="btn btn-primary btn-lg w-100"><i class="fa-solid fa-magnifying-glass me-1"></i>Trace</button></div>
        </form>
    </div>

    <?php if($res && !$res['ok']):?><div class="alert alert-danger mt-3" style="max-width:1100px"><?=e($res['error'])?></div><?php endif;?>
    <?php if($verdict):?>
    <div class="alert alert-<?=e($verdict[0]==='secondary'?'light border':$verdict[0])?> mt-3" style="max-width:1100px"><div class="fw-semibold"><?=e($verdict[1])?></div><div class="small mt-1"><i class="fa-solid fa-arrow-right me-1"></i><?=e($verdict[2])?></div></div>
    <?php endif;?>

    <?php foreach($calls as $c): [$lv,$what,$where]=$c['why'];?>
    <div class="cardx mt-2 tr-call border-start border-4 border-<?=e($lv==='secondary'?'light':$lv)?>" style="max-width:1100px">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold"><?=$c['time']?e(date('d M Y H:i:s',(int)$c['time'])):'(time unknown)'?></span>
            <code class="fs-6"><?=e($c['request']!==''?$c['request']:'—')?></code>
            <?php if($c['menu_name']!==''):?><span class="text-muted">→ <?=e($c['menu_name'])?><?=$c['menu_code']!==''&&$c['menu_code']!==$c['request']?' <code>'.e($c['menu_code']).'</code>':''?></span><?php endif;?>
            <span class="badge bg-<?=e($lv==='secondary'?'secondary':$lv)?><?=$lv==='warning'?' text-dark':''?> ms-auto"><?=$lv==='success'?'answered':($lv==='secondary'?'not finished':'problem')?></span>
        </div>
        <div class="mt-1"><?=e($what)?> <span class="text-muted small"><?=e($where)?></span></div>
        <?php $legs=array_merge([['c'=>$c,'first'=>true]],array_map(fn($x)=>['c'=>$x,'first'=>false],$c['then']??[])); $nSteps=array_sum(array_map(fn($l)=>count($l['c']['steps']),$legs));
        if(count($legs)>1 || !empty($c['via_names'])):?><div class="small mt-1 text-muted"><i class="fa-solid fa-share me-1"></i>Journey: <?=e($c['menu_name']?:'menu')?><?php foreach($c['via_names']??[] as $vn):?> → <?=e($vn)?><?php endforeach;?><?php foreach($c['then']??[] as $t):?> → <?=e($t['menu_name']?:'another menu')?><?php endforeach;?> <span title="through “Go to menu” steps">(Go to menu)</span></div><?php endif;?>
        <?php if($nSteps):?><details class="mt-2"><summary class="small">The session, screen by screen (<?=$nSteps?>)</summary>
            <div class="tr-chat mt-2"><?php foreach($legs as $li=>$l): if(!$l['first']):?><div class="tr-jump small text-muted"><i class="fa-solid fa-share me-1"></i>went on to <b><?=e($l['c']['menu_name']?:'another menu')?></b><?=$l['c']['time']?' at '.e(date('H:i:s',(int)$l['c']['time'])):''?><?=$li===count($legs)-1?' — '.e($l['c']['why'][1]):''?></div><?php endif;?>
                <?php foreach($l['c']['steps'] as $s): if($s['message']==='') continue;?><div class="tr-msg <?=$s['mobile']?'tr-in':'tr-out'?>"><span class="small text-muted d-block"><?=$s['mobile']?'Customer typed':'Screen shown'?></span><?=nl2br(e($s['message']))?></div><?php endforeach;?>
            <?php endforeach;?></div></details><?php endif;?>
        <?php if($c['menu']!=='' && nav_can_see('mobius_menus')):?><div class="small mt-2"><a href="?page=mobius_menus&view=<?=urlencode($c['menu'])?>">Open the menu</a><?php if($c['menu_kind']==='mobius'):?> · <a href="?page=mobius_menus&steps=<?=urlencode($c['menu'])?>">its screens</a><?php elseif($c['menu_kind']==='portal' && menu_valid_shortcode(menu_normalize_shortcode($c['menu_code']))):?> · <a href="?page=ussd_sim&sc=<?=urlencode(menu_normalize_shortcode($c['menu_code']))?>">try it in the Simulator</a><?php endif;?></div><?php endif;?>
    </div>
    <?php endforeach;?>

    <?php if($buys):?>
    <div class="cardx mt-3" style="max-width:1100px"><h3 class="mb-2"><i class="fa-solid fa-cart-shopping me-2"></i>Purchases made through portal menus</h3>
        <div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>When</th><th>Offer</th><th>For</th><th>Result</th><th>What the customer saw</th></tr></thead><tbody>
        <?php foreach($buys as $b):?><tr><td class="text-nowrap"><?=e(date('d M H:i:s',strtotime((string)$b['created_at'])))?></td><td><?=e(($b['offer_name']??'')!==''?$b['offer_name']:$b['offer_code'])?> <span class="text-muted small"><?=e($b['offer_code'])?></span></td>
            <td><?=($b['recipient']??'')!==''&&$b['recipient']!==$b['msisdn']?'another number':'themselves'?></td>
            <td><span class="badge bg-<?=in_array(strtolower((string)$b['status']),['ok','success','done'],true)?'success':(strtolower((string)$b['status'])==='test'?'info':'danger')?>"><?=e($b['status'])?></span> <span class="text-muted small"><?=e($b['mode'])?><?=$b['http_code']?' · HTTP '.(int)$b['http_code']:''?><?=$b['ms']?' · '.(int)$b['ms'].' ms':''?></span></td>
            <td class="small"><?=e((string)($b['reply_text']??''))?></td></tr><?php endforeach;?></tbody></table></div></div>
    <?php endif;?>

    <?php if($num===''):?>
    <div class="cardx mt-3" style="max-width:1100px"><h3 class="mb-2">What the answers mean</h3><ul class="small mb-0">
        <li><b>Mobius answered and the session ended normally</b>, but the customer saw “Connection problem / invalid MMI code” → the reply was lost after Mobius, in the network (STP / HLR / MSC).</li>
        <li><b>No dial reached Mobius</b> → the network did not send it (wrong number, or MSC / HLR routing) — or search again with the short code.</li>
        <li><b>No answer in time</b> → the screen came too late: the portal, the partner app or a “Call a service” step was slow.</li>
        <li><b>No menu on the code</b> → the code is not in Mobius, or the network changed it on the way (missing *, extra #).</li></ul></div>
    <?php endif;?>
    <style>.tr-chat{display:flex;flex-direction:column;gap:.4rem;max-width:560px}.tr-msg{padding:.45rem .7rem;border-radius:12px;white-space:normal}
    .tr-out{background:#f1f5f9;align-self:flex-start}.tr-in{background:#dcfce7;align-self:flex-end;text-align:right}.tr-jump{align-self:center;border-top:1px dashed #cbd5e1;padding-top:.3rem;width:100%;text-align:center}</style>
    <?php layout_end(); exit;
