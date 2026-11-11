<?php
declare(strict_types=1);
// Page: ?page=ussd_menus — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); try { menu_apply_schedules(); } catch (Throwable $e) { error_log('menu schedule: '.$e->getMessage()); } $canEdit=can('manage_ussd_menus'); $canUrl=can('manage_api_keys'); $db=portal_pdo(); $cfg=ussd_proxy_config();
    $form=['short_code'=>trim((string)($_GET['short_code']??'')),'name'=>trim((string)($_GET['name']??'')),'items'=>'','copy_from'=>'']; $formOpen=isset($_GET['add']);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_perm('manage_ussd_menus'); $do=(string)($_POST['do']??'create');
        if ($do==='rename') {
            $old=trim((string)($_POST['old']??'')); $new=menu_normalize_shortcode((string)($_POST['short_code']??'')); $nm=trim((string)($_POST['name']??'')); $also=!empty($_POST['also_mobius']); $ext=!empty($_POST['extendable']);
            try {
                if ($also) require_perm('manage_api_keys'); // checked before anything is changed
                menu_update_identity($old,$new,$nm); $parts=['portal updated'];
                if ($also) { try { $mr=mobius_update_menu($cfg,(string)($_POST['menu_id']??''),$nm,$new,$ext); $parts[]='Mobius updated'; } catch (RuntimeException $e) { flash('warning','The portal was changed, but Mobius was not: '.$e->getMessage()); } }
                flash('success','Saved '.($new!==$old?$old.' → '.$new:$new).' ('.implode(', ',$parts).').');
            } catch (RuntimeException $e) { flash('danger',$e->getMessage()); redirect('?page=ussd_menus&edit='.urlencode($old).($also?'&mobius=1':'')); }
            redirect('?page=ussd_menus'.($also?'&mobius=1':''));
        }
        if ($do==='mobius_create') {
            require_perm('manage_api_keys');
            try { $mr=mobius_create_menu($cfg,trim((string)($_POST['name']??'')),(string)($_POST['short_code']??''),!empty($_POST['extendable']));
                if ($mr['state']==='ours') flash('success','Created in Mobius: '.($mr['menu']['shortcode']??'').' ('.($mr['menu']['name']??'').'). It calls this portal; dial it to test.');
                else flash('warning','Mobius accepted the request, but reading it back does not show it as ours ('.$mr['state'].') — check the menu in Mobius.'); }
            catch (RuntimeException $e) { flash('danger',$e->getMessage()); }
            redirect('?page=ussd_menus&mobius=1');
        }
        $form=['short_code'=>trim((string)($_POST['short_code']??'')),'name'=>trim((string)($_POST['name']??'')),'items'=>(string)($_POST['items']??''),'copy_from'=>trim((string)($_POST['copy_from']??''))];
        try {
            $r=menu_create($form['short_code'],$form['name'],$form['copy_from']===''?$form['items']:'');
            if($form['copy_from']!=='' && in_array($form['copy_from'],menu_shortcodes(),true)) { $r['items']=menu_copy_to($form['copy_from'],$r['code'],true); }
            flash('success','Menu '.$r['code'].' created'.($r['items']?' with '.$r['items'].' draft item'.($r['items']===1?'':'s'):'').'. Next: create it in Mobius with the values below, then build and switch it on.');
            redirect('?page=ussd_menus&setup='.urlencode($r['code']));
        } catch (RuntimeException $e) { flash('danger',$e->getMessage()); $formOpen=true; }
    }
    $reg=[]; foreach($db->query("SELECT * FROM portal_short_codes WHERE channel_type='USSD'")->fetchAll() as $r0) $reg[$r0['short_code']]=$r0;
    $ov=menu_overview(); $codes=array_values(array_unique(array_merge(array_keys($ov),array_keys($reg)))); sort($codes);
    $setup=trim((string)($_GET['setup']??'')); $setupOk=$setup!=='' && (isset($ov[$setup])||isset($reg[$setup]));
    $url=($canUrl && $cfg['token']!=='') ? 'ussd.php?t='.$cfg['token'].'&m=proxy' : '';
    // Mobius is read every time the page opens (kept for a minute; Refresh reads it again) — no more "Check Mobius" first.
    $mob=($canUrl && $cfg['mobius_user']!=='') ? mobius_list_menus_cached($cfg, isset($_GET['refresh'])) : null; $mobOk=$mob && $mob['ok']; $editCode=trim((string)($_GET['edit']??''));
    if (isset($_GET['refresh'])) redirect('?page=ussd_menus'.(isset($_GET['f'])?'&f='.urlencode((string)$_GET['f']):''));
    $canMobPage=nav_can_see('mobius_menus');
    // One list: every portal menu, and every menu in Mobius. A portal menu and the Mobius menu that calls it are one row.
    $rows=[]; $byBare=[];
    foreach($codes as $c){ $rows[]=['code'=>$c,'kind'=>'portal','p'=>$ov[$c]??null,'reg'=>$reg[$c]??null,'ms'=>$mobOk?mobius_menu_state($c,$mob['menus'],$cfg):null]; $byBare[rtrim($c,'#')]=true; }
    $openSched=[]; if($mobOk){ try{ foreach(mobius_sched_list(null,true) as $s) $openSched[$s['menu_id']][]=$s; }catch(Throwable $e){} }
    $twice=[]; if($mobOk) foreach($mob['menus'] as $m){ $b0=rtrim(trim((string)$m['shortcode']),'#'); if($b0!=='') $twice[$b0]=($twice[$b0]??0)+1; } // codes Mobius has on more than one menu
    if($mobOk) foreach($mob['menus'] as $m){ $bare=rtrim((string)$m['shortcode'],'#'); if($bare!=='' && isset($byBare[$bare])) continue;
        $kind=mobius_menu_kind($m,$cfg); if($kind==='portal' && $m['shortcodeType']==='MS_INITIATED') $kind='mobius';
        $rows[]=['code'=>(string)$m['shortcode'],'kind'=>$kind,'p'=>null,'reg'=>null,'ms'=>['state'=>in_array($kind,['other','push'],true)?'elsewhere':'ours','menu'=>$m]]; }
    usort($rows,fn($a,$b)=>[$a['code']==='',$a['code']]<=>[$b['code']==='',$b['code']] ?: strnatcmp($a['code'],$b['code'])); // by code; menus without a code (push) last
    $kinds=['all'=>['All',count($rows)],'portal'=>['Built in the portal',0],'mobius'=>['Built in Mobius',0],'push'=>['Push messages',0],'other'=>['Other services',0]]; foreach($rows as $r0) $kinds[$r0['kind']][1]++;
    $fk=(string)($_GET['f']??'all'); if(!isset($kinds[$fk])) $fk='all';
    layout_start('USSD Menus'); ussd_subnav('ussd_menus');
    ?>
    <div class="cardx">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div><h3 class="mb-0">Menus</h3><div class="small text-muted">Every USSD short code — built in the portal or in Mobius — and whether it answers customers.</div></div>
            <input class="form-control w-auto ms-auto" id="mnFilter" placeholder="Search name or short code…" style="min-width:16rem">
            <?php if($mob!==null):?><a class="btn btn-outline-secondary" href="?page=ussd_menus&refresh=1<?=$fk!=='all'?'&f='.$fk:''?>" title="Read Mobius again<?=$mobOk&&!empty($mob['at'])?' (last read '.date('H:i:s',(int)$mob['at']).')':''?>"><i class="fa-solid fa-arrows-rotate me-1"></i>Refresh</a><?php endif;?>
            <?php if($canEdit):?><a class="btn btn-primary" href="?page=ussd_new"><i class="fa-solid fa-plus me-1"></i>Create a menu</a><button class="btn btn-link btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#addMenu" title="The old form: name, code and items typed as lines">quick form</button><?php endif;?>
        </div>
        <?php if($canEdit):?>
        <div class="collapse <?=$formOpen?'show':''?> mt-3" id="addMenu">
            <form method="post" class="border rounded p-3 bg-light"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                <h5 class="mb-1">New menu</h5><p class="small text-muted mb-2">Fill this in and the portal creates the menu, puts it in the Short Code register and adds your items as <b>Drafts</b> (only you can see them until you switch them on). You then create the same short code in Mobius — the next screen shows exactly what to type there.</p>
                <div class="row g-2">
                    <div class="col-md-4"><label class="small text-muted mb-0">Name</label><input class="form-control" name="name" value="<?=e($form['name'])?>" placeholder="e.g. Data bundles" maxlength="150" required></div>
                    <div class="col-md-4"><label class="small text-muted mb-0">Short code</label><input class="form-control" name="short_code" value="<?=e($form['short_code'])?>" placeholder="*9606*7070#" maxlength="40" required></div>
                    <div class="col-md-4"><label class="small text-muted mb-0">Start from a copy of (optional)</label><select class="form-select" name="copy_from"><option value="">— nothing, I will type the items —</option><?php foreach(array_keys($ov) as $oc):?><option value="<?=e($oc)?>" <?=$form['copy_from']===$oc?'selected':''?>><?=e($oc)?> (<?=e($reg[$oc]['service_name']??'no name')?>)</option><?php endforeach;?></select></div>
                    <div class="col-12"><label class="small text-muted mb-0">Items — one per line: <code>Name</code> or <code>Name | type | extra</code> (leave empty to add them later in the Builder)</label>
                        <textarea class="form-control font-monospace" name="items" rows="8" placeholder="KAA Bundle&#10;Sakan 7 days   | catalog | Sakan 7 days&#10;Daily 1GB      | offer   | 40154&#10;How to play    | end     | Answer 5 questions, score 4+ to win&#10;Shared Bundle  | shared  | Seddo&#10;Buy for other  | other"><?=e($form['items'])?></textarea>
                        <div class="small text-muted mt-1">Types: a line with only a name is a <b>submenu</b>; <code>catalog</code> = every offer of a catalogue sub-category (separate several with ;); <code>offer</code> = one offer code; <code>end</code> = a message; <code>shared</code> = Shared Bundle; <code>other</code> = buy for another number; <code>quiz</code>; <code>flow</code> = a Service Flow.</div></div>
                </div>
                <div class="mt-3"><button class="btn btn-primary"><i class="fa-solid fa-check me-1"></i>Create menu</button> <button type="button" class="btn btn-link" data-bs-toggle="collapse" data-bs-target="#addMenu">Cancel</button></div>
            </form>
        </div>
        <?php endif;?>
    </div>

    <?php if($mob!==null && !$mobOk):?>
    <div class="alert alert-warning mt-3 mb-0 py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>Could not read Mobius, so only the portal's menus are listed: <?=e($mob['error'])?> <a href="?page=ussd_proxy">Check the Mobius connection</a> · <a href="?page=ussd_menus&refresh=1">Try again</a></div>
    <?php endif;?>
    <?php if($canEdit && $editCode!=='' && (isset($ov[$editCode])||isset($reg[$editCode]))): $erg=$reg[$editCode]??null; $ems=$mobOk?mobius_menu_state($editCode,$mob['menus'],$cfg):null;?>
    <div class="cardx mt-3 border-primary"><div class="d-flex justify-content-between"><h3 class="mb-2">Edit <?=e($editCode)?></h3><a class="btn-close" href="?page=ussd_menus<?=$mobOk?'&mobius=1':''?>" aria-label="Close"></a></div>
        <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="rename"><input type="hidden" name="old" value="<?=e($editCode)?>"><input type="hidden" name="menu_id" value="<?=e($ems&&$ems['state']==='ours'?($ems['menu']['menuID']??''):'')?>">
            <div class="col-md-5"><label class="small text-muted mb-0">Name</label><input class="form-control" name="name" value="<?=e($erg['service_name']??'')?>" maxlength="150" required></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Short code</label><input class="form-control" name="short_code" value="<?=e($editCode)?>" maxlength="40" required></div>
            <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100">Save</button></div>
            <div class="col-12 small">
                <?php if($ems && $ems['state']==='ours'):?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="also_mobius" value="1" id="alsoM" checked><label class="form-check-label" for="alsoM">Make the same change in Mobius (it is in Mobius as “<?=e($ems['menu']['name']??'')?>”)</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="extendable" value="1" id="extM" <?=!empty($ems['menu']['isExtendable'])?'checked':''?>><label class="form-check-label" for="extM">Extendable in Mobius — a dial such as <code><?=e(rtrim($editCode,'#'))?>#12</code> is treated as the menu followed by choices 1 and 2</label></div>
                <?php elseif($ems && $ems['state']==='elsewhere'):?><span class="text-muted">A menu with this short code exists in Mobius but does not call this portal, so it is not changed from here.</span>
                <?php elseif($ems):?><span class="text-muted">This short code is not in Mobius yet — use <b>Create</b> on its row after saving.</span>
                <?php else:?><span class="text-muted">Press <b>Check Mobius</b> first if you want to change the menu in Mobius at the same time.</span><?php endif;?>
                <div class="text-muted mt-1">Changing the short code moves every item, the history and the register entry. Customers must dial the new code afterwards — and Mobius must have the new code too, or the phone says “connection problem”.</div>
            </div>
        </form></div>
    <?php endif;?>
    <?php if($setupOk): $o=$ov[$setup]??['items'=>0,'active'=>0,'draft'=>0,'errors'=>0,'warns'=>0]; $stt=shortcode_state($setup,$cfg,$reg); $builder='?page=ussd_build&short_code='.urlencode($setup);
        $mname=$reg[$setup]['service_name']??$setup; $flat=menu_nodes_flat($setup); $labels=[]; foreach($flat as $fn) $labels[(int)$fn['id']]=$fn['prompt_text']; $dc=menu_direct_codes($setup); $lvl=substr_count(rtrim($setup,'#'),'*')+1;
        $first=array_filter($dc,fn($c)=>substr_count(rtrim($c,'#'),'*')===$lvl);
        $sms=$mobOk?mobius_menu_state($setup,$mob['menus'],$cfg):null; // how the code stands in Mobius, read live
        $mobStep=$sms===null?[null,'In Mobius','Mobius could not be read, so this is not checked.',null,null]
            :($sms['state']==='ours'?[true,'In Mobius','as “'.($sms['menu']['name']??'').'”, calling this portal',null,null]
            :($sms['state']==='elsewhere'?[false,'In Mobius','the code is used in Mobius by “'.($sms['menu']['name']??'').'”, which does not call this portal — pick another code or change that menu in Mobius','?page=mobius_menus&view='.urlencode((string)($sms['menu']['menuID']??'')),'Open it']
            :[false,'In Mobius','not yet — press Create on its row in the list','?page=ussd_menus#row-'.md5($setup),'Go to its row']));
        $steps=[[$o['items']>0,'Items are built',$o['items'].' item'.($o['items']===1?'':'s'),$builder,'Open the Builder'],
            [$o['items']>0&&!$o['errors'],'Menu check is clean',$o['errors'].' to fix, '.$o['warns'].' to look at',$builder,'See the check'],
            [$o['active']>0&&!$o['draft'],'Items are switched on',$o['active'].' on, '.$o['draft'].' still draft',$builder,'Activate'],
            [isset($reg[$setup]),'In the Short Code register',isset($reg[$setup])?'as “'.$reg[$setup]['service_name'].'”':'not yet','?page=shortcodes&new='.urlencode($setup),'Register it'],
            $mobStep,
            ($sms && $sms['state']!=='ours') ? [false,'Answering on phones','not until the code is in Mobius and calls this portal (step above).',null,null]
                : [$stt['live'],'Answering on phones',$stt['live']?'The USSD endpoint is live.':'Not yet: '.$stt['why'].'.','?page=ussd_proxy','Open Proxy']];?>
    <div class="cardx mt-3 border-primary"><div class="d-flex justify-content-between"><h3 class="mb-2">Set up <?=e($setup)?> <small class="text-muted">— <?=e($mname)?></small></h3><a class="btn-close" href="?page=ussd_menus" aria-label="Close"></a></div>
        <div class="row g-3"><div class="col-lg-7"><ul class="list-unstyled mb-0">
            <?php foreach($steps as [$ok,$tt,$dd,$lk,$lt]):?><li class="py-1 d-flex gap-2"><span class="badge <?=$ok===null?'bg-secondary':($ok?'bg-success':'bg-warning text-dark')?> mt-1" style="min-width:4.2rem"><?=$ok===null?'unknown':($ok?'done':'to do')?></span><span><b><?=e($tt)?></b> <span class="text-muted"><?=e($dd)?></span><?php if($lk&&!$ok):?> <a href="<?=e($lk)?>"><?=e($lt)?></a><?php endif;?></span></li><?php endforeach;?></ul>
            <?php if($first):?><div class="small mt-3"><b>Direct codes</b> — dial these to jump straight to an item (add them in Mobius too, or ask for a wildcard on <code><?=e(rtrim($setup,'#'))?>*</code>):<br><?php foreach($first as $nid=>$c):?><code><?=e($c)?></code> <span class="text-muted"><?=e($labels[$nid]??'')?></span><br><?php endforeach;?></div><?php endif;?></div>
        <?php if($sms===null || $sms['state']==='missing'):?><div class="col-lg-5"><div class="border rounded p-2 bg-light"><div class="fw-semibold mb-1">Or type it in Mobius yourself (Menus → Add new row)</div>
            <table class="table table-sm mb-0"><tbody><tr><th class="text-muted fw-normal">Name</th><td><?=e($mname)?></td></tr><tr><th class="text-muted fw-normal">Shortcode</th><td><code><?=e($setup)?></code></td></tr><tr><th class="text-muted fw-normal">Extendable</th><td>false</td></tr><tr><th class="text-muted fw-normal">Type</th><td>PROXY</td></tr>
            <tr><th class="text-muted fw-normal">URL</th><td><?php if($url):?><button class="btn btn-sm btn-outline-primary" type="button" data-copy-url="<?=e($url)?>"><i class="fa-regular fa-copy me-1"></i>Copy the URL</button> <small class="text-muted">secret — do not share</small><?php else:?><span class="text-muted">an admin can copy it from Proxy → Connection</span><?php endif;?></td></tr></tbody></table></div></div><?php endif;?></div>
    </div>
    <?php endif;?>

    <div class="d-flex flex-wrap gap-2 align-items-center mt-3 mb-2" id="mnKinds" role="group" aria-label="Show">
        <?php foreach($kinds as $k=>[$lb,$n]): if($k!=='all' && !$n) continue;?><button type="button" class="btn btn-sm <?=$k===$fk?'btn-dark':'btn-outline-secondary'?>" data-kind="<?=e($k)?>"><?=e($lb)?> <span class="badge rounded-pill <?=$k===$fk?'bg-light text-dark':'bg-secondary'?>"><?=$n?></span></button><?php endforeach;?>
        <?php if($mobOk && $canMobPage):?><a class="small ms-auto" href="?page=mobius_menus"><i class="fa-solid fa-server me-1"></i>More Mobius tools (history, calls, export)</a><?php endif;?>
    </div>
    <div class="cardx table-card"><div class="table-scroll"><table class="table table-hover align-middle mb-0" id="mnTable"><thead><tr><th>Name</th><th>Short code</th><th>Built in</th><th>What is built</th><th>On phones</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach($rows as $r): $c=$r['code']; $m=$r['p']; $rg=$r['reg']; $ms=$r['ms']; $mm=$ms['menu']??null; $kind=$r['kind']; $mid=(string)($mm['menuID']??''); $mq=urlencode($mid);
        $name=$rg['service_name']??($mm['name']??''); $stt=$kind==='portal'?shortcode_state($c,$cfg,$reg):null;
        $host=$mm?(string)(parse_url((string)$mm['remoteURL'],PHP_URL_HOST)??''):''; $sch=$mid!==''?($openSched[$mid]??[]):[];?>
        <tr id="row-<?=md5($c)?>" data-kind="<?=e($kind)?>" <?=$fk!=='all'&&$fk!==$kind?'hidden':''?>>
            <td><?php if($name!==''):?><strong><?=e($name)?></strong><?php else:?><span class="text-muted">(no name yet)</span><?php endif;?>
                <?php if($kind==='portal' && !$rg):?> <a class="small" href="?page=shortcodes&new=<?=urlencode($c)?>">name it</a><?php elseif($rg && strtolower((string)$rg['status'])!=='active'):?> <span class="badge status-<?=e(strtolower($rg['status']))?>"><?=e($rg['status'])?></span><?php endif;?></td>
            <td><?php if($c===''):?><span class="text-muted small">no code</span><?php else:?><code><?=e($c)?></code><?php endif;?><?php if($c!=='' && ($twice[rtrim($c,'#')]??0)>1):?> <span class="badge bg-warning text-dark" title="Mobius has <?=(int)$twice[rtrim($c,'#')]?> menus on this code — only one of them answers. Check them on the Mobius page.">same code ×<?=(int)$twice[rtrim($c,'#')]?></span><?php endif;?></td>
            <td><?php if($kind==='portal'):?><span class="badge mn-kind-portal"><i class="fa-solid fa-store me-1"></i>Portal</span>
                <?php elseif($kind==='mobius'):?><span class="badge mn-kind-mobius"><i class="fa-solid fa-sitemap me-1"></i>Mobius</span>
                <?php elseif($kind==='push'):?><span class="badge bg-warning-subtle text-warning-emphasis"><i class="fa-solid fa-bullhorn me-1"></i>Push message</span>
                <?php else:?><span class="badge bg-light text-dark border" title="A menu in Mobius that calls another system<?=$host!==''?' ('.e($host).')':''?>. Changed only on the Mobius page."><i class="fa-solid fa-plug me-1"></i>Other service</span><?php endif;?></td>
            <td><?php if($kind==='portal'):?>
                    <?php if(!$m):?><span class="badge bg-secondary">empty</span> <a class="small" href="?page=ussd_build&short_code=<?=urlencode($c)?>">build</a><?php else:?><?=$m['active']?> on<?=$m['draft']?' · '.$m['draft'].' draft':''?><?=$m['scheduled']?' · <span class="text-info" title="Go live by themselves"><i class="fa-regular fa-clock"></i> '.$m['scheduled'].' scheduled</span>':''?> <?php if($m['errors']):?><span class="badge bg-danger"><?=$m['errors']?> to fix</span><?php elseif($m['warns']):?><span class="badge bg-warning text-dark"><?=$m['warns']?> to check</span><?php else:?><span class="badge bg-success">ok</span><?php endif;?><?php endif;?>
                <?php elseif($kind==='mobius'):?><span class="text-muted small">menu of screens</span><?=!empty($mm['isExtendable'])?' <span class="badge bg-info text-dark">extendable</span>':''?>
                    <?php foreach($sch as $s):?><div class="small text-info"><i class="fa-regular fa-clock me-1"></i><?=$s['state']==='live'?'live on <code>'.e($s['live_code']).'</code>'.($s['end_at']?' until '.e(date('d M H:i',strtotime((string)$s['end_at']))):''):'goes live on <code>'.e($s['live_code']).'</code> '.e(date('d M H:i',strtotime((string)$s['start_at'])))?></div><?php endforeach;?>
                <?php elseif($kind==='push'):?><span class="text-muted small">sent to phones by the network<?=$host!==''?' · calls '.e($host):''?></span>
                <?php else:?><span class="text-muted small"><?=$host!==''?'calls '.e($host):'—'?></span><?php endif;?></td>
            <td><?php if($kind==='portal'):?>
                    <?php if(!$m && !($mobOk && $ms['state']==='ours')):?><span class="text-muted">— nothing built yet</span>
                    <?php elseif($mobOk && $ms['state']==='missing'):?><span class="badge bg-danger" title="Phones get “connection problem” until the code is in Mobius">not in Mobius</span><?php if($canEdit && $canUrl):?> <form method="post" class="d-inline" data-confirm="Create <?=e($c)?> in Mobius now? This adds a live menu to Mobius that calls this portal."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="mobius_create"><input type="hidden" name="short_code" value="<?=e($c)?>"><input type="hidden" name="name" value="<?=e($rg['service_name']??$c)?>"><button class="btn btn-sm btn-outline-primary py-0">Create</button></form><?php endif;?>
                    <?php elseif($mobOk && $ms['state']==='elsewhere'):?><span class="badge bg-warning text-dark" title="Mobius has this code as “<?=e($mm['name']??'')?>”, which does not call this portal">code used by another menu</span>
                    <?php elseif(!$m):?><span class="text-muted">— nothing to show yet</span>
                    <?php elseif($stt['live']):?><span class="badge bg-success">answering</span><?=$mobOk?'':' <span class="small text-muted">(Mobius not checked)</span>'?>
                    <?php else:?><span class="badge bg-secondary" title="<?=e($stt['why'])?>">not live</span><?php endif;?>
                <?php elseif($kind==='mobius'):?><?php if(str_starts_with($c,'*0*')):?><span class="badge bg-secondary" title="Moved off its short code by a schedule">parked</span><?php else:?><span class="badge bg-success">in Mobius</span><?php endif;?>
                <?php elseif($kind==='push'):?><span class="badge bg-light text-dark border">push</span>
                <?php else:?><span class="badge bg-light text-dark border">in Mobius</span><?php endif;?></td>
            <td class="text-end text-nowrap">
                <?php if($kind==='portal'):?><a class="btn btn-sm btn-primary" href="?page=ussd_build&short_code=<?=urlencode($c)?>" title="Open in the Easy Builder"><i class="fa-solid fa-wand-magic-sparkles"></i> Build</a>
                    <?php if($m):?><a class="btn btn-sm btn-outline-secondary" href="?page=ussd_sim&sc=<?=urlencode($c)?>" title="Try it like a phone"><i class="fa-solid fa-mobile-screen"></i></a><?php endif;?>
                    <?php if($canEdit && ($m || $rg)):?><a class="btn btn-sm btn-outline-secondary" href="?page=ussd_menus&edit=<?=urlencode($c)?>" title="Rename it or change its short code"><i class="fa-solid fa-pen"></i></a><?php endif;?>
                    <?php if($m || $rg):?><a class="btn btn-sm btn-outline-secondary" href="?page=ussd_menus&setup=<?=urlencode($c)?>" title="Setup checklist"><i class="fa-solid fa-list-check"></i></a><?php endif;?>
                    <?php if($url):?><button class="btn btn-sm btn-outline-secondary" type="button" data-copy-url="<?=e($url)?>" title="Copy the URL Mobius calls"><i class="fa-regular fa-copy"></i></button><?php endif;?>
                <?php elseif($canMobPage && $mid!==''):?>
                    <?php if($kind==='mobius'):?><a class="btn btn-sm btn-primary" href="?page=mobius_menus&steps=<?=$mq?>" title="Open its screens in the Builder"><i class="fa-solid fa-wand-magic-sparkles"></i> Build</a>
                        <a class="btn btn-sm btn-outline-secondary" href="?page=mobius_menus&try=<?=$mq?>" title="Try it like a phone"><i class="fa-solid fa-mobile-screen"></i></a>
                        <a class="btn btn-sm btn-outline-secondary" href="?page=mobius_menus&schedule=<?=$mq?>" title="Choose when it goes live"><i class="fa-regular fa-clock"></i></a><?php endif;?>
                    <a class="btn btn-sm btn-outline-secondary" href="?page=mobius_menus&view=<?=$mq?>" title="Open it on the Mobius page"><i class="fa-solid fa-up-right-from-square"></i></a>
                <?php endif;?></td></tr>
    <?php endforeach;?>
    <tr id="mnNone" <?=$rows && ($fk==='all' || $kinds[$fk][1])?'hidden':''?>><td colspan="6" class="text-muted p-3">No menus here yet. Press <b>Create a menu</b>.</td></tr>
    </tbody></table></div><div class="small text-muted px-3 py-2"><?=count($rows)?> short code<?=count($rows)===1?'':'s'?><?=$mobOk?' · Mobius read at '.date('H:i',(int)($mob['at']??time())):''?>.</div></div>
    <style>.mn-kind-portal{background:#e0ecff;color:#1d4ed8}.mn-kind-mobius{background:#ebe7fe;color:#6d28d9}</style>
    <script nonce="<?=e(csp_nonce())?>">
    (function(){ // search box + "Built in" buttons, together; the choice stays in the address so Refresh keeps it
        var kind=<?=json_encode($fk)?>, box=document.getElementById('mnFilter');
        function show(){ var q=(box&&box.value||'').toLowerCase().trim(), n=0;
            document.querySelectorAll('#mnTable tbody tr[data-kind]').forEach(function(r){ var ok=(kind==='all'||r.dataset.kind===kind)&&(q===''||r.textContent.toLowerCase().indexOf(q)>=0); r.hidden=!ok; if(ok) n++; });
            var none=document.getElementById('mnNone'); if(none) none.hidden=n>0; }
        box&&box.addEventListener('input',show);
        document.querySelectorAll('#mnKinds [data-kind]').forEach(function(b){ b.addEventListener('click',function(){ kind=b.dataset.kind;
            document.querySelectorAll('#mnKinds [data-kind]').forEach(function(x){ var on=x===b; x.className='btn btn-sm '+(on?'btn-dark':'btn-outline-secondary'); var bd=x.querySelector('.badge'); if(bd) bd.className='badge rounded-pill '+(on?'bg-light text-dark':'bg-secondary'); });
            var u=new window.URL(location.href); if(kind==='all') u.searchParams.delete('f'); else u.searchParams.set('f',kind); history.replaceState(null,'',u.href); show(); }); });
    })();
    document.querySelectorAll('[data-copy-url]').forEach(function(b){b.addEventListener('click',function(){var u=new URL(b.dataset.copyUrl,location.href).href,done=function(){var t=b.innerHTML;b.innerHTML='<i class="fa-solid fa-check"></i> Copied';setTimeout(function(){b.innerHTML=t;},1500);};if(navigator.clipboard){navigator.clipboard.writeText(u).then(done);}else{var x=document.createElement('textarea');x.value=u;document.body.appendChild(x);x.select();document.execCommand('copy');x.remove();done();}});});
    </script>
    <?php layout_end(); exit;
