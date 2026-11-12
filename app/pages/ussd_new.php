<?php
declare(strict_types=1);
// Page: ?page=ussd_new — "Create a menu": the one way to start any USSD menu. Name + short code, what it should do, how it starts; then the right builder opens.
//  * In the portal (offers, bundles, balance, quiz, flows): the portal menu is created and the Mobius side (a PROXY menu calling the portal) is made at once.
//  * In Mobius (menus of messages, questions and services): an MS_INITIATED menu is created in Mobius with its first screens, or a copy of another menu's steps.
// Included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_ussd_menus'); $cfg=ussd_proxy_config(); $canMobius=can('manage_api_keys') && $cfg['mobius_user']!=='';
    $list=$canMobius?mobius_list_menus($cfg):['ok'=>false,'menus'=>[],'error'=>'not connected']; $mob=$list['ok']?$list['menus']:[];
    if (isset($_GET['check'])) { // live check of a short code while typing
        header('Content-Type: application/json'); $c=menu_normalize_shortcode((string)$_GET['check']); $t=ussd_code_taken($c,$mob);
        echo json_encode(['code'=>$c,'valid'=>(bool)preg_match('/^\*[0-9*]{1,38}#$/',$c),'portal_ok'=>menu_valid_shortcode($c),'portal'=>$t['portal'],'mobius'=>$t['mobius']?['name'=>$t['mobius']['name'],'id'=>$t['mobius']['menuID']]:null]); exit;
    }
    $f=['name'=>trim((string)($_GET['name']??'')),'code'=>trim((string)($_GET['code']??'')),'where'=>($_GET['where']??'')==='mobius'?'mobius':'portal','start'=>'starter','starter'=>'options','copy_portal'=>'','copy_mobius'=>''];
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        foreach ($f as $k=>$v) $f[$k]=trim((string)($_POST[$k]??$v));
        try {
            $name=$f['name']; $code=menu_normalize_shortcode($f['code']);
            if ($name==='' || mb_strlen($name)>150) throw new RuntimeException('Give the menu a name, for example "Data bundles".');
            if (!preg_match('/^\*[0-9*]{1,38}#$/',$code)) throw new RuntimeException('A short code looks like *9606*7070# — a *, numbers (more *numbers if you like), ending with #.');
            $t=ussd_code_taken($code,$mob); if ($t['portal'] || $t['mobius']) throw new RuntimeException($code.' is already used'.($t['mobius']?' in Mobius by “'.$t['mobius']['name'].'”':' in the portal').'. Pick another short code.');
            if ($f['where']==='portal') {
                $r=menu_create($code,$name,''); $items=0;
                if ($f['start']==='copy' && $f['copy_portal']!=='' && in_array($f['copy_portal'],menu_shortcodes(),true)) $items=menu_copy_to($f['copy_portal'],$r['code'],true);
                $msg='Created '.$r['code'].' — “'.$name.'” in the portal'.($items?' with '.$items.' draft items copied':'').'.';
                if ($canMobius) { try { $mr=mobius_create_menu($cfg,$name,$r['code']); $msg.=' Mobius is ready too: '.$r['code'].' calls the portal.'; }
                    catch (RuntimeException $e) { flash('warning','The menu is in the portal, but Mobius was not set up: '.$e->getMessage().' You can do it later from USSD Menus → Check Mobius → Create.'); } }
                flash('success',$msg.(($cfg['env']??'prod')==='test'?' (Testbed: the Mobius side is on the testbed; the portal menu itself is shared with production — it is not live there until its code is in production Mobius.)':'').' Now add what customers will see, then press Publish.');
                redirect('?page=ussd_build&short_code='.urlencode($r['code']));
            }
            if ($f['where']==='mobius') {
                if (!$canMobius) throw new RuntimeException('Mobius is not connected (USSD Proxy → Connection), or you do not have the settings permission.');
                $start=$f['start']==='copy'?'copy':($f['start']==='empty'?'empty':$f['starter']);
                if ($start==='copy' && $f['copy_mobius']==='') throw new RuntimeException('Choose the menu to copy.');
                $r=mobius_create_built($cfg,$name,$code,$start,$f['copy_mobius']); $mid=(string)($r['menu']['menuID']??'');
                if ($r['steps']) flash('success','Created '.$code.' — “'.$name.'” in Mobius with its first screens. Change the texts in the Builder, test it with Try it, and use the Schedule tab to choose when it goes live.');
                elseif ($r['note']!=='') flash('warning','Created '.$code.' in Mobius, but its first screens could not be added ('.mb_strimwidth($r['note'],0,200,'…').'). Open “Open in Mobius Builder” on the next page to add the first step there; after that everything else works here.');
                else flash('success','Created '.$code.' — “'.$name.'” in Mobius. Press “Start: add the first screen” in the Builder.');
                redirect($mid!==''?'?page=mobius_menus&steps='.urlencode($mid):'?page=mobius_menus');
            }
            throw new RuntimeException('Choose what the menu should do.');
        } catch (RuntimeException $e) { flash('danger',$e->getMessage()); }
    }
    $portalMenus=menu_shortcodes(); $reg=[]; foreach(portal_pdo()->query("SELECT short_code,service_name FROM portal_short_codes WHERE channel_type='USSD'")->fetchAll() as $r0) $reg[$r0['short_code']]=$r0['service_name'];
    $mobBuilt=array_values(array_filter($mob,fn($m)=>$m['shortcodeType']==='MS_INITIATED')); usort($mobBuilt,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
    layout_start('Create a menu'); ussd_subnav('ussd_new');
    ?>
    <style>.cm-step{display:flex;gap:.75rem;align-items:flex-start}.cm-num{flex:none;width:2rem;height:2rem;border-radius:50%;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}
    .cm-choice{display:block;border:2px solid #e2e8f0;border-radius:12px;padding:1rem;cursor:pointer;height:100%;background:#fff}.cm-choice:hover{border-color:#cbd5e1}
    input:checked+.cm-choice{border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-light)}.cm-choice .fa-solid{font-size:1.4rem;color:var(--brand)}
    .cm-ok{color:#198754}.cm-bad{color:#dc3545}</style>
    <form method="post" class="cardx" id="cmForm" style="max-width:980px"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <h3 class="mb-1">Create a menu</h3><p class="text-muted mb-4">Three steps. The portal sets up everything it needs (in the portal and in Mobius) and then opens the right builder.</p>

        <div class="cm-step mb-4"><div class="cm-num">1</div><div class="flex-grow-1"><div class="fw-semibold mb-2">Name and short code</div>
            <div class="row g-2"><div class="col-md-6"><input class="form-control form-control-lg" name="name" value="<?=e($f['name'])?>" placeholder="Name, e.g. Data bundles" maxlength="150" required></div>
            <div class="col-md-6"><input class="form-control form-control-lg font-monospace" name="code" id="cmCode" value="<?=e($f['code'])?>" placeholder="*9606*7070#" maxlength="40" required autocomplete="off"><div class="small mt-1" id="cmCheck">Type the short code: the portal checks it is free.</div></div></div>
        </div></div>

        <div class="cm-step mb-4"><div class="cm-num">2</div><div class="flex-grow-1"><div class="fw-semibold mb-2">What should it do?</div>
            <div class="row g-2">
                <div class="col-md-6"><input class="btn-check" type="radio" name="where" value="portal" id="cmP" <?=$f['where']==='portal'?'checked':''?>><label class="cm-choice" for="cmP"><i class="fa-solid fa-store mb-2 d-block"></i><b>Sell offers, bundles, balance, quiz…</b><div class="small text-muted mt-1">Built in the portal with the Easy Builder: offers from the catalogue, Buy for another number, Shared Bundle, Check balance, Quiz, service flows. The Mobius side is set up for you.</div></label></div>
                <div class="col-md-6"><input class="btn-check" type="radio" name="where" value="mobius" id="cmM" <?=$f['where']==='mobius'?'checked':''?> <?=$canMobius?'':'disabled'?>><label class="cm-choice" for="cmM"><i class="fa-solid fa-sitemap mb-2 d-block"></i><b>Messages, questions and services</b><div class="small text-muted mt-1">Built in Mobius' Builder (from here): menus of choices, messages, questions, calls to a service (like Hera). Good for promotions and information codes.<?=$canMobius?'':' <span class="text-danger">Needs the Mobius connection and the settings permission.</span>'?></div></label></div>
            </div>
        </div></div>

        <div class="cm-step mb-4"><div class="cm-num">3</div><div class="flex-grow-1"><div class="fw-semibold mb-2">How does it start?</div>
            <div class="d-flex flex-wrap gap-3 mb-2">
                <div class="form-check"><input class="form-check-input" type="radio" name="start" value="empty" id="cs0" <?=$f['start']==='empty'?'checked':''?>><label class="form-check-label" for="cs0">Empty</label></div>
                <div class="form-check" data-where="mobius"><input class="form-check-input" type="radio" name="start" value="starter" id="cs1" <?=$f['start']==='starter'?'checked':''?>><label class="form-check-label" for="cs1">A ready example</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="start" value="copy" id="cs2" <?=$f['start']==='copy'?'checked':''?>><label class="form-check-label" for="cs2">A copy of another menu</label></div>
            </div>
            <div data-start="starter" data-where="mobius" class="row g-2"><?php foreach(mobius_starters() as $k=>[$lab,$desc]):?><div class="col-md-4"><input class="btn-check" type="radio" name="starter" value="<?=e($k)?>" id="st_<?=e($k)?>" <?=$f['starter']===$k?'checked':''?>><label class="cm-choice py-2" for="st_<?=e($k)?>"><b><?=e($lab)?></b><div class="small text-muted"><?=e($desc)?></div></label></div><?php endforeach;?></div>
            <div data-start="copy" data-where="portal"><select class="form-select" name="copy_portal"><option value="">— choose a portal menu —</option><?php foreach($portalMenus as $pc):?><option value="<?=e($pc)?>" <?=$f['copy_portal']===$pc?'selected':''?>><?=e($pc.(isset($reg[$pc])?' — '.$reg[$pc]:''))?></option><?php endforeach;?></select><div class="small text-muted mt-1">Its items are copied as Drafts; customers see nothing until you Publish.</div></div>
            <div data-start="copy" data-where="mobius"><select class="form-select" name="copy_mobius"><option value="">— choose a menu built in Mobius (<?=count($mobBuilt)?>) —</option><?php foreach($mobBuilt as $m):?><option value="<?=e($m['menuID'])?>" <?=$f['copy_mobius']===$m['menuID']?'selected':''?>><?=e($m['name'].($m['shortcode']!==''?' ('.$m['shortcode'].')':''))?></option><?php endforeach;?></select><div class="small text-muted mt-1">All its screens and services are copied to the new code; the original is not touched.</div></div>
        </div></div>

        <div class="d-flex align-items-center gap-3"><button class="btn btn-primary btn-lg"><i class="fa-solid fa-check me-1"></i>Create the menu</button><span class="small text-muted">Nothing is shown to customers until you publish (portal) or until it is ready (Mobius); you can undo from History.</span></div>
    </form>
    <script nonce="<?=e(csp_nonce())?>">
    (()=>{ const F=document.getElementById('cmForm'); let startTouched=false; F.querySelectorAll('[name=start]').forEach(r=>r.addEventListener('change',()=>startTouched=true));
      const sync=()=>{ const w=F.where.value; let s=F.start.value;
        if(w==='portal' && s==='starter'){ F.querySelector('#cs0').checked=true; s='empty'; } // the ready examples are for Mobius menus
        if(w==='mobius' && s==='empty' && !startTouched){ F.querySelector('#cs1').checked=true; s='starter'; } // a Mobius menu starts best from an example
        F.querySelectorAll('[data-where]').forEach(x=>{ const ok=x.dataset.where===w && (!x.dataset.start || x.dataset.start===s); x.hidden=!ok; x.querySelectorAll('input,select').forEach(i=>i.disabled=!ok); }); };
      F.addEventListener('change',sync); sync();
      const c=document.getElementById('cmCode'), out=document.getElementById('cmCheck'); let t;
      c.addEventListener('input',()=>{ clearTimeout(t); t=setTimeout(async()=>{ const v=c.value.trim(); if(!v){ out.textContent='Type the short code: the portal checks it is free.'; out.className='small mt-1'; return; }
        try{ const r=await (await fetch('?page=ussd_new&check='+encodeURIComponent(v))).json();
          if(!r.valid){ out.textContent='A short code looks like *9606*7070# (a *, numbers, ending with #).'; out.className='small mt-1 cm-bad'; }
          else if(r.portal||r.mobius){ out.innerHTML=''; out.className='small mt-1 cm-bad'; out.textContent=r.code+' is already used'+(r.mobius?' in Mobius by “'+r.mobius.name+'”':' in the portal')+'.'; }
          else { out.className='small mt-1 cm-ok'; out.textContent='✓ '+r.code+' is free'+(F.where.value==='portal'&&!r.portal_ok?' — but the portal needs digits between the * (like *9606*7070#)':'')+'.'; } }catch(e){} },350); });
    })();
    </script>
    <?php layout_end(); exit;
