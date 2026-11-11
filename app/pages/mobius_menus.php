<?php
declare(strict_types=1);
// Page: ?page=mobius_menus — every menu in Mobius (USSD Shortcodes), with the same actions as Mobius: Builder (steps), Calls, Stats, Copy, Edit, Delete;
// plus create, send a code to this portal, History with Put back, export.
// Included by index.php inside its try/catch, after the sign-in, CSRF and $page checks. The rules are in app/lib/mobius.php (mobius_save_menu).

    require_perm('manage_api_keys'); $cfg=ussd_proxy_config();
    if ($_SERVER['REQUEST_METHOD']!=='POST') mobius_sched_tick($cfg); // schedules that are due start / end now
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $do=(string)($_POST['do']??''); $back='?page=mobius_menus';
        try {
            if ($do==='save') {
                $id=trim((string)($_POST['menu_id']??'')); $in=(array)($_POST['m']??[]);
                $r=mobius_save_menu($cfg,$id===''?null:$id,$in,(string)($_POST['confirm']??''));
                $m=$r['menu']; flash('success',($id===''?'Created in Mobius: ':'Saved in Mobius: ').($m['shortcode']??($in['shortcode']??'')).' — “'.($m['name']??($in['name']??'')).'”. You can put it back from History if needed.');
                if ($m && $m['menuID']!=='') $back.='&view='.urlencode((string)$m['menuID']);
            } elseif ($do==='to_portal') {
                $id=(string)($_POST['menu_id']??''); $r=mobius_point_to_portal($cfg,$id,(string)($_POST['confirm']??''));
                $code=(string)($r['menu']['shortcode']??''); $built=in_array(menu_normalize_shortcode($code),menu_shortcodes(),true);
                flash($built?'success':'warning','Now calls this portal: '.$code.'.'.($built?'':' The portal has no menu for '.$code.' yet, so callers will see “no menu” until you build one (Menus → Add new menu with the same short code).'));
                $back.='&view='.urlencode($id);
            } elseif ($do==='restore') {
                $r=mobius_restore_change($cfg,(int)($_POST['change_id']??0));
                if (!empty($r['removed'])) flash('success','Put back: the menu '.($r['menu']['shortcode']??'').' — “'.($r['menu']['name']??'').'” that the portal had created was removed from Mobius again.');
                elseif (!empty($r['steps'])) { flash('success','Step change undone in '.($r['menu']['shortcode']??'').' — “'.($r['menu']['name']??'').'”.'); $back.='&steps='.urlencode((string)$r['menu']['menuID']); }
                else { flash('success','Put back as it was: '.($r['menu']['shortcode']??'').' — “'.($r['menu']['name']??'').'”.'); if (!empty($r['menu']['menuID'])) $back.='&view='.urlencode((string)$r['menu']['menuID']); }
            } elseif ($do==='copy') {
                $id=(string)($_POST['menu_id']??''); $r=mobius_copy_menu($cfg,$id,(string)($_POST['shortcode']??''),(string)($_POST['name']??''),(string)($_POST['confirm']??''));
                flash('success','Copied into a new menu: '.($r['menu']['shortcode']??'').' — “'.($r['menu']['name']??'').'”. Put back in History removes the copy again.');
                $back.=!empty($r['menu']['menuID'])?'&view='.urlencode((string)$r['menu']['menuID']):'';
            } elseif ($do==='delete') {
                $r=mobius_delete_menu($cfg,(string)($_POST['menu_id']??''),(string)($_POST['confirm']??''));
                flash($r['gone']===false?'warning':'success',($r['gone']===false?'Mobius said yes, but the menu is still listed — check it in Mobius: ':'Deleted from Mobius: ').($r['menu']['shortcode']??'').' — “'.($r['menu']['name']??'').'”. Put back in History creates it again with the same settings.');
            } elseif ($do==='sched_add' || $do==='sched_stop') {
                $mid=(string)($_POST['menu_id']??''); $back.='&schedule='.urlencode($mid);
                if ($do==='sched_add') { mobius_sched_add($cfg,$mid,(string)($_POST['live_code']??''),(string)($_POST['start']??''),(string)($_POST['end']??'')); $r=mobius_sched_apply($cfg);
                    flash('success','Schedule saved.'.($r['started']?' It started now (the start time had come).':' It starts by itself at the time you chose (within about 5 minutes).')); }
                else { $how=mobius_sched_stop($cfg,(int)($_POST['id']??0)); flash('success',$how==='cancelled'?'Schedule cancelled: nothing was changed in Mobius.':'Ended now: the short codes are back as they were before.'); }
            } elseif (in_array($do,['unlock','step_save','step_delete'],true)) {
                $mid=(string)($_POST['menu_id']??''); $l0=mobius_list_menus($cfg); $mm=$l0['ok']?mobius_find_menu($l0['menus'],$mid):null; if(!$mm) throw new RuntimeException('That menu is no longer in Mobius.');
                $back.='&steps='.urlencode($mid);
                if ($do==='unlock') { mobius_steps_unlock($mm,(string)($_POST['confirm']??'')); flash('success','Editing unlocked for '.mobius_confirm_word($mm).' for 30 minutes. Every change goes live in Mobius at once and is kept in History (Put back).'); }
                elseif ($do==='step_save') { $how=(string)($_POST['how']??'edit'); $r=mobius_step_save($cfg,$mm,$how,(string)($_POST['at']??''),(array)($_POST['s']??[])); flash('success',($how==='edit'?'Step saved':'Step added').' in Mobius. Dial '.(mobius_confirm_word($mm)).' to try it; Put back in History undoes it.'); $back.='&focus='.urlencode($r['id']); }
                else { $r=mobius_step_delete($cfg,$mm,(string)($_POST['step']??'')); flash('success','Deleted '.$r['count'].' step'.($r['count']===1?'':'s').' from Mobius. Put back in History creates '.($r['count']===1?'it':'them').' again.'); }
            }
        } catch (RuntimeException $e) {
            flash('danger',$e->getMessage());
            if ($do==='step_save' && str_contains($e->getMessage(), MOBIUS_NO_CREATE)) { $_SESSION['flash']=array_values(array_filter($_SESSION['flash']??[],fn($f)=>$f['msg']!==$e->getMessage())); flash('warning','Mobius does not let the portal create new steps (its API answers with an error). Add this step in Mobius\' own Builder with + (the “Open in Mobius Builder” link below), press Save Menu there, then come back: edit, preview and History work here.'); }
            if ($do==='step_save' && !empty($_POST['menu_id'])) { $_SESSION['mobius_step_form']=(array)($_POST['s']??[]); $q=['op'=>($_POST['how']??'edit')==='edit'?'edit':'add','how'=>(string)($_POST['how']??''),'at'=>(string)($_POST['at']??'')]; $back.='&'.http_build_query($q); }
            if ($do==='save') { $_SESSION['mobius_form']=['menu_id'=>(string)($_POST['menu_id']??''),'m'=>(array)($_POST['m']??[])]; $back.=($_POST['menu_id']??'')!==''?'&edit='.urlencode((string)$_POST['menu_id']):'&new=1'; }
            elseif ($do==='sched_add'||$do==='sched_stop') $back.='&schedule='.urlencode((string)($_POST['menu_id']??''));
            elseif ($do==='copy'||$do==='delete') $back.='&'.$do.'='.urlencode((string)($_POST['menu_id']??''));
            elseif (!empty($_POST['menu_id'])) $back.='&view='.urlencode((string)$_POST['menu_id']);
        }
        redirect($back);
    }

    $list=mobius_list_menus($cfg); $ok=$list['ok']; $menus=$ok?$list['menus']:[];
    usort($menus,fn($a,$b)=>strnatcasecmp((string)$a['shortcode'],(string)$b['shortcode']));
    $exp=(string)($_GET['export']??'');
    if ($ok && ($exp==='json' || $exp==='csv')) {
        audit('export',null,'mobius',null,'Mobius menus '.$exp.' export ('.count($menus).')'); $fn='mobius_menus_'.date('Ymd_His');
        if ($exp==='json') { header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="'.$fn.'.json"'); echo json_encode(['generated_at'=>date('c'),'count'=>count($menus),'menus'=>mobius_menus_export($menus)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$fn.'.csv"'); $o=fopen('php://output','w'); fputcsv($o,['menuID','name','shortcode','type','extendable','address','destinations','calls this portal']);
        foreach ($menus as $m) fputcsv($o,[$m['menuID'],$m['name'],$m['shortcode'],$m['shortcodeType'],$m['isExtendable']?'true':'false',preg_replace('~([?&]t=)[^&]+~','$1…',$m['remoteURL']),implode(' ',$m['destinationID']),mobius_menu_is_ours($m,$cfg)?'yes':'no']);
        exit;
    }
    $portalCodes=array_flip(array_map(fn($c)=>rtrim($c,'#'),menu_shortcodes()));
    $viewId=trim((string)($_GET['view']??'')); $editId=trim((string)($_GET['edit']??'')); $isNew=isset($_GET['new']);
    $act=''; $actId=''; foreach(['calls','stats','steps','try','schedule','copy','delete'] as $a) if(trim((string)($_GET[$a]??''))!==''){ $act=$a; $actId=trim((string)$_GET[$a]); break; }
    $actMenu=$act!==''?mobius_find_menu($menus,$actId):null; $actData=null;
    if($actMenu && $act==='calls') $actData=mobius_menu_calls($cfg,$actMenu['menuID'],(string)($_GET['msisdn']??''),50,isset($_GET['from'])?(string)$_GET['from']:null);
    if($actMenu && $act==='stats') $actData=mobius_menu_stats($cfg,$actMenu['menuID'],(int)($_GET['max']??1000));
    if($actMenu && ($act==='steps' || $act==='try')) $actData=mobius_menu_steps($cfg,$actMenu['menuID']);
    $stepOp=(string)($_GET['op']??''); $stepAt=(string)($_GET['at']??''); $stepHow=(string)($_GET['how']??''); $stepForm=$_SESSION['mobius_step_form']??null; unset($_SESSION['mobius_step_form']);
    $view=$viewId!==''?mobius_find_menu($menus,$viewId):null; $edit=$editId!==''?mobius_find_menu($menus,$editId):null;
    $saved=$_SESSION['mobius_form']??null; unset($_SESSION['mobius_form']);
    $tpl=$ok?mobius_template_menu($menus,$cfg):null;
    $form=null; if ($edit || $isNew) {
        $base=$edit?mobius_menu_public($edit):['name'=>'','shortcode'=>'','shortcodeType'=>'PROXY','isExtendable'=>false,'remoteURL'=>$tpl['remoteURL']??'','destinationID'=>$tpl['destinationID']??[]];
        if ($saved && $saved['menu_id']===($edit['menuID']??'')) { $s=$saved['m']; $base=['name'=>$s['name']??'','shortcode'=>$s['shortcode']??'','shortcodeType'=>$s['shortcodeType']??'PROXY','isExtendable'=>!empty($s['isExtendable']),'remoteURL'=>$s['remoteURL']??'','destinationID'=>preg_split('/[\s,;]+/',(string)($s['destinationID']??''),-1,PREG_SPLIT_NO_EMPTY)]; }
        $form=$base;
        $parking=false; if ($edit && isset($_GET['park']) && !$saved) { // "Park it" from USSD Menus → Same code twice: a spare code, nothing saved until Save
            try { $form['shortcode']=mobius_sched_spare_code($menus,(string)$edit['shortcode']); $parking=true; } catch (RuntimeException $e) { flash('warning',$e->getMessage()); } }
    }
    $mk=mobius_menu_keys($menus)['keys']; $hasDest=$mk['destinationID']!==null; $hasExt=$mk['isExtendable']!==null;
    $dests=[]; foreach($menus as $m) foreach($m['destinationID'] as $d) $dests[$d]=($dests[$d]??0)+1; arsort($dests);
    $oursN=count(array_filter($menus,fn($m)=>mobius_menu_is_ours($m,$cfg)));
    $typeN=[]; foreach($menus as $m) $typeN[$m['shortcodeType']?:'—']=($typeN[$m['shortcodeType']?:'—']??0)+1; ksort($typeN);
    $destNames=$ok&&$hasDest?mobius_destination_names($cfg):[]; $destLabel=fn(array $ids)=>implode(', ',array_map(fn($d)=>$destNames[$d]??$d,$ids));
    $changes=mobius_changes(30);
    $hideUrl=fn(string $u)=>preg_replace('~([?&]t=)[^&]+~','$1…',$u);
    $focus=$act!=='' || $view || $form!==null; // one menu open: show it on its own, the list comes back with ← All menus
    layout_start('Mobius Menus'); ussd_subnav('mobius_menus');
    ?>
    <style>.mb-kv th{background:none!important;color:var(--muted)!important;text-transform:none!important;letter-spacing:0!important;font-size:.85rem!important;width:9rem;padding:6px 8px!important}#mbTable td{vertical-align:middle}#mbTable .text-truncate{max-width:26rem}
    .mbt-wrap{overflow:auto;padding:28px 20px;border:1px solid #e4e5e5;border-radius:4px;background:#fff;max-height:78vh;font-family:"Open Sans",system-ui,sans-serif}
    .mbt{display:flex;align-items:center}
    .mbt-kids{display:flex;flex-direction:column;margin-left:40px;position:relative}
    .mbt-kids::before{content:'';position:absolute;left:-40px;top:50%;width:40px;border-top:1px solid #c8cdd2}
    .mbt-kids>.mbt{position:relative;padding:5px 0 5px 20px}
    .mbt-kids>.mbt::before{content:'';position:absolute;left:0;top:50%;width:20px;border-top:1px solid #c8cdd2}
    .mbt-kids>.mbt::after{content:'';position:absolute;left:0;top:0;bottom:0;border-left:1px solid #c8cdd2}
    .mbt-kids>.mbt:first-child::after{top:50%}.mbt-kids>.mbt:last-child::after{bottom:50%}.mbt-kids>.mbt:only-child::after{display:none}
    .mbt-card{display:flex;align-items:center;gap:12px;background:#fdfeff;border:1px solid #e4e5e5;border-radius:3px;padding:14px;width:360px;flex:none;cursor:pointer;color:#515c66;font-size:14px;position:relative}
    .mbt-card:hover{border-color:#b9c3cc;background:#fff}.mbt-card.on{border-color:#1b74c5;box-shadow:0 0 0 2px rgba(27,116,197,.18)}
    .mbt-ref{border-style:dashed;opacity:.8}
    .mbt-code{font-size:20px;line-height:40px;min-width:1.4rem;text-align:center;color:#515c66}
    .mbt-ic{width:40px;height:40px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;flex:none;font-size:18px}
    .mbt-txt{display:flex;flex-direction:column;min-width:0;flex:1}.mbt-kind{font-size:14px;color:#515c66}
    .mbt-sub{font-weight:700;white-space:pre-line;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
    .mbt-dots{border:0;background:none;color:#7a858f;width:28px;height:28px;border-radius:50%;flex:none}.mbt-dots:hover{background:#eef1f4;color:#333}
    .mbt-plus{border:1px dashed #c8cdd2;background:none;color:#7a858f;text-decoration:none;border-radius:3px;padding:6px 12px;font-size:13px;display:inline-flex;align-items:center;gap:6px}.mbt-plus:hover{color:#1b74c5;border-color:#1b74c5}
    .mbt-side{position:sticky;top:84px}.mbt-hint{border:1px dashed #cdd5df;border-radius:8px;padding:.6rem;text-align:center;margin-bottom:.5rem}
    .mbt-phone{max-width:260px;background:#1f2933;color:#e9f5e1;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.8rem;border-radius:12px;padding:10px 12px;white-space:pre-wrap;border:6px solid #3a4652;min-height:3rem}
    .mbt-phone:empty::before{content:'(the screen text shows here)';color:#8a96a3}
    .mbt-sim{max-width:none;min-height:14rem;font-size:.9rem}.mbt-sim .sim-end{color:#f6c56b;display:block;margin-top:.5rem}.mbt-sim .sim-btn{margin:.15rem .2rem 0 0}</style>
    <?php if(!$focus): $kindN=['mobius'=>0,'portal'=>0,'push'=>0,'other'=>0]; foreach($menus as $m0) $kindN[mobius_menu_kind($m0,$cfg)]++;?>
    <div class="cardx">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="me-auto"><h3 class="mb-0">USSD menus in Mobius</h3><div class="small text-muted"><?=$ok?count($menus).' menus':'Mobius cannot be read right now'?> — open one to build it, try it, see its calls and numbers.</div></div>
            <a class="btn btn-primary" href="?page=ussd_new&where=mobius"><i class="fa-solid fa-plus me-1"></i>Create a menu</a>
            <div class="dropdown"><button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">More</button><ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="?page=mobius_menus"><i class="fa-solid fa-arrows-rotate fa-fw me-2"></i>Refresh from Mobius</a></li>
                <?php if($ok):?><li><a class="dropdown-item" href="?page=mobius_menus&export=json"><i class="fa-solid fa-download fa-fw me-2"></i>Backup of all menus (JSON)</a></li><li><a class="dropdown-item" href="?page=mobius_menus&export=csv"><i class="fa-solid fa-table fa-fw me-2"></i>List as CSV (Excel)</a></li><?php endif;?>
                <li><a class="dropdown-item" href="#mbHistory"><i class="fa-solid fa-clock-rotate-left fa-fw me-2"></i>History of changes</a></li>
                <li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="?page=mobius_menus&new=1"><i class="fa-solid fa-screwdriver-wrench fa-fw me-2"></i>Add a Mobius menu by hand (advanced)</a></li></ul></div>
        </div>
        <?php $openS=mobius_sched_list(null,true); if($openS):?><div class="alert alert-info py-2 mt-3 mb-0 small"><b><i class="fa-regular fa-clock me-1"></i>Scheduled:</b>
            <?php foreach($openS as $x): [$sl,$sc]=MOBIUS_SCHED_STATES[$x['state']];?><span class="d-inline-block me-3"><a href="?page=mobius_menus&schedule=<?=urlencode($x['menu_id'])?>"><?=e($x['menu_name'])?></a> → <code><?=e($x['live_code'])?></code> <span class="badge bg-<?=$sc?>"><?=e($sl)?></span> <?=$x['state']==='waiting'?'from '.e(substr($x['start_at'],0,16)):''?><?=$x['end_at']?' until '.e(substr($x['end_at'],0,16)):''?></span><?php endforeach;?></div><?php endif;?>
        <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
            <div class="position-relative flex-grow-1" style="max-width:32rem"><i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="left:.75rem;top:.7rem"></i><input class="form-control ps-5" id="mbFilter" placeholder="Search by name or short code…" autocomplete="off" value="<?=e((string)($_GET['q']??''))?>"></div>
            <div class="btn-group flex-wrap" role="group" id="mbKinds"><button type="button" class="btn btn-sm btn-outline-secondary active" data-kind="">All <span class="badge bg-light text-dark"><?=count($menus)?></span></button>
                <?php foreach(MOBIUS_KINDS as $k=>[$lab,$cls]): if(!$kindN[$k]) continue;?><button type="button" class="btn btn-sm btn-outline-secondary" data-kind="<?=$k?>"><?=e($lab)?> <span class="badge bg-light text-dark"><?=$kindN[$k]?></span></button><?php endforeach;?></div>
            <select class="d-none" id="mbWho"><option value=""></option></select>
        </div>
        <?php if(!$ok):?><div class="alert alert-danger mt-3 mb-0 py-2">Could not read Mobius: <?=e($list['error'])?> <a href="?page=ussd_proxy">Check the Mobius connection</a>.</div>
        <?php else: $short=$list['count']!==null && $list['count']!==count($menus);?>
        <?php if($short):?><div class="alert alert-warning mt-2 mb-0 py-2 small"><b>The list is not complete.</b> Mobius counts <?=(int)$list['count']?> menus but the portal could read <?=count($menus)?>. Open <i>Technical details</i> below and send it to whoever maintains the portal.</div><?php endif;?>
        <details class="mt-2"<?=$short?' open':''?>><summary class="small text-muted"><i class="fa-solid fa-circle-info me-1"></i>Technical details</summary>
            <p class="small mb-1 mt-2">Mobius counts <?=$list['count']!==null?(int)$list['count']:'?'?>; read <?=count($menus)?> (<?=e($list['shape'])?>). Session ids and the portal token are hidden below.</p>
            <?php foreach($list['diag'] as $d):?><div class="small mt-2"><b><?=e($d['call'])?></b><?=isset($d['rows'])?' — '.(int)$d['rows'].' menus read':''?></div><pre class="small bg-light border rounded p-2 mb-0" style="max-height:14rem;overflow:auto;white-space:pre-wrap"><?=e($d['answer'])?></pre><?php endforeach;?>
        </details>
        <?php endif;?>
    </div>
    <?php else:?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><a class="btn btn-outline-secondary" href="?page=mobius_menus"><i class="fa-solid fa-arrow-left me-1"></i>All menus</a><span class="small text-muted">Mobius → <?=count($menus)?> menus</span><?php if(!$ok):?><div class="alert alert-danger py-1 px-2 mb-0 small">Could not read Mobius: <?=e($list['error'])?></div><?php endif;?></div>
    <?php $cur=$actMenu?:($view?:$edit); if($cur): $ck=mobius_menu_kind($cur,$cfg); [$ckl,$ckc]=MOBIUS_KINDS[$ck]; $cq=urlencode($cur['menuID']); $tab=$act!==''?$act:($view?'view':'edit');
        $tabs=[['steps','fa-sitemap','Builder'],['try','fa-mobile-screen','Try it'],['calls','fa-phone','Calls'],['stats','fa-chart-column','Numbers'],['schedule','fa-regular fa-clock','Schedule'],['view','fa-gear','Settings'],['copy','fa-regular fa-copy','Copy']];
        if($ck!=='mobius') $tabs=array_values(array_filter($tabs,fn($t)=>!in_array($t[0],['try'],true)));?>
    <div class="cardx mb-0 pb-0"><div class="d-flex flex-wrap align-items-center gap-2"><div class="me-auto"><h3 class="mb-0"><?=e($cur['name'])?></h3><div class="small"><?=$cur['shortcode']!==''?'<code class="fs-6">'.e($cur['shortcode']).'</code>':'<span class="text-muted">no short code</span>'?> <span class="badge <?=$ckc?> ms-1"><?=e($ckl)?></span></div></div>
        <?php if($ck==='portal' && menu_valid_shortcode(menu_normalize_shortcode($cur['shortcode']))):?><a class="btn btn-sm btn-outline-success" href="?page=ussd_build&short_code=<?=urlencode(menu_normalize_shortcode($cur['shortcode']))?>"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Easy Builder</a><?php endif;?>
        <a class="btn btn-sm btn-outline-danger" href="?page=mobius_menus&delete=<?=$cq?>"><i class="fa-solid fa-trash me-1"></i>Delete</a></div>
        <ul class="nav nav-tabs mt-3 border-0"><?php foreach($tabs as [$t,$ic,$lb]): $href=$t==='view'?'?page=mobius_menus&view='.$cq:'?page=mobius_menus&'.$t.'='.$cq; $on=$tab===$t||($t==='view'&&$tab==='edit');?><li class="nav-item"><a class="nav-link<?=$on?' active fw-semibold':''?>" href="<?=e($href)?>"><i class="<?=str_contains($ic,'fa-regular')?$ic:'fa-solid '.$ic?> me-1"></i><?=e($lb)?></a></li><?php endforeach;?></ul></div>
    <?php endif;?>
    <?php endif;?>

    <?php if($form!==null): $other=$edit && !mobius_menu_is_ours($edit,$cfg);?>
    <div class="cardx mt-3 <?=$other?'border-danger':'border-primary'?>"><div class="d-flex justify-content-between"><h3 class="mb-2"><?=$edit?'Change '.e($edit['shortcode']).' in Mobius':'New menu in Mobius'?></h3><a class="btn-close" href="?page=mobius_menus<?=$edit?'&view='.urlencode($edit['menuID']):''?>" aria-label="Close"></a></div>
        <?php if($other):?><div class="alert alert-danger py-2 small"><b>This menu belongs to another service</b> (it does not call this portal). Customers dialling <code><?=e($edit['shortcode'])?></code> use it right now — a wrong address or code stops that service. The portal keeps the menu as it is now, so you can put it back from <b>History</b>.</div><?php endif;?>
        <?php if(!empty($parking)):?><div class="alert alert-warning py-2 small"><i class="fa-solid fa-square-parking me-1"></i><b>Parking</b>: the short code below is a spare one (<code><?=e($form['shortcode'])?></code>). Press <b>Save</b> to move this menu off <code><?=e($edit['shortcode'])?></code>, so the other menu on that code is the only one left. Nothing else changes; <b>Put back</b> in History moves it back.</div><?php endif;?>
        <form method="post" class="row g-2" id="mbForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save"><input type="hidden" name="menu_id" value="<?=e($edit['menuID']??'')?>">
            <div class="col-md-5"><label class="small text-muted mb-0">Name</label><input class="form-control" name="m[name]" value="<?=e($form['name'])?>" maxlength="150" required></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Short code</label><input class="form-control font-monospace" name="m[shortcode]" value="<?=e($form['shortcode'])?>" maxlength="40" placeholder="*9606*7070#"></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Type</label><select class="form-select" name="m[shortcodeType]"><?php foreach(array_unique(array_merge(MOBIUS_MENU_TYPES,[$form['shortcodeType']])) as $t): if($t==='') continue;?><option <?=$form['shortcodeType']===$t?'selected':''?>><?=e($t)?></option><?php endforeach;?></select></div>
            <div class="col-md-2 d-flex align-items-end"><?php if($hasExt):?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="m[isExtendable]" value="1" id="mbExt" <?=$form['isExtendable']?'checked':''?>><label class="form-check-label" for="mbExt">Extendable</label></div><?php endif;?></div>
            <div class="col-md-8"><label class="small text-muted mb-0">Address Mobius calls (remote URL)</label><input class="form-control font-monospace" name="m[remoteURL]" id="mbUrl" value="<?=e($form['remoteURL'])?>" maxlength="500" placeholder="https://…">
                <?php if($tpl):?><button type="button" class="btn btn-link btn-sm px-0" id="mbUsePortal" data-url="<?=e($tpl['remoteURL'])?>" data-dest="<?=e(implode(', ',$tpl['destinationID']))?>">Use this portal's address</button><?php endif;?></div>
            <?php if($hasDest):?><div class="col-md-4"><label class="small text-muted mb-0">Destination ids (comma separated)</label><input class="form-control font-monospace" name="m[destinationID]" id="mbDest" value="<?=e(implode(', ',$form['destinationID']))?>" list="mbDests"><datalist id="mbDests"><?php foreach($dests as $d=>$n):?><option value="<?=e($d)?>"><?=e($d)?> (<?=$n?> menus)</option><?php endforeach;?></datalist></div><?php endif;?>
            <div class="col-md-5"><label class="small text-muted mb-0">Confirm<?=$other?'':' (only needed when the menu does not call this portal)'?>: <?=$edit&&trim($edit['shortcode'])===''?'type its name <code>'.e($edit['name']).'</code>':'type the short code'.($edit?' <code>'.e($edit['shortcode']).'</code>':'')?></label><input class="form-control font-monospace" name="confirm" autocomplete="off" <?=$other?'required':''?>></div>
            <div class="col-md-3 d-flex align-items-end"><button class="btn <?=$other?'btn-danger':'btn-primary'?> w-100"><?=$edit?'Save in Mobius':'Create in Mobius'?></button></div>
            <div class="col-12 small text-muted">The change goes straight to Mobius (live). Fields Mobius has that are not shown here are sent back unchanged.</div>
        </form></div>
    <?php endif;?>

    <?php if($act!=='' && !$actMenu):?><div class="alert alert-warning mt-3">That menu is no longer in Mobius (refresh the list).</div><?php endif;?>
    <?php if($actMenu): $am=$actMenu; $amOurs=mobius_menu_is_ours($am,$cfg); $amWord=mobius_confirm_word($am); $base='?page=mobius_menus&'.$act.'='.urlencode($am['menuID']);
        $titles=['calls'=>'Calls','stats'=>'Numbers','steps'=>'Builder','try'=>'Try it','schedule'=>'Schedule: when this menu is live','copy'=>'Copy to a new short code','delete'=>'Delete this menu'];?>
    <div class="cardx mt-3 <?=$act==='delete'?'border-danger':'border-primary'?>"><div class="d-flex justify-content-between align-items-start"><h3 class="mb-2 h5"><?=e($titles[$act])?></h3></div>
    <?php if($act==='calls'): $cd=$actData;?>
        <form class="d-flex gap-2 mb-2" method="get"><input type="hidden" name="page" value="mobius_menus"><input type="hidden" name="calls" value="<?=e($am['menuID'])?>"><input class="form-control w-auto" name="msisdn" value="<?=e((string)($_GET['msisdn']??''))?>" placeholder="Number, e.g. 220866123456"><button class="btn btn-outline-primary">Filter</button><?php if(($_GET['msisdn']??'')!==''):?><a class="btn btn-link" href="?page=mobius_menus&calls=<?=urlencode($am['menuID'])?>">All numbers</a><?php endif;?></form>
        <?php if(!$cd['ok']):?><div class="alert alert-danger py-2">Mobius refused: <?=e($cd['error'])?></div>
        <?php else:?><p class="small mb-2"><?=$cd['total']!==null?'<b>'.number_format((int)$cd['total']).'</b> calls in Mobius for this menu'.(($_GET['msisdn']??'')!==''?' from that number':'').'. ':''?>Newest first, 50 a page. Times come from Mobius' record ids (server time).</p>
        <?php if(!$cd['calls']):?><p class="text-muted">No calls.</p><?php else:?>
        <div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>Number</th><th>Dialled</th><th>Customer typed</th><th>Result</th><th></th></tr></thead><tbody>
        <?php foreach($cd['calls'] as $i=>$c): $typed=array_values(array_map(fn($x)=>$x['message'],array_filter($c['steps'],fn($x)=>$x['mobile'])));?>
            <tr><td class="small text-nowrap"><?=$c['time']?e(date('Y-m-d H:i:s',$c['time'])):'—'?></td><td><code><?=e($c['msisdn'])?></code></td><td><code><?=e($c['request'])?></code></td><td class="small"><?=e(implode(' → ',$typed))?:'—'?></td>
                <td><?php if($c['error']!==''):?><span class="badge bg-danger"><?=e(mb_strimwidth($c['error'],0,40,'…'))?></span><?php elseif($c['completed']):?><span class="badge bg-success">completed</span><?php else:?><span class="badge bg-secondary">not finished</span><?php endif;?></td>
                <td><?php if($c['steps']):?><button class="btn btn-sm btn-link py-0" type="button" data-bs-toggle="collapse" data-bs-target="#cs<?=$i?>">screens</button><?php endif;?></td></tr>
            <?php if($c['steps']):?><tr class="collapse" id="cs<?=$i?>"><td colspan="6"><?php foreach($c['steps'] as $stp):?><div class="small <?=$stp['mobile']?'text-primary':''?>"><b><?=$stp['mobile']?'Customer':'Screen'?>:</b> <span style="white-space:pre-wrap"><?=e($stp['message'])?></span></div><?php endforeach;?><?php if($c['vars']):?><div class="small text-muted mt-1">Variables: <?=e(implode(', ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($c['vars']),$c['vars'])))?></div><?php endif;?></td></tr><?php endif;?>
        <?php endforeach;?></tbody></table></div>
        <div class="mt-2"><?php if(isset($_GET['from'])):?><a class="btn btn-sm btn-outline-secondary" href="?page=mobius_menus&calls=<?=urlencode($am['menuID'])?>&msisdn=<?=urlencode((string)($_GET['msisdn']??''))?>">Newest</a> <?php endif;?><?php if($cd['next']):?><a class="btn btn-sm btn-outline-secondary" href="?page=mobius_menus&calls=<?=urlencode($am['menuID'])?>&msisdn=<?=urlencode((string)($_GET['msisdn']??''))?>&from=<?=urlencode($cd['next'])?>">Older calls →</a><?php endif;?></div>
        <?php endif; endif;?>
    <?php elseif($act==='stats'): $sd=$actData;?>
        <?php if(!$sd['ok']):?><div class="alert alert-danger py-2">Mobius refused: <?=e($sd['error'])?></div>
        <?php elseif(!$sd['read']):?><p class="text-muted mb-0">No calls recorded for this menu<?=$sd['total']!==null?' (Mobius counts '.(int)$sd['total'].')':''?>.</p>
        <?php else: $pct=fn($n)=>$sd['read']?round($n*100/$sd['read']).'%':'—';?>
        <p class="small text-muted">From the <?=number_format($sd['read'])?> most recent calls<?=$sd['total']!==null&&$sd['total']>$sd['read']?' of '.number_format((int)$sd['total']).' in Mobius':''?><?=$sd['from']?', '.e(date('Y-m-d H:i',$sd['from'])).' to '.e(date('Y-m-d H:i',$sd['to'])):''?>.<?php if($sd['total']!==null&&$sd['total']>$sd['read']):?> <a href="?page=mobius_menus&stats=<?=urlencode($am['menuID'])?>&max=2000">Use up to 2,000</a><?php endif;?></p>
        <div class="row g-2 mb-3"><?php foreach([['Calls',number_format($sd['read'])],['Different numbers',number_format($sd['unique'])],['Completed',$pct($sd['completed'])],['With an error',$pct($sd['failed'])]] as [$k,$v]):?><div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted"><?=e($k)?></div><div class="fs-4 fw-semibold"><?=e($v)?></div></div></div><?php endforeach;?></div>
        <?php $mx=max($sd['days']?:[1]);?><div class="fw-semibold small mb-1">Calls per day</div>
        <div class="d-flex align-items-end gap-1 mb-3" style="height:9rem;overflow-x:auto"><?php foreach($sd['days'] as $d=>$n):?><div class="text-center" style="min-width:2.2rem" title="<?=e($d)?>: <?=$n?> calls"><div class="small"><?=$n?></div><div style="height:<?=max(2,round($n/$mx*100))?>px;background:var(--bs-primary,#0d6efd);border-radius:3px 3px 0 0"></div><div class="small text-muted" style="font-size:.65rem"><?=e(substr($d,5))?></div></div><?php endforeach;?></div>
        <div class="row g-3"><?php foreach([['Top numbers',$sd['numbers']],['What customers typed',$sd['answers']],['Errors',$sd['errors']]] as [$k,$list]): if(!$list) continue;?><div class="col-md-4"><div class="fw-semibold small mb-1"><?=e($k)?></div><table class="table table-sm mb-0"><tbody><?php foreach($list as $x=>$n):?><tr><td class="small"><?=e(mb_strimwidth((string)$x,0,40,'…'))?></td><td class="text-end small"><?=$n?></td></tr><?php endforeach;?></tbody></table></div><?php endforeach;?></div>
        <?php endif;?>
    <?php elseif($act==='steps'): $pd=$actData;?>
        <?php if($amOurs && menu_valid_shortcode(menu_normalize_shortcode($am['shortcode']))):?><div class="alert alert-info py-2 small">This menu calls the portal, so its screens are built in the portal: <a href="?page=ussd_build&short_code=<?=urlencode(menu_normalize_shortcode($am['shortcode']))?>">open it in Easy Builder</a>.</div><?php endif;?>
        <?php $unl=mobius_steps_unlocked($am['menuID']); $sb='?page=mobius_menus&steps='.urlencode($am['menuID']); $mbBase=mobius_bases($cfg)[0]??''; $mbBuilder=$mbBase!==''?preg_replace('~/rest/?$~','',$mbBase).'/ussd/ussdmenubuilder/'.rawurlencode($am['menuID']):'';
        if($mbBuilder!==''):?><div class="text-end mb-1"><a class="small" href="<?=e($mbBuilder)?>" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square me-1"></i>Open in Mobius Builder</a></div><?php endif;
        if($pd['ok']):?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2 p-2 rounded <?=$unl?'bg-success-subtle border border-success':'bg-light border'?>">
            <?php if($unl):?><i class="fa-solid fa-lock-open text-success"></i><span class="small"><b>Editing is on</b> until <?=e(date('H:i',(int)$_SESSION['mobius_unlock'][$am['menuID']]))?>. Click a card, then Edit, Delete or Add. Changes go live in Mobius at once; History → Put back undoes them.</span>
            <?php if(!$pd['steps']):?><a class="btn btn-sm btn-primary ms-auto" href="<?=e($sb)?>&op=add&how=first">Start: add the first screen</a><?php endif;?>
            <?php else:?><i class="fa-solid fa-lock text-muted"></i><span class="small">To change this menu's steps, unlock editing: type <?=trim($am['shortcode'])!==''?'its short code':'its name'?> <code><?=e($amWord)?></code>.</span>
            <form method="post" class="d-flex gap-2 ms-auto"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="unlock"><input type="hidden" name="menu_id" value="<?=e($am['menuID'])?>"><input class="form-control form-control-sm font-monospace" name="confirm" placeholder="<?=e($amWord)?>" required autocomplete="off" style="width:12rem"><button class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-lock-open me-1"></i>Unlock editing</button></form><?php endif;?>
        </div>
        <?php endif;?>
        <?php if(!$pd['ok']):?><div class="alert alert-danger py-2">Mobius refused: <?=e($pd['error'])?></div>
        <?php else: $menuNames=[]; foreach($menus as $mm) $menuNames[$mm['menuID']]=$mm['name'].($mm['shortcode']!==''?' ('.$mm['shortcode'].')':'');
            $steps=$pd['steps'];
            $card=function(array $node) use (&$card,$steps,$menuNames,$am,$unl,$sb): void {
                $st=$steps[$node['id']]; [$label,$icon,$col,$bg]=MOBIUS_STEP_KINDS[$st['type']]??[$st['type'],'fa-circle','#5f5f5f','#f4f4f4'];
                $sub=$st['type']==='WELCOME'?'Short code '.$am['shortcode']:mobius_step_summary($st,$steps,$menuNames);
                $tip=trim($st['message']."\n".($st['url']!==''?$st['url']:'')); ?>
                <?php $plus=[]; if($unl && !$node['ref']){ if(mobius_step_has_options($st['type'])) $plus[]=['option','Add option']; if(mobius_step_continues($st['type']) && !($st['next']!=='' && isset($steps[$st['next']]))) $plus[]=['next','Add next step']; } $sid=urlencode($st['id']);?>
                <div class="mbt"><div class="mbt-card<?=$node['ref']?' mbt-ref':''?>" tabindex="0" data-step="<?=e($st['id'])?>" title="<?=e($tip)?>">
                    <?php if($st['code']!==''):?><span class="mbt-code"><?=e($st['code'])?></span><?php endif;?>
                    <span class="mbt-ic" style="background:<?=$bg?>;color:<?=$col?>"><i class="fa-solid <?=$icon?>"></i></span>
                    <span class="mbt-txt"><span class="mbt-kind"><?=e($label)?><?=$node['ref']?' (drawn above)':''?></span><span class="mbt-sub"><?=e(mb_strimwidth($sub,0,140,'…'))?:'&nbsp;'?></span></span>
                    <div class="dropdown" data-nocard><button type="button" class="mbt-dots" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="Step actions"><i class="fa-solid fa-ellipsis-vertical"></i></button><ul class="dropdown-menu dropdown-menu-end">
                        <li><button type="button" class="dropdown-item" data-view="<?=e($st['id'])?>"><i class="fa-regular fa-eye me-2"></i>View</button></li>
                        <?php if($unl):?><li><a class="dropdown-item" href="<?=e($sb)?>&op=edit&at=<?=$sid?>"><i class="fa-solid fa-pen me-2"></i>Edit</a></li>
                        <?php foreach($plus as [$how,$lab]):?><li><a class="dropdown-item" href="<?=e($sb)?>&op=add&how=<?=$how?>&at=<?=$sid?>"><i class="fa-solid fa-plus me-2"></i><?=e($lab)?></a></li><?php endforeach;?>
                        <?php if(!in_array($st['type'],['WELCOME','WELCOME_INCOMING'],true)):?><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="<?=e($sb)?>&op=delete&at=<?=$sid?>"><i class="fa-solid fa-trash me-2"></i>Delete</a></li><?php endif;?>
                        <?php else:?><li><span class="dropdown-item-text small text-muted">Unlock editing to change it</span></li><?php endif;?></ul></div></div>
                    <?php if(($node['kids'] && !$node['ref']) || $plus):?><div class="mbt-kids"><?php if(!$node['ref']) foreach($node['kids'] as $k) $card($k); foreach($plus as [$how,$lab]):?><div class="mbt"><a class="mbt-plus" href="<?=e($sb)?>&op=add&how=<?=$how?>&at=<?=$sid?>"><i class="fa-solid fa-plus"></i><?=e($lab)?></a></div><?php endforeach;?></div><?php endif;?></div>
            <?php };?>
        <?php if(!$steps):?><p class="text-muted mb-0">Mobius has no steps for this menu<?=$am['shortcodeType']==='PROXY'?' (a PROXY menu takes its screens from its address).':'.'?></p><?php else:?>
        <p class="small text-muted mb-2"><?=count($steps)?> steps, laid out like Mobius' Builder: the menu starts on the left; the number is the key the customer presses. <?=$unl?'Click a card to edit it; <i class="fa-solid fa-ellipsis-vertical"></i> for View, Add and Delete.':'Click a card to see the step.'?></p>
        <div class="mbt-wrap"><?php foreach($pd['tree'] as $root) $card($root);?></div><?php endif;?>
        <div class="modal fade" id="mbtModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title h6" id="mbtModalTitle">Step</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">
        <div id="mbtDetail" class="small" data-unlocked="<?=$unl?'1':''?>" data-base="<?=e($sb)?>" data-focus="<?=e((string)($_GET['focus']??($_GET['at']??'')))?>"></div></div></div></div></div>
        <script type="application/json" id="mbtData"><?=json_encode(array_map(function($st) use ($steps,$menuNames){ return ['kind'=>MOBIUS_STEP_KINDS[$st['type']][0]??$st['type'],'type'=>$st['type'],'id'=>$st['id'],'code'=>$st['code'],'message'=>$st['message'],'invalid'=>$st['error'],'language'=>$st['language'],
            'url'=>$st['url'],'format'=>$st['mapping'],'next'=>$st['next']!==''?($st['type']==='GO_TO_MENU'?($menuNames[$st['next']]??$st['next']):(isset($steps[$st['next']])?(MOBIUS_STEP_KINDS[$steps[$st['next']]['type']][0]??'').' '.$steps[$st['next']]['code'].' — '.mb_strimwidth($steps[$st['next']]['message'],0,50,'…'):$st['next'].' (not in this menu)')):'',
            'sends'=>$st['props'],'receives'=>$st['resp'],'can_option'=>mobius_step_has_options($st['type']),'can_next'=>mobius_step_continues($st['type'])&&!($st['next']!==''&&isset($steps[$st['next']]))]; },$steps),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?></script>
        <?php $editStep=$stepOp==='edit'&&isset($steps[$stepAt])?$steps[$stepAt]:null; $addMode=$stepOp==='add'&&in_array($stepHow,['option','next','first'],true);
        if($unl && $stepOp==='delete' && isset($steps[$stepAt])): $sub=mobius_step_subtree($steps,$stepAt);?>
        <div class="modal fade mbs-auto" id="mbsModal" tabindex="-1" data-close="<?=e($sb.'&focus='.urlencode($stepAt))?>"><div class="modal-dialog"><div class="modal-content border-danger"><div class="modal-body"><div class="fw-semibold mb-1">Delete <?=e(mobius_step_words($steps[$stepAt]['_raw']))?><?=count($sub)>1?' and the '.(count($sub)-1).' step'.(count($sub)>2?'s':'').' under it':''?>?</div>
            <p class="small mb-2">This changes the live menu in Mobius at once. History → Put back creates the deleted steps again.</p>
            <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="step_delete"><input type="hidden" name="menu_id" value="<?=e($am['menuID'])?>"><input type="hidden" name="step" value="<?=e($stepAt)?>"><button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>Delete</button></form> <button type="button" class="btn btn-link btn-sm" data-bs-dismiss="modal">Cancel</button></div></div></div></div>
        <?php elseif($unl && ($editStep || ($addMode && ($stepHow==='first' || isset($steps[$stepAt]))))):
            $r0=$editStep['_raw']??null; $f=$stepForm??[];
            $v=fn($k,$d='')=>array_key_exists($k,$f)?(string)$f[$k]:$d;
            $type=$v('type',$editStep?$editStep['type']:($stepHow==='first'?'MENU':'MESSAGE'));
            $title=$editStep?'Edit '.mobius_step_words($r0):($stepHow==='first'?'Start the menu: its first screen':($stepHow==='option'?'Add an option to '.mobius_step_words($steps[$stepAt]['_raw']):'Add the step after '.mobius_step_words($steps[$stepAt]['_raw'])));
            $usedKeys=[]; $pid=$editStep?$editStep['parent']:$stepAt; foreach($steps as $o) if($o['parent']===$pid && $o['code']!=='' && (!$editStep||$o['id']!==$editStep['id'])) $usedKeys[]=$o['code'];
            $nextKey=''; if($stepHow==='option'){ for($k=1;$k<=9;$k++) if(!in_array((string)$k,$usedKeys,true)){ $nextKey=(string)$k; break; } }
            $varName=$editStep&&is_array($r0['properties']??null)?(string)($r0['properties'][0]['propertyName']??''):'';?>
        <div class="modal fade mbs-auto" id="mbsModal" tabindex="-1" data-bs-backdrop="static" data-close="<?=e($sb.($stepAt!==''?'&focus='.urlencode($stepAt):''))?>"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title h6"><?=e($title)?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="mbsForm">
            <?php if($editStep):?><div class="d-flex flex-wrap gap-2 mb-2"><?php if(mobius_step_has_options($editStep['type'])):?><a class="btn btn-sm btn-outline-primary" href="<?=e($sb)?>&op=add&how=option&at=<?=urlencode($editStep['id'])?>">+ Add option</a><?php endif;?><?php if(mobius_step_continues($editStep['type']) && !($editStep['next']!=='' && isset($steps[$editStep['next']]))):?><a class="btn btn-sm btn-outline-primary" href="<?=e($sb)?>&op=add&how=next&at=<?=urlencode($editStep['id'])?>">+ Add next step</a><?php endif;?><?php if($editStep['type']!=='WELCOME'):?><a class="btn btn-sm btn-outline-danger ms-auto" href="<?=e($sb)?>&op=delete&at=<?=urlencode($editStep['id'])?>">Delete this step</a><?php endif;?></div><?php endif;?>
            <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="step_save"><input type="hidden" name="menu_id" value="<?=e($am['menuID'])?>"><input type="hidden" name="how" value="<?=e($editStep?'edit':$stepHow)?>"><input type="hidden" name="at" value="<?=e($stepAt)?>">
                <div class="col-12"><label class="small text-muted mb-0">What this step does</label><select class="form-select" name="s[type]" id="mbsType"><?php foreach(($stepHow==='first'?['MENU','MESSAGE']:array_diff(MOBIUS_STEP_TYPES,['WELCOME'])) as $t):?><option value="<?=$t?>" <?=$type===$t?'selected':''?>><?=e(MOBIUS_STEP_KINDS[$t][0]??$t)?></option><?php endforeach;?><?php if($editStep && !in_array($type,MOBIUS_STEP_TYPES,true)):?><option selected value="<?=e($type)?>"><?=e($type)?></option><?php endif;?></select><?php if($stepHow==='first'):?><div class="small text-muted">Mobius starts every menu with a Welcome step; it is created together with this first screen.</div><?php endif;?></div>
                <?php if($stepHow==='option' || ($editStep && $editStep['code']!=='')):?><div class="col-6"><label class="small text-muted mb-0">Key (customer presses)</label><input class="form-control font-monospace" name="s[code]" value="<?=e($v('code',$editStep['code']??$nextKey))?>" maxlength="6" required><?php if($usedKeys):?><div class="small text-muted">Used: <?=e(implode(', ',$usedKeys))?></div><?php endif;?></div><?php endif;?>
                <div class="col-6" data-for="MENU MESSAGE COLLECT VARIABLE_MENU"><label class="small text-muted mb-0">Language</label><select class="form-select" name="s[language]"><?php foreach(MOBIUS_STEP_LANGS as $lg):?><option <?=$v('language',$r0['language']??'DEFAULT')===$lg?'selected':''?>><?=$lg?></option><?php endforeach;?></select></div>
                <div class="col-12" data-for="MENU MESSAGE COLLECT VARIABLE_MENU"><label class="small text-muted mb-0">Message (what the customer sees; {variable} puts in a value)</label><textarea class="form-control font-monospace" name="s[message]" rows="5" maxlength="1000"><?=e($v('message',(string)($r0['message']??'')))?></textarea><div class="small text-muted"><span id="mbsCount">0</span> characters (a USSD screen shows about 160)</div><div class="mbt-phone mt-2" id="mbsPhone"></div></div>
                <div class="col-12" data-for="MENU VARIABLE_MENU"><label class="small text-muted mb-0">Invalid option message (optional)</label><input class="form-control" name="s[invalid]" value="<?=e($v('invalid',(string)($r0['errorMessage']??'')))?>" maxlength="300"></div>
                <div class="col-12" data-for="COLLECT VARIABLE_MENU"><label class="small text-muted mb-0">Variable name (keeps what the customer types)</label><input class="form-control font-monospace" name="s[variable]" value="<?=e($v('variable',$varName))?>" placeholder="otherMsisdn" maxlength="60"></div>
                <?php if(!$editStep && $stepHow!=='first'):?><div class="col-12" data-for="COLLECT EXTERNAL_SERVICE"><label class="small text-muted mb-0" id="mbsThenLabel">Reply after the answer (shown to the customer next)</label><textarea class="form-control font-monospace" name="s[then]" id="mbsThen" rows="2" maxlength="1000"><?=e($v('then',''))?></textarea><div class="small text-muted">Created together with this step. You can change it, or put a menu or another step there later.</div></div><?php endif;?>
                <div class="col-12" data-for="GO_TO_STEP"><label class="small text-muted mb-0">Go to step</label><select class="form-select" name="s[target_step]"><option value="">—</option><?php foreach($steps as $o): if($editStep && $o['id']===$editStep['id']) continue;?><option value="<?=e($o['id'])?>" <?=$v('target_step',$editStep['next']??'')===$o['id']?'selected':''?>><?=e(mobius_step_words($o['_raw']))?></option><?php endforeach;?></select></div>
                <div class="col-12" data-for="GO_TO_MENU"><label class="small text-muted mb-0">Go to menu</label><select class="form-select" name="s[target_menu]"><option value="">—</option><?php $ms=$menus; usort($ms,fn($a,$b)=>strnatcasecmp($a['name'],$b['name'])); foreach($ms as $o): if($o['menuID']===$am['menuID']) continue;?><option value="<?=e($o['menuID'])?>" <?=$v('target_menu',$editStep['next']??'')===$o['menuID']?'selected':''?>><?=e($o['name'].($o['shortcode']!==''?' ('.$o['shortcode'].')':''))?></option><?php endforeach;?></select></div>
                <div class="col-12" data-for="EXTERNAL_SERVICE"><label class="small text-muted mb-0">Service address (request URL)</label><input class="form-control font-monospace" name="s[url]" value="<?=e($v('url',(string)($r0['externalUrl']??'')))?>" placeholder="https://vas-staging.comium.gm/hera/prepaid/SpecialOffer"></div>
                <div class="col-6" data-for="EXTERNAL_SERVICE"><label class="small text-muted mb-0">Request format</label><select class="form-select" name="s[format]"><?php foreach(['JSON','XML','ENCODED_FORM'] as $fm):?><option <?=$v('format',(string)($r0['mappingType']??'JSON'))===$fm?'selected':''?>><?=$fm?></option><?php endforeach;?></select></div>
                <div class="col-12" data-for="EXTERNAL_SERVICE"><label class="small text-muted mb-0">Sends (the request)</label><textarea class="form-control font-monospace mbs-props" data-cols="Field sent|Value or {variable}|Default" name="s[sends]" rows="4" placeholder="offerCode = {offerCode} | 40154&#10;otherMsisdn = {otherMsisdn}"><?=e($v('sends',$type==='EXTERNAL_SERVICE'?mobius_props_text($r0['properties']??null):''))?></textarea></div>
                <div class="col-12" data-for="EXTERNAL_SERVICE"><label class="small text-muted mb-0">Reads back (from the answer)</label><textarea class="form-control font-monospace mbs-props" data-cols="Field in the answer|Save into variable|Default" name="s[reads]" rows="4" placeholder="result.resultDescription = resultDescription"><?=e($v('reads',mobius_props_text($r0['responseProperties']??null)))?></textarea></div>
                <div class="col-12" data-for="EXTERNAL_SERVICE"><label class="small text-muted mb-0">Headers — <?=!empty($r0['headers'])?'this step has: <b>'.e(implode(', ',array_map(fn($h)=>(string)($h['propertyName']??''),(array)$r0['headers']))).'</b> (values hidden). Leave empty to keep them, or type all of them again to replace':'one per line: <code>Name: value</code>'?></label><textarea class="form-control font-monospace" name="s[headers]" rows="2" placeholder="X-API-KEY: …" autocomplete="off"></textarea></div>
                <div class="col-12"><button class="btn btn-primary"><?=$editStep?'Save in Mobius':'Add in Mobius'?></button> <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button> <span class="small text-muted ms-2">Goes live at once.</span></div>
            </form></div></div></div></div>
        <?php endif;?>
        <details class="mt-2"><summary class="small text-muted">Raw steps as Mobius stores them (for checking; read-only)</summary><pre class="small bg-light border rounded p-2 mb-0" style="max-height:20rem;overflow:auto;white-space:pre-wrap"><?=e(mobius_redact(json_encode(array_map('mobius_step_safe',$pd['raw']),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),60000))?></pre></details>
        <?php endif;?>
    <?php elseif($act==='try'): $pd=$actData;?>
        <?php if(!$pd['ok']):?><div class="alert alert-danger py-2">Mobius refused: <?=e($pd['error'])?></div>
        <?php elseif(!$pd['steps']):?><p class="text-muted mb-0">This menu has no steps in Mobius yet<?=$am['shortcodeType']==='PROXY'?' (it takes its screens from its address)':''?>. Build it first in the Builder tab.</p>
        <?php else: $mn=[]; foreach($menus as $mm) $mn[$mm['menuID']]=$mm['name'].($mm['shortcode']!==''?' ('.$mm['shortcode'].')':'');
            $sim=[]; foreach($pd['steps'] as $st) $sim[$st['id']]=['t'=>$st['type'],'c'=>$st['code'],'p'=>$st['parent'],'n'=>$st['next'],'m'=>$st['message'],'e'=>$st['error'],'v'=>$st['props'][0]['name']??'',
                'reads'=>array_values(array_filter(array_map(fn($x)=>$x['value']!==''?$x['value']:'',$st['resp']))),'u'=>preg_replace('~^https?://~','',$st['url']),'g'=>$st['type']==='GO_TO_MENU'?($mn[$st['next']]??''):''];?>
        <div class="row g-3"><div class="col-md-5 col-lg-4">
            <div class="mbt-phone mbt-sim" id="simScreen" aria-live="polite"></div>
            <form class="d-flex gap-2 mt-2" id="simForm" autocomplete="off"><input class="form-control font-monospace" id="simIn" placeholder="Type a key, then Send" inputmode="numeric"><button class="btn btn-primary">Send</button></form>
            <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-outline-secondary" type="button" id="simRestart"><i class="fa-solid fa-rotate-left me-1"></i>Dial <?=e($am['shortcode']?:'again')?></button><a class="btn btn-sm btn-outline-secondary" href="?page=mobius_menus&steps=<?=urlencode($am['menuID'])?>"><i class="fa-solid fa-sitemap me-1"></i>Builder</a></div>
        </div><div class="col-md-7 col-lg-8">
            <div class="small text-muted mb-1">What happens, step by step (services are <b>not</b> called here; you choose their answer)</div>
            <ol class="small mb-0" id="simLog"></ol>
        </div></div>
        <script type="application/json" id="simData"><?=json_encode(['steps'=>$sim],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?></script>
        <?php endif;?>
    <?php elseif($act==='schedule'): $scheds=mobius_sched_list($am['menuID']); $ownLive=false; foreach($scheds as $x) if(in_array($x['state'],['waiting','live'],true)) $ownLive=true;?>
        <p class="small text-muted mb-2">Mobius has no schedule, so the portal does it with the short codes. <b>At the start</b> this menu gets the live short code; if another menu has that code now (for example the old version), it is parked on a spare code. <b>At the end</b> both go back as they were. It runs by itself within about 5 minutes of the time. Every change is in History.</p>
        <form method="post" class="row g-2 align-items-end mb-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="sched_add"><input type="hidden" name="menu_id" value="<?=e($am['menuID'])?>">
            <div class="col-md-3"><label class="small text-muted mb-0">Live on short code</label><input class="form-control font-monospace" name="live_code" value="<?=e($am['shortcode'])?>" placeholder="*606#" required></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Start (empty = now)</label><input type="datetime-local" class="form-control" name="start"></div>
            <div class="col-md-3"><label class="small text-muted mb-0">End (optional)</label><input type="datetime-local" class="form-control" name="end"></div>
            <div class="col-md-3"><button class="btn btn-primary w-100"><i class="fa-regular fa-clock me-1"></i>Save schedule</button></div>
            <div class="col-12 small text-muted">Typical uses: build the new version on a test code (e.g. <code>*9606*0*1#</code>) and schedule it onto <code>*606#</code> for the launch time; or give a promotion menu an end time so it stops by itself. To only take this menu off at a time, keep its own code and fill <b>End</b>.</div>
        </form>
        <?php if(!$scheds):?><p class="text-muted mb-0">No schedule for this menu yet.</p><?php else:?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Short code</th><th>Start</th><th>End</th><th>State</th><th>What happened</th><th></th></tr></thead><tbody>
        <?php foreach($scheds as $x): [$sl,$sc]=MOBIUS_SCHED_STATES[$x['state']]??[$x['state'],'secondary'];?><tr>
            <td><code><?=e($x['live_code'])?></code></td><td class="small"><?=e(substr($x['start_at'],0,16))?></td><td class="small"><?=$x['end_at']?e(substr($x['end_at'],0,16)):'<span class="text-muted">—</span>'?></td>
            <td><span class="badge bg-<?=$sc?><?=$sc==='light'?' text-dark border':''?>"><?=e($sl)?></span></td><td class="small"><?=e((string)$x['note'])?><?=$x['created_by']?'<div class="text-muted">by '.e($x['created_by']).'</div>':''?></td>
            <td class="text-end"><?php if(in_array($x['state'],['waiting','live'],true)):?><form method="post" class="d-inline" data-confirm="<?=$x['state']==='live'?'End it now and put the short codes back?':'Cancel this schedule?'?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="sched_stop"><input type="hidden" name="menu_id" value="<?=e($am['menuID'])?>"><input type="hidden" name="id" value="<?=(int)$x['id']?>"><button class="btn btn-sm <?=$x['state']==='live'?'btn-outline-danger':'btn-outline-secondary'?>"><?=$x['state']==='live'?'End now':'Cancel'?></button></form><?php endif;?></td></tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
    <?php elseif($act==='copy'):?>
        <p class="small">Makes a <b>new</b> menu in Mobius with every setting of <code><?=e($am['shortcode'])?></code> (type, address, destination, Extendable). The original is not changed. Put back in History removes the copy.</p>
        <form method="post" class="row g-2" data-confirm="Create the copy in Mobius now?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="copy"><input type="hidden" name="menu_id" value="<?=e($am['menuID'])?>">
            <div class="col-md-3"><label class="small text-muted mb-0">New short code</label><input class="form-control font-monospace" name="shortcode" required placeholder="*7998#"></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Name of the copy</label><input class="form-control" name="name" value="<?=e($am['name'].' (copy)')?>" maxlength="150"></div>
            <?php if(!$amOurs):?><div class="col-md-3"><label class="small text-muted mb-0">Confirm: type the new short code again</label><input class="form-control font-monospace" name="confirm" required autocomplete="off"></div><?php endif;?>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Copy</button></div></form>
    <?php elseif($act==='delete'): $cnt=mobius_rest($cfg,'ussdcalls/count_by_menu',['data'=>$am['menuID'],'msisdn'=>'']); $calls=$cnt['ok']?mobius_find_count($cnt['json']):null;?>
        <div class="alert alert-danger small"><b>This removes the menu from Mobius (live).</b> Customers dialling <code><?=e($am['shortcode']?:'—')?></code> will get “connection problem or invalid MMI code” straight away.<?=$calls?' Mobius has <b>'.number_format((int)$calls).'</b> calls recorded for it.':''?><?=$amOurs?'':' It belongs to <b>another service</b>, not this portal.'?> The portal keeps all its settings: <b>Put back</b> in History creates it again (with a new id).</div>
        <form method="post" class="row g-2" data-confirm="Delete <?=e($amWord)?> from Mobius now?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="menu_id" value="<?=e($am['menuID'])?>">
            <div class="col-md-5"><label class="small text-muted mb-0">Type <?=trim($am['shortcode'])!==''?'its short code':'its name'?> <code><?=e($amWord)?></code> to confirm</label><input class="form-control font-monospace" name="confirm" required autocomplete="off"></div>
            <div class="col-md-3 d-flex align-items-end"><button class="btn btn-danger w-100"><i class="fa-solid fa-trash me-1"></i>Delete from Mobius</button></div></form>
    <?php endif;?></div>
    <?php endif;?>

    <?php if($view): $vo=mobius_menu_is_ours($view,$cfg); $hist=mobius_changes(10,$view['menuID']); $inPortal=isset($portalCodes[rtrim($view['shortcode'],'#')]);?>
    <div class="cardx mt-3"><div class="d-flex justify-content-between"><h3 class="mb-2 h5">Settings and details</h3><a class="btn btn-sm btn-primary" href="?page=mobius_menus&edit=<?=urlencode($view['menuID'])?>"><i class="fa-solid fa-pen me-1"></i>Change settings</a></div>
        <div class="row g-3"><div class="col-lg-7"><table class="table table-sm mb-0 mb-kv"><tbody>
            <tr><th class="text-muted fw-normal">Menu id</th><td><code><?=e($view['menuID'])?></code></td></tr><tr><th class="text-muted fw-normal">Type</th><td><?=e($view['shortcodeType'])?></td></tr><tr><th class="text-muted fw-normal">Extendable</th><td><?=$view['isExtendable']?'yes':'no'?></td></tr>
            <tr><th class="text-muted fw-normal">Address</th><td class="small font-monospace text-break"><?=e($hideUrl($view['remoteURL']))?></td></tr><?php if($hasDest):?><tr><th class="text-muted fw-normal">Destination</th><td><?=e($destLabel($view['destinationID'])?:'—')?><?php if($view['destinationID'] && isset($destNames[$view['destinationID'][0]]) && $destNames[$view['destinationID'][0]]!==$view['destinationID'][0]):?> <span class="small text-muted">(<?=e(implode(', ',$view['destinationID']))?>)</span><?php endif;?></td></tr><?php endif;?>
            <tr><th class="text-muted fw-normal">In the portal</th><td><?php if($inPortal):?>built — <a href="?page=ussd_build&short_code=<?=urlencode(menu_normalize_shortcode($view['shortcode']))?>">open in Easy Builder</a> · <a href="?page=ussd_sim&sc=<?=urlencode(menu_normalize_shortcode($view['shortcode']))?>">try it</a><?php else:?><span class="text-muted">no menu with this code</span><?php if(menu_valid_shortcode(menu_normalize_shortcode($view['shortcode']))):?> — <a href="?page=ussd_menus&add=1&short_code=<?=urlencode(menu_normalize_shortcode($view['shortcode']))?>&name=<?=urlencode($view['name'])?>">build one</a><?php endif;?><?php endif;?></td></tr>
        </tbody></table>
        <details class="mt-2"><summary class="small text-muted">Everything Mobius holds for this menu</summary><pre class="small bg-light border rounded p-2 mb-0" style="white-space:pre-wrap"><?=e(mobius_redact(json_encode($view['_raw'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),6000))?></pre></details></div>
        <div class="col-lg-5"><div class="d-grid gap-2">
            <?php if(!$vo && $tpl):?><form method="post" class="border rounded p-2" data-confirm="Send <?=e($view['shortcode'])?> to this portal? Customers dialling it will get the portal's menu from now on."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="to_portal"><input type="hidden" name="menu_id" value="<?=e($view['menuID'])?>">
                <div class="small mb-1"><b>Send this code to the portal</b> — makes it a PROXY menu with the portal's address<?=$inPortal?' (the portal already has a menu for it)':'; <span class="text-danger">build the menu in the portal first</span>'?>.</div>
                <div class="input-group input-group-sm"><input class="form-control font-monospace" name="confirm" placeholder="type <?=e($view['shortcode'])?>" required autocomplete="off"><button class="btn btn-warning">Send to portal</button></div></form><?php endif;?>
            <?php if($hist):?><div class="small"><b>Recent changes to this menu</b><?php foreach($hist as $h):?><div class="text-muted"><?=e($h['created_at'])?> · <?=e($h['action'])?> · <?=e($h['username']??'')?></div><?php endforeach;?></div><?php endif;?>
        </div></div></div></div>
    <?php endif;?>

    <?php if($ok && !$focus):?>
    <div class="cardx table-card mt-3"><div class="table-scroll"><table class="table table-hover align-middle mb-0 no-pager" id="mbTable"><thead><tr><th>Menu</th><th>What it is</th><th class="text-end">Open</th></tr></thead><tbody>
        <?php foreach($menus as $m): $mo=mobius_menu_is_ours($m,$cfg); $kd=mobius_menu_kind($m,$cfg); [$kl,$kc]=MOBIUS_KINDS[$kd]; $q=urlencode($m['menuID']); $open=$kd==='mobius'?'steps':'view';?>
        <tr data-who="<?=$mo?'portal':'other'?>" data-type="<?=e($m['shortcodeType']?:'—')?>" data-kind="<?=$kd?>"<?=$view&&$view['menuID']===$m['menuID']?' class="table-active"':''?>>
            <td style="max-width:30rem"><a class="fw-semibold text-decoration-none text-reset" href="?page=mobius_menus&<?=$open?>=<?=$q?>"><?=e($m['name'])?></a>
                <div class="small"><?=$m['shortcode']!==''?'<code>'.e($m['shortcode']).'</code>':'<span class="text-muted">no short code</span>'?><?php if($m['remoteURL']!==''):?> <span class="text-muted text-truncate d-inline-block align-bottom" style="max-width:20rem" title="<?=e($hideUrl($m['remoteURL']))?>">· <?=e(preg_replace('~^https?://~','',$hideUrl($m['remoteURL'])))?></span><?php endif;?></div></td>
            <td><span class="badge <?=$kc?>"><?=e($kl)?></span><?php if($mo && !isset($portalCodes[rtrim($m['shortcode'],'#')])):?> <span class="badge bg-warning text-dark" title="Mobius sends this code to the portal, but the portal has no menu for it yet">not built yet</span><?php endif;?><?php if($hasExt&&$m['isExtendable']):?> <span class="badge bg-light text-dark border" title="Extendable: a dial such as <?=e(rtrim($m['shortcode'],'#'))?>*1# opens option 1 straight away">direct dial</span><?php endif;?></td>
            <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="?page=mobius_menus&<?=$open?>=<?=$q?>">Open</a>
                <?php if($kd==='mobius'):?><a class="btn btn-sm btn-light border" href="?page=mobius_menus&try=<?=$q?>" title="Try it on a phone on screen"><i class="fa-solid fa-mobile-screen"></i></a><?php endif;?>
                <a class="btn btn-sm btn-light border" href="?page=mobius_menus&calls=<?=$q?>" title="Calls"><i class="fa-solid fa-phone"></i></a>
                <a class="btn btn-sm btn-light border" href="?page=mobius_menus&stats=<?=$q?>" title="Numbers"><i class="fa-solid fa-chart-column"></i></a>
                <div class="btn-group"><button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" title="More"><i class="fa-solid fa-ellipsis"></i></button><ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="?page=mobius_menus&steps=<?=$q?>"><i class="fa-solid fa-sitemap fa-fw me-2"></i>Builder</a></li><li><a class="dropdown-item" href="?page=mobius_menus&edit=<?=$q?>"><i class="fa-solid fa-gear fa-fw me-2"></i>Settings</a></li>
                    <li><a class="dropdown-item" href="?page=mobius_menus&copy=<?=$q?>"><i class="fa-regular fa-copy fa-fw me-2"></i>Copy to a new code</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="?page=mobius_menus&delete=<?=$q?>"><i class="fa-solid fa-trash fa-fw me-2"></i>Delete</a></li></ul></div></td>
        </tr><?php endforeach;?>
        <?php if(!$menus):?><tr><td colspan="3" class="text-muted">No menus read from Mobius.</td></tr><?php endif;?>
    </tbody></table></div>
    <div class="d-flex flex-wrap align-items-center gap-2 p-2 border-top" id="mbPager"><button class="btn btn-sm btn-outline-secondary" type="button" data-pg="first" title="First">«</button><button class="btn btn-sm btn-outline-secondary" type="button" data-pg="prev" title="Previous">‹</button>
        <span class="small" id="mbPgInfo"></span><button class="btn btn-sm btn-outline-secondary" type="button" data-pg="next" title="Next">›</button><button class="btn btn-sm btn-outline-secondary" type="button" data-pg="last" title="Last">»</button>
        <select class="form-select form-select-sm w-auto ms-auto" id="mbPgSize"><option>10</option><option>25</option><option>50</option><option value="100000">All</option></select><span class="small text-muted">per page</span></div></div>
    <?php endif;?>

    <div class="cardx mt-3" id="mbHistory"><h3 class="mb-2"><i class="fa-solid fa-clock-rotate-left me-2"></i>History</h3><p class="small text-muted">Every change the portal made in Mobius, with the menu as it was before. <b>Put back</b> sends the earlier values to Mobius again, creates a deleted menu again, or removes a menu the portal created or copied.</p>
        <?php if(!$changes):?><p class="text-muted mb-0">No changes yet.</p><?php else:?><div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Code</th><th>Before → after</th><th></th></tr></thead><tbody>
        <?php $latest=[]; foreach($changes as $h0) if(!str_ends_with((string)$h0['action'],'-refused')) $latest[mobius_bare_code((string)$h0['shortcode'])] ??= (int)$h0['id'];
        foreach($changes as $h): $isLatest=($latest[mobius_bare_code((string)$h['shortcode'])]??0)===(int)$h['id']; $b=$h['before_json']?mobius_menu_public((array)json_decode($h['before_json'],true)):null; $a=$h['after_json']?mobius_menu_public((array)json_decode($h['after_json'],true)):null;
            $diff=[]; if($b&&$a) foreach(['name','shortcode','shortcodeType','isExtendable','remoteURL','destinationID'] as $k) if($b[$k]!=$a[$k]) $diff[]=$k.': '.(is_array($b[$k])?implode(',',$b[$k]):var_export($b[$k],true)).' → '.(is_array($a[$k])?implode(',',$a[$k]):var_export($a[$k],true));
            $refused=str_ends_with((string)$h['action'],'-refused');?>
        <tr><td class="small text-nowrap"><?=e($h['created_at'])?></td><td class="small"><?=e($h['username']??'')?></td><td><span class="badge <?=$refused?'bg-danger':'bg-light text-dark border'?>"><?=e(mobius_action_label((string)$h['action']))?></span></td><td><code><?=e($h['shortcode'])?></code></td>
            <td class="small text-break" style="max-width:34rem"><?=$refused?e(mb_strimwidth(html_entity_decode((string)$h['answer'],ENT_QUOTES|ENT_HTML5,'UTF-8'),0,200,'…')):(str_starts_with((string)$h['action'],'step-')?e(mb_strimwidth((string)$h['answer'],0,160,'…')).' <a class="small" href="?page=mobius_menus&steps='.urlencode((string)$h['menu_id']).'">Builder</a>':($h['action']==='delete'?'deleted (was “'.e($b['name']??'').'”, '.e($hideUrl((string)($b['remoteURL']??''))).')':($b?e($hideUrl(implode(' · ',$diff))?:'—'):($h['action']==='undo-create'?'removed again':($h['action']==='undelete'?'created again with the same settings':'new menu')))))?></td>
            <td class="text-end text-nowrap"><?php if(!$isLatest && !$refused):?><span class="small text-muted" title="A newer change of this short code is higher up the list: put that one back first">newer change above ↑</span><?php endif;?><?php if($isLatest && ($b || in_array($h['action'],['create','copy','undelete','step-edit','step-add','step-delete'],true)) && !$refused && !in_array($h['action'],['undo-create','step-restore','step-undelete'],true)):?><form method="post" class="d-inline" data-confirm="<?=str_starts_with((string)$h['action'],'step-')?'Undo this step change in '.e($h['shortcode']).'? This changes the live menu in Mobius.':($b?'Put '.e($h['shortcode']).' back as it was before this change? This changes the live menu in Mobius.':'Remove '.e($h['shortcode']).', which the portal created, from Mobius again?')?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="restore"><input type="hidden" name="change_id" value="<?=(int)$h['id']?>"><input type="hidden" name="menu_id" value="<?=e($h['menu_id'])?>"><button class="btn btn-sm btn-outline-warning py-0">Put back</button></form><?php endif;?></td></tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <script nonce="<?=e(csp_nonce())?>">
    (()=>{const f=document.getElementById('mbFilter'),w=document.getElementById('mbWho'),rows=[...document.querySelectorAll('#mbTable tbody tr[data-who]')];
      let page=0; const sz=document.getElementById('mbPgSize'), info=document.getElementById('mbPgInfo');
      const run=(keep)=>{ if(!keep) page=0; const q=(f?f.value:'').toLowerCase(),who=w?w.value:''; const hit=rows.filter(r=>(!q||r.textContent.toLowerCase().includes(q))&&(!who||r.dataset.kind===who||r.dataset.who===who||r.dataset.type===who));
        const n=parseInt(sz?sz.value:'10',10)||10, pages=Math.max(1,Math.ceil(hit.length/n)); page=Math.min(Math.max(page,0),pages-1);
        const act=rows.findIndex(r=>r.classList.contains('table-active')); if(!keep && act>=0 && !q && !who){ const i=hit.indexOf(rows[act]); if(i>=0) page=Math.floor(i/n); }
        rows.forEach(r=>r.style.display='none'); hit.slice(page*n,page*n+n).forEach(r=>r.style.display='');
        if(info) info.textContent=hit.length?((page*n+1)+'–'+Math.min(hit.length,page*n+n)+' of '+hit.length+' menus'+(hit.length<rows.length?' (of '+rows.length+')':'')):'No menu matches';};
      document.querySelectorAll('#mbPager [data-pg]').forEach(b=>b.addEventListener('click',()=>{const g=b.dataset.pg; page=g==='first'?0:g==='prev'?page-1:g==='next'?page+1:1e9; run(true);}));
      f&&f.addEventListener('input',()=>run()); w&&w.addEventListener('change',()=>run());
      document.querySelectorAll('#mbKinds [data-kind]').forEach(b=>b.addEventListener('click',()=>{ document.querySelectorAll('#mbKinds [data-kind]').forEach(x=>x.classList.toggle('active',x===b)); if(w){ if(![...w.options].some(o=>o.value===b.dataset.kind)) w.add(new Option('',b.dataset.kind)); w.value=b.dataset.kind; } run(); })); sz&&sz.addEventListener('change',()=>run()); if(rows.length) run();
      const dataEl=document.getElementById('mbtData'), box=document.getElementById('mbtDetail');
      if(dataEl&&box){ const D=JSON.parse(dataEl.textContent), esc=t=>String(t??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        const show=c=>{document.querySelectorAll('.mbt-card.on').forEach(x=>x.classList.remove('on')); c.classList.add('on'); const d=D[c.dataset.step]; if(!d) return;
          const row=(k,v)=>v===''||v==null||(Array.isArray(v)&&!v.length)?'':'<tr><th class="text-muted fw-normal pe-3" style="background:none;color:var(--muted);text-transform:none;letter-spacing:0">'+k+'</th><td style="white-space:pre-wrap">'+(Array.isArray(v)?v.map(p=>esc(p.name)+(p.value?' = '+esc(p.value):'')+(p.default?' (default '+esc(p.default)+')':'')).join('<br>'):esc(v))+'</td></tr>';
          const B=box.dataset.base, sid=encodeURIComponent(d.id), act=box.dataset.unlocked?'<div class="d-flex flex-wrap gap-2 mb-2"><a class="btn btn-sm btn-primary" href="'+B+'&op=edit&at='+sid+'">Edit</a>'+(d.can_option?'<a class="btn btn-sm btn-outline-primary" href="'+B+'&op=add&how=option&at='+sid+'">+ Add option</a>':'')+(d.can_next?'<a class="btn btn-sm btn-outline-primary" href="'+B+'&op=add&how=next&at='+sid+'">+ Add next step</a>':'')+(d.type!=='WELCOME'?'<a class="btn btn-sm btn-outline-danger" href="'+B+'&op=delete&at='+sid+'">Delete</a>':'')+'</div>':'<div class="small text-muted mb-2">Unlock editing (above) to change this step.</div>';
          const ph=d.message&&['MENU','MESSAGE','COLLECT','VARIABLE_MENU'].includes(d.type)?'<div class="mbt-phone mb-2">'+esc(d.message)+(d.type==='COLLECT'?'\n\n[ customer types ]':'')+'</div>':''; const hint=document.getElementById('mbtHint'); if(hint) hint.hidden=true;
          box.innerHTML=act+ph+'<table class="table table-sm mb-0 mb-kv"><tbody>'+row('Message',d.message)+row('Invalid option message',d.invalid)+row('Language',d.language)+row('Goes to',d.next)+row('Address',d.url)+row('Request format',d.format)+row(d.type==='COLLECT'||d.type==='VARIABLE_MENU'?'Keeps the answer in':'Sends',d.sends)+row('Reads back',d.receives)+row('Step id',d.id)+'</tbody></table>'; box.hidden=false; const tt=document.getElementById('mbtModalTitle'); if(tt) tt.textContent=d.kind+(d.code?' — key '+d.code:''); if(window.bootstrap) bootstrap.Modal.getOrCreateInstance(document.getElementById('mbtModal')).show();};
        const direct=c=>{ if(box.dataset.unlocked && c.dataset.step){ location.href=box.dataset.base+'&op=edit&at='+encodeURIComponent(c.dataset.step); return true; } return false; };
        document.querySelectorAll('.mbt-card[data-step]').forEach(c=>{c.addEventListener('click',e=>{ if(e.target.closest('[data-nocard]')) return; if(!direct(c)) show(c); }); c.addEventListener('keydown',e=>{if(e.key==='Enter')show(c);});});
        document.querySelectorAll('[data-view]').forEach(b=>b.addEventListener('click',()=>{ const c=document.querySelector('.mbt-card[data-step="'+CSS.escape(b.dataset.view)+'"]'); if(c) show(c); }));
        const fc=box.dataset.focus&&document.querySelector('.mbt-card[data-step="'+CSS.escape(box.dataset.focus)+'"]'); if(fc){ fc.classList.add('on'); fc.scrollIntoView({block:'nearest',inline:'center'}); } }
      const ty=document.getElementById('mbsType'), th=document.getElementById('mbsThen'), thl=document.getElementById('mbsThenLabel'), vn=document.querySelector('#mbsForm [name="s[variable]"]'); let thTouched=th&&th.value!==''; th&&th.addEventListener('input',()=>thTouched=true);
      const thDefault=t=>{ if(!th||thTouched) return; th.value=t==='COLLECT'?'Thank you'+(vn&&vn.value?' {'+vn.value+'}':'')+'!':(t==='EXTERNAL_SERVICE'?'{resultDescription}':''); if(thl) thl.textContent=t==='EXTERNAL_SERVICE'?'Message after the service (what it answered: {variable} from Reads back)':'Reply after the answer (shown to the customer next)'; };
      vn&&vn.addEventListener('input',()=>{ if(ty) thDefault(ty.value); });
      if(ty){ const sync=()=>{const t=ty.value; thDefault(t); document.querySelectorAll('#mbsForm [data-for]').forEach(x=>{const on=x.dataset.for.split(' ').includes(t); x.hidden=!on; x.querySelectorAll('input,select,textarea').forEach(i=>i.disabled=!on);});}; ty.addEventListener('change',sync); sync(); }
      const sd=document.getElementById('simData'); if(sd){ const S=JSON.parse(sd.textContent).steps, scr=document.getElementById('simScreen'), log=document.getElementById('simLog'), inp=document.getElementById('simIn'); let cur=null, wait=null, vars={}, hops=0;
        const esc=t=>String(t??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])), fill=t=>String(t||'').replace(/\{([^}]+)\}/g,(m,k)=>k in vars?vars[k]:'‹'+k+'›');
        const kids=id=>Object.entries(S).filter(([k,x])=>x.p===id&&x.c!=='').map(([k,x])=>[k,x]);
        const say=(h)=>{ const li=document.createElement('li'); li.innerHTML=h; log.appendChild(li); };
        const screen=(t,end)=>{ scr.innerHTML=esc(fill(t))+(end?'<span class="sim-end">— end of session —</span>':''); };
        const choose=(title,opts,cb)=>{ scr.innerHTML='<div class="mb-1">'+esc(title)+'</div>'; opts.forEach(([lab,val])=>{ const b=document.createElement('button'); b.type='button'; b.className='btn btn-sm btn-light sim-btn'; b.textContent=lab; b.onclick=()=>cb(val); scr.appendChild(b); }); wait=null; };
        const go=id=>{ if(++hops>200){ screen('Stopped: the menu loops too many times.',true); return; } const x=S[id]; cur=id; wait=null;
          if(!x){ screen('(this step is missing in Mobius)',true); say('Step '+esc(id)+' is missing'); return; }
          switch(x.t){
            case 'WELCOME': case 'WELCOME_INCOMING': say('Dial — the menu starts'); if(x.n) go(x.n); else screen('(nothing after Welcome yet)',true); return;
            case 'MENU': screen(x.m); wait='menu'; say('Shows the menu'); return;
            case 'VARIABLE_MENU': { const ks=kids(id); const val=vars[x.v]; if(val!==undefined){ const hit=ks.find(([k,c])=>c.c===String(val)); say('Variable menu: '+esc(x.v)+' = '+esc(val)); if(hit) go(hit[0]); else { screen(x.e||'No option for that value.',true); } return; }
              choose('Which value does '+x.v+' have?',ks.map(([k,c])=>[x.v+' = '+c.c,c.c]),v=>{ vars[x.v]=v; go(id); }); say('Variable menu on <code>'+esc(x.v)+'</code> — choose the value'); return; }
            case 'MESSAGE': screen(x.m,true); say('Shows a message and ends'); return;
            case 'COLLECT': screen(x.m); wait='collect'; say('Asks the customer to type something (kept in <code>'+esc(x.v)+'</code>)'); return;
            case 'GO_TO_STEP': say('Jumps to another step'); if(x.n) go(x.n); else screen('(Go to step: no step chosen)',true); return;
            case 'GO_TO_MENU': screen('→ Continues in the menu '+(x.g||'(not found)'),true); say('Goes to the menu <b>'+esc(x.g||'?')+'</b>'); return;
            case 'EXTERNAL_SERVICE': say('Calls the service <code>'+esc(x.u)+'</code> (not called in Try it)'); const nx=()=>{ if(x.n) go(x.n); else screen('(nothing after the service yet)',true); };
              if(x.reads&&x.reads.length){ scr.innerHTML='<div class="mb-1">Service: '+esc(x.u)+'</div><div class="small mb-1">Type what it would answer (or leave empty):</div>'; x.reads.slice(0,6).forEach(r=>{ const l=document.createElement('label'); l.className='d-block small'; l.textContent=r; const i=document.createElement('input'); i.className='form-control form-control-sm mb-1'; i.dataset.k=r; i.value=vars[r]??''; l.appendChild(i); scr.appendChild(l); }); const b=document.createElement('button'); b.type='button'; b.className='btn btn-sm btn-warning mt-1'; b.textContent='Continue'; b.onclick=()=>{ scr.querySelectorAll('input[data-k]').forEach(i=>{ if(i.value!=='') vars[i.dataset.k]=i.value; }); nx(); }; scr.appendChild(b); return; }
              nx(); return;
            default: screen('('+x.t+' is not shown in Try it)',true);
          } };
        const start=()=>{ log.innerHTML=''; vars={}; hops=0; const w=Object.entries(S).find(([k,x])=>x.t==='WELCOME'||x.t==='WELCOME_INCOMING'); if(w) go(w[0]); else screen('This menu has no Welcome step.',true); inp&&inp.focus(); };
        document.getElementById('simForm').addEventListener('submit',e=>{ e.preventDefault(); const v=inp.value.trim(); inp.value=''; if(!cur||!wait) return; const x=S[cur];
          if(wait==='menu'){ say('Customer presses <b>'+esc(v)+'</b>'); const hit=kids(cur).find(([k,c])=>c.c===v); if(hit){ hops=0; go(hit[0]); } else { scr.innerHTML=esc(fill(x.e||'Invalid option'))+'\n\n'+esc(fill(x.m)); say('No option '+esc(v)+' — shows the invalid option message'); } }
          else if(wait==='collect'){ vars[x.v]=v; say('Customer types <b>'+esc(v)+'</b>'); hops=0; if(x.n) go(x.n); else screen('(nothing after this question yet)',true); } });
        document.getElementById('simRestart').addEventListener('click',start); start(); }
      document.querySelectorAll('textarea.mbs-props').forEach(ta=>{ const cols=ta.dataset.cols.split('|'); const wrap=document.createElement('div'); wrap.className='mbs-tbl';
        const parse=t=>t.split(/\r?\n/).map(l=>l.trim()).filter(Boolean).map(l=>{ const m=l.match(/^([^=|]+?)\s*(?:=\s*([^|]*?))?\s*(?:\|\s*(.*))?$/)||[,l,'','']; return [m[1]||'',m[2]||'',m[3]||'']; });
        const ser=()=>{ ta.value=[...wrap.querySelectorAll('tbody tr')].map(tr=>[...tr.querySelectorAll('input')].map(i=>i.value.trim())).filter(r=>r[0]!=='').map(([a,b,c])=>a+(b!==''?' = '+b:'')+(c!==''?' | '+c:'')).join('\n'); };
        const row=(v=['','',''])=>{ const tr=document.createElement('tr'); v.forEach((x,i)=>{ const td=document.createElement('td'); const inp=document.createElement('input'); inp.className='form-control form-control-sm font-monospace'; inp.value=x; inp.placeholder=cols[i]; inp.addEventListener('input',ser); td.appendChild(inp); tr.appendChild(td); });
          const td=document.createElement('td'); const del=document.createElement('button'); del.type='button'; del.className='btn btn-sm btn-link text-danger p-0'; del.title='Remove'; del.innerHTML='&times;'; del.onclick=()=>{ tr.remove(); ser(); count(); }; td.appendChild(del); tr.appendChild(td); tb.appendChild(tr); };
        wrap.innerHTML='<div class="table-scroll" style="max-height:18rem;overflow:auto"><table class="table table-sm mb-1 mb-kv"><thead><tr>'+cols.map(c=>'<th style="background:none;color:var(--muted);text-transform:none;letter-spacing:0;font-size:.75rem">'+c+'</th>').join('')+'<th></th></tr></thead><tbody></tbody></table></div><div class="d-flex gap-2 align-items-center"><button type="button" class="btn btn-sm btn-outline-secondary" data-a="add">+ Add row</button><button type="button" class="btn btn-sm btn-link" data-a="paste">Paste lines</button><span class="small text-muted ms-auto" data-a="n"></span></div>';
        const tb=wrap.querySelector('tbody'), n=wrap.querySelector('[data-a=n]'), count=()=>{ n.textContent=tb.children.length+' row'+(tb.children.length===1?'':'s'); };
        const load=()=>{ tb.innerHTML=''; parse(ta.value).forEach(r=>row(r)); if(!tb.children.length) row(); count(); };
        wrap.querySelector('[data-a=add]').onclick=()=>{ row(); count(); tb.lastChild.querySelector('input').focus(); };
        let pasting=false; const pb=wrap.querySelector('[data-a=paste]');
        pb.onclick=()=>{ pasting=!pasting; if(pasting){ ser(); ta.hidden=false; wrap.querySelector('.table-scroll').hidden=true; pb.textContent='Back to the table'; ta.rows=8; ta.focus(); } else { load(); ta.hidden=true; wrap.querySelector('.table-scroll').hidden=false; pb.textContent='Paste lines'; } };
        ta.placeholder='One per line:  name = value | default'; ta.hidden=true; ta.after(wrap); wrap.after(ta); load();
        ta.form&&ta.form.addEventListener('submit',()=>{ if(!pasting) ser(); }); });
      const ta=document.querySelector('#mbsForm textarea[name="s[message]"]'), cn=document.getElementById('mbsCount'), pp=document.getElementById('mbsPhone'); if(ta&&cn){ const c=()=>{cn.textContent=ta.value.length; cn.parentElement.classList.toggle('text-danger',ta.value.length>160); if(pp) pp.textContent=ta.value;}; ta.addEventListener('input',c); c(); }
      const am=document.querySelector('.mbs-auto'); if(am) window.addEventListener('load',()=>{ if(!window.bootstrap) return; am.addEventListener('hidden.bs.modal',()=>{ location.href=am.dataset.close; }); bootstrap.Modal.getOrCreateInstance(am).show(); });
      const b=document.getElementById('mbUsePortal'); b&&b.addEventListener('click',()=>{document.getElementById('mbUrl').value=b.dataset.url;const d=document.getElementById('mbDest');if(d)d.value=b.dataset.dest;});})();
    </script>
    <?php layout_end(); exit;
