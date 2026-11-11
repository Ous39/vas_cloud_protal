<?php
declare(strict_types=1);
// Page: ?page=ussd_trace — "Trace a number": a phone number's USSD sessions as Mobius recorded them (code dialled, menu, screens, answers,
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
    layout_start('Trace a number'); ussd_subnav('ussd_trace');
    ?>
    <div class="cardx" style="max-width:1100px">
        <h3 class="mb-1"><i class="fa-solid fa-magnifying-glass-location me-2"></i>Trace a number</h3>
        <p class="text-muted small mb-3">A customer says a USSD code did not work? Type their number: this shows every session Mobius recorded for it — the code dialled, the menu that answered, the screens and answers — and says in plain words what went wrong and where to look. No need to search the gateway logs. Every search is kept in the audit trail.</p>
        <form method="get" class="row g-2 align-items-end"><input type="hidden" name="page" value="ussd_trace">
            <div class="col-md-4"><label class="small text-muted mb-0">Phone number</label><input class="form-control form-control-lg font-monospace" name="msisdn" value="<?=e($num)?>" placeholder="866 123 456" inputmode="tel" autocomplete="off" required autofocus></div>
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

    <?php if($res && $res['ok'] && $num!==''): // the same sessions as log lines, and the grep commands for the gateway nodes
        $n9=substr(preg_replace('/\D+/','',$res['msisdn']??$num),-9); $lines=[];
        foreach(array_reverse($calls) as $c){ $legs=array_merge([$c],$c['then']??[]);
            $lines[]=date('Y-m-d H:i:s',(int)$c['time']).'  '.($res['msisdn']??'').'  DIAL '.$c['request'].'  -> menu "'.($c['menu_name']?:'?').'"'.($c['id']!==''?'  call='.$c['id']:'');
            foreach($legs as $i=>$l){ if($i>0) $lines[]=($l['time']?date('H:i:s',(int)$l['time']):'        ').'             GO TO MENU "'.($l['menu_name']?:'?').'"'.($l['id']!==''?'  call='.$l['id']:'');
                foreach($l['steps'] as $st){ if($st['message']==='') continue; $lines[]=($st['time']?date('H:i:s',(int)$st['time']):'        ').'             '.($st['mobile']?'<< typed  ':'>> screen ').str_replace(["\r","\n"],['',' | '],$st['message']); } }
            $end=end($legs); $lines[]=($end['error']!==''?'                     ERROR '.($end['code']!==null?$end['code'].' ':'').$end['error']:($end['completed']?'                     END (completed)':'                     END (not finished)'));
            $lines[]=''; }
        // grep commands: the files of the days in the period (today = network.log, older days = network.log.YYYY-MM-DD)
        $days=[]; for($t=$since;$t<=time()+60;$t+=86400) $days[]=date('Y-m-d',$t); $days[]=date('Y-m-d'); $days=array_values(array_unique($days));
        $files=fn($base)=>implode(' ',array_map(fn($d)=>$d===date('Y-m-d')?$base:$base.'.'.$d,count($days)>8?[]:$days)) ?: $base.'*';
        $cmd="# On each Mobius node (ussdapp01, ussdapp02) — read-only. Check the prompt first.\ncd /opt/ussd/log/\n"
            ."grep -h \"$n9\" ".$files('network.log')." | cut -c1-250\n"
            ."grep -h -A12 \"$n9\" ".$files('debug.log')." | grep -E \"Creating new call|processing step|Error|timed out|Sending\" | cut -c1-250\n";
        foreach(array_slice($calls,0,5) as $c){ $d=date('Y-m-d',(int)$c['time']); $f=$d===date('Y-m-d')?'network.log':'network.log.'.$d;
            $cmd.="\n# the dial of ".date('d M H:i',(int)$c['time'])." (".$c['request'].")\ngrep -h \"$n9\" $f | grep \"^".date('H:i',(int)$c['time'])."\" | cut -c1-250\n"; }?>
    <div class="cardx mt-3" style="max-width:1100px"><div class="d-flex flex-wrap gap-2 align-items-center mb-2"><h3 class="mb-0"><i class="fa-solid fa-terminal me-2"></i>As in the logs</h3>
        <div class="btn-group btn-group-sm ms-auto" role="group"><button type="button" class="btn btn-dark" data-trlog="tr-log">Log view</button><button type="button" class="btn btn-outline-secondary" data-trlog="tr-grep">grep on the nodes</button></div></div>
        <div id="tr-log"><p class="small text-muted mb-2">The sessions above, oldest first, one line per event — like reading <code>network.log</code> and <code>debug.log</code>, but already for this number only.</p>
            <pre class="tr-pre" id="tr-log-text"><?=e($lines?implode("\n",$lines):'(no sessions in this period)')?></pre><button type="button" class="btn btn-sm btn-outline-secondary" data-copy-from="tr-log-text"><i class="fa-regular fa-copy me-1"></i>Copy</button></div>
        <div id="tr-grep" hidden><p class="small text-muted mb-2">When you still need the gateway's own lines (for the vendor or the core network team): paste these on a node. They only read; the file names are the days of the period you chose.</p>
            <pre class="tr-pre" id="tr-grep-text"><?=e($cmd)?></pre><button type="button" class="btn btn-sm btn-outline-secondary" data-copy-from="tr-grep-text"><i class="fa-regular fa-copy me-1"></i>Copy the commands</button></div>
    </div>
    <script nonce="<?=e(csp_nonce())?>">
    document.querySelectorAll('[data-trlog]').forEach(function(b){ b.addEventListener('click',function(){ document.querySelectorAll('[data-trlog]').forEach(function(x){ var on=x===b; x.className='btn '+(on?'btn-dark':'btn-outline-secondary'); document.getElementById(x.dataset.trlog).hidden=!on; }); }); });
    document.querySelectorAll('[data-copy-from]').forEach(function(b){ b.addEventListener('click',function(){ var t=document.getElementById(b.dataset.copyFrom).textContent, old=b.innerHTML, ok=function(){ b.innerHTML='<i class="fa-solid fa-check me-1"></i>Copied'; setTimeout(function(){ b.innerHTML=old; },1500); };
        if(navigator.clipboard) navigator.clipboard.writeText(t).then(ok); else { var x=document.createElement('textarea'); x.value=t; document.body.appendChild(x); x.select(); document.execCommand('copy'); x.remove(); ok(); } }); });
    </script>
    <?php endif;?>

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
    .tr-out{background:#f1f5f9;align-self:flex-start}.tr-in{background:#dcfce7;align-self:flex-end;text-align:right}.tr-pre{background:#0f172a;color:#e2e8f0;border-radius:10px;padding:12px 14px;font-size:.8rem;line-height:1.5;max-height:420px;overflow:auto;white-space:pre}
    .tr-jump{align-self:center;border-top:1px dashed #cbd5e1;padding-top:.3rem;width:100%;text-align:center}</style>
    <?php layout_end(); exit;
