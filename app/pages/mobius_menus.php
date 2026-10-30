<?php
declare(strict_types=1);
// Page: ?page=mobius_menus — every menu in Mobius (USSD Shortcodes): search, look at, create, change, point at this portal, put back, export.
// Included by index.php inside its try/catch, after the sign-in, CSRF and $page checks. The rules are in app/lib/mobius.php (mobius_save_menu).

    require_perm('manage_api_keys'); $cfg=ussd_proxy_config();
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
                $r=mobius_restore_change($cfg,(int)($_POST['change_id']??0)); flash('success','Put back as it was: '.($r['menu']['shortcode']??'').' — “'.($r['menu']['name']??'').'”.');
                if (!empty($r['menu']['menuID'])) $back.='&view='.urlencode((string)$r['menu']['menuID']);
            }
        } catch (RuntimeException $e) {
            flash('danger',$e->getMessage());
            if ($do==='save') { $_SESSION['mobius_form']=['menu_id'=>(string)($_POST['menu_id']??''),'m'=>(array)($_POST['m']??[])]; $back.=($_POST['menu_id']??'')!==''?'&edit='.urlencode((string)$_POST['menu_id']):'&new=1'; }
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
    $view=$viewId!==''?mobius_find_menu($menus,$viewId):null; $edit=$editId!==''?mobius_find_menu($menus,$editId):null;
    $saved=$_SESSION['mobius_form']??null; unset($_SESSION['mobius_form']);
    $tpl=$ok?mobius_template_menu($menus,$cfg):null;
    $form=null; if ($edit || $isNew) {
        $base=$edit?mobius_menu_public($edit):['name'=>'','shortcode'=>'','shortcodeType'=>'PROXY','isExtendable'=>false,'remoteURL'=>$tpl['remoteURL']??'','destinationID'=>$tpl['destinationID']??[]];
        if ($saved && $saved['menu_id']===($edit['menuID']??'')) { $s=$saved['m']; $base=['name'=>$s['name']??'','shortcode'=>$s['shortcode']??'','shortcodeType'=>$s['shortcodeType']??'PROXY','isExtendable'=>!empty($s['isExtendable']),'remoteURL'=>$s['remoteURL']??'','destinationID'=>preg_split('/[\s,;]+/',(string)($s['destinationID']??''),-1,PREG_SPLIT_NO_EMPTY)]; }
        $form=$base;
    }
    $dests=[]; foreach($menus as $m) foreach($m['destinationID'] as $d) $dests[$d]=($dests[$d]??0)+1; arsort($dests);
    $oursN=count(array_filter($menus,fn($m)=>mobius_menu_is_ours($m,$cfg)));
    $changes=mobius_changes(30);
    $hideUrl=fn(string $u)=>preg_replace('~([?&]t=)[^&]+~','$1…',$u);
    layout_start('Mobius Menus'); ussd_subnav('mobius_menus');
    ?>
    <div class="cardx">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div><h3 class="mb-0">Mobius menus</h3><div class="small text-muted">Every USSD short code in Mobius (the same list as Mobius → USSD Shortcodes). Search, open, change, create, and send a menu to this portal.</div></div>
            <input class="form-control w-auto ms-auto" id="mbFilter" placeholder="Search name, code, address…" style="min-width:16rem">
            <select class="form-select w-auto" id="mbWho"><option value="">All menus</option><option value="portal">Calls this portal</option><option value="other">Other services</option></select>
            <a class="btn btn-outline-secondary" href="?page=mobius_menus"><i class="fa-solid fa-arrows-rotate me-1"></i>Refresh</a>
            <?php if($ok):?><div class="btn-group"><a class="btn btn-outline-secondary" href="?page=mobius_menus&export=json" title="Every menu exactly as Mobius gives it — keep it as a backup"><i class="fa-solid fa-download me-1"></i>JSON</a><a class="btn btn-outline-secondary" href="?page=mobius_menus&export=csv">CSV</a></div>
            <a class="btn btn-primary" href="?page=mobius_menus&new=1"><i class="fa-solid fa-plus me-1"></i>New menu in Mobius</a><?php endif;?>
        </div>
        <?php if(!$ok):?><div class="alert alert-danger mt-3 mb-0 py-2">Could not read Mobius: <?=e($list['error'])?> <a href="?page=ussd_proxy">Check the Mobius connection</a>.</div>
        <?php else: $short=$list['count']!==null && $list['count']!==count($menus);?>
        <p class="mt-3 mb-0 small">Mobius lists <b><?=count($menus)?></b> menu<?=count($menus)===1?'':'s'?><?=$list['count']!==null?' (its count says <b>'.(int)$list['count'].'</b>)':''?>: <b><?=$oursN?></b> call this portal, <b><?=count($menus)-$oursN?></b> belong to other services. <span class="text-muted">Read as: <?=e($list['shape'])?>.</span></p>
        <?php if($short):?><div class="alert alert-warning mt-2 mb-0 py-2 small"><b>The list is not complete.</b> Mobius counts <?=(int)$list['count']?> menus but the portal could read <?=count($menus)?>. Open <i>What Mobius answered</i> below and send it to whoever maintains the portal — it shows the exact answer so the reading can be adjusted.</div><?php endif;?>
        <details class="mt-2"<?=$short?' open':''?>><summary class="small text-muted">What Mobius answered (for troubleshooting; session ids and the portal token are hidden)</summary>
            <?php foreach($list['diag'] as $d):?><div class="small mt-2"><b><?=e($d['call'])?></b><?=isset($d['rows'])?' — '.(int)$d['rows'].' menus read':''?></div><pre class="small bg-light border rounded p-2 mb-0" style="max-height:14rem;overflow:auto;white-space:pre-wrap"><?=e($d['answer'])?></pre><?php endforeach;?>
        </details>
        <?php endif;?>
    </div>

    <?php if($form!==null): $other=$edit && !mobius_menu_is_ours($edit,$cfg);?>
    <div class="cardx mt-3 <?=$other?'border-danger':'border-primary'?>"><div class="d-flex justify-content-between"><h3 class="mb-2"><?=$edit?'Change '.e($edit['shortcode']).' in Mobius':'New menu in Mobius'?></h3><a class="btn-close" href="?page=mobius_menus<?=$edit?'&view='.urlencode($edit['menuID']):''?>" aria-label="Close"></a></div>
        <?php if($other):?><div class="alert alert-danger py-2 small"><b>This menu belongs to another service</b> (it does not call this portal). Customers dialling <code><?=e($edit['shortcode'])?></code> use it right now — a wrong address or code stops that service. The portal keeps the menu as it is now, so you can put it back from <b>History</b>.</div><?php endif;?>
        <form method="post" class="row g-2" id="mbForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save"><input type="hidden" name="menu_id" value="<?=e($edit['menuID']??'')?>">
            <div class="col-md-5"><label class="small text-muted mb-0">Name</label><input class="form-control" name="m[name]" value="<?=e($form['name'])?>" maxlength="150" required></div>
            <div class="col-md-3"><label class="small text-muted mb-0">Short code</label><input class="form-control font-monospace" name="m[shortcode]" value="<?=e($form['shortcode'])?>" maxlength="40" placeholder="*9606*7070#" required></div>
            <div class="col-md-2"><label class="small text-muted mb-0">Type</label><select class="form-select" name="m[shortcodeType]"><?php foreach(array_unique(array_merge(MOBIUS_MENU_TYPES,[$form['shortcodeType']])) as $t): if($t==='') continue;?><option <?=$form['shortcodeType']===$t?'selected':''?>><?=e($t)?></option><?php endforeach;?></select></div>
            <div class="col-md-2 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="m[isExtendable]" value="1" id="mbExt" <?=$form['isExtendable']?'checked':''?>><label class="form-check-label" for="mbExt">Extendable</label></div></div>
            <div class="col-md-8"><label class="small text-muted mb-0">Address Mobius calls (remote URL)</label><input class="form-control font-monospace" name="m[remoteURL]" id="mbUrl" value="<?=e($form['remoteURL'])?>" maxlength="500" placeholder="https://…">
                <?php if($tpl):?><button type="button" class="btn btn-link btn-sm px-0" id="mbUsePortal" data-url="<?=e($tpl['remoteURL'])?>" data-dest="<?=e(implode(', ',$tpl['destinationID']))?>">Use this portal's address</button><?php endif;?></div>
            <div class="col-md-4"><label class="small text-muted mb-0">Destination ids (comma separated)</label><input class="form-control font-monospace" name="m[destinationID]" id="mbDest" value="<?=e(implode(', ',$form['destinationID']))?>" list="mbDests"><datalist id="mbDests"><?php foreach($dests as $d=>$n):?><option value="<?=e($d)?>"><?=e($d)?> (<?=$n?> menus)</option><?php endforeach;?></datalist></div>
            <div class="col-md-5"><label class="small text-muted mb-0">Confirm<?=$other?'':' (only needed when the menu does not call this portal)'?>: type the short code<?=$edit?' <code>'.e($edit['shortcode']).'</code>':''?></label><input class="form-control font-monospace" name="confirm" autocomplete="off" <?=$other?'required':''?>></div>
            <div class="col-md-3 d-flex align-items-end"><button class="btn <?=$other?'btn-danger':'btn-primary'?> w-100"><?=$edit?'Save in Mobius':'Create in Mobius'?></button></div>
            <div class="col-12 small text-muted">The change goes straight to Mobius (live). Fields Mobius has that are not shown here are sent back unchanged. Nothing is deleted from Mobius by the portal.</div>
        </form></div>
    <?php endif;?>

    <?php if($view): $vo=mobius_menu_is_ours($view,$cfg); $hist=mobius_changes(10,$view['menuID']); $inPortal=isset($portalCodes[rtrim($view['shortcode'],'#')]);?>
    <div class="cardx mt-3"><div class="d-flex justify-content-between"><h3 class="mb-2"><code><?=e($view['shortcode'])?></code> — <?=e($view['name'])?> <span class="badge <?=$vo?'bg-success':'bg-secondary'?> align-middle"><?=$vo?'calls this portal':'other service'?></span></h3><a class="btn-close" href="?page=mobius_menus" aria-label="Close"></a></div>
        <div class="row g-3"><div class="col-lg-7"><table class="table table-sm mb-0"><tbody>
            <tr><th class="text-muted fw-normal">Menu id</th><td><code><?=e($view['menuID'])?></code></td></tr><tr><th class="text-muted fw-normal">Type</th><td><?=e($view['shortcodeType'])?></td></tr><tr><th class="text-muted fw-normal">Extendable</th><td><?=$view['isExtendable']?'yes':'no'?></td></tr>
            <tr><th class="text-muted fw-normal">Address</th><td class="small font-monospace text-break"><?=e($hideUrl($view['remoteURL']))?></td></tr><tr><th class="text-muted fw-normal">Destinations</th><td><?=e(implode(', ',$view['destinationID'])?:'—')?></td></tr>
            <tr><th class="text-muted fw-normal">In the portal</th><td><?php if($inPortal):?>built — <a href="?page=ussd_build&short_code=<?=urlencode(menu_normalize_shortcode($view['shortcode']))?>">open in Easy Builder</a> · <a href="?page=ussd_sim&sc=<?=urlencode(menu_normalize_shortcode($view['shortcode']))?>">try it</a><?php else:?><span class="text-muted">no menu with this code</span><?php if(menu_valid_shortcode(menu_normalize_shortcode($view['shortcode']))):?> — <a href="?page=ussd_menus&add=1">build one</a><?php endif;?><?php endif;?></td></tr>
        </tbody></table>
        <details class="mt-2"><summary class="small text-muted">Everything Mobius holds for this menu</summary><pre class="small bg-light border rounded p-2 mb-0" style="white-space:pre-wrap"><?=e(mobius_redact(json_encode($view['_raw'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),6000))?></pre></details></div>
        <div class="col-lg-5"><div class="d-grid gap-2">
            <a class="btn btn-outline-primary" href="?page=mobius_menus&edit=<?=urlencode($view['menuID'])?>"><i class="fa-solid fa-pen me-1"></i>Change in Mobius</a>
            <?php if(!$vo && $tpl):?><form method="post" class="border rounded p-2" data-confirm="Send <?=e($view['shortcode'])?> to this portal? Customers dialling it will get the portal's menu from now on."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="to_portal"><input type="hidden" name="menu_id" value="<?=e($view['menuID'])?>">
                <div class="small mb-1"><b>Send this code to the portal</b> — makes it a PROXY menu with the portal's address<?=$inPortal?' (the portal already has a menu for it)':'; <span class="text-danger">build the menu in the portal first</span>'?>.</div>
                <div class="input-group input-group-sm"><input class="form-control font-monospace" name="confirm" placeholder="type <?=e($view['shortcode'])?>" required autocomplete="off"><button class="btn btn-warning">Send to portal</button></div></form><?php endif;?>
            <?php if($hist):?><div class="small"><b>Recent changes to this menu</b><?php foreach($hist as $h):?><div class="text-muted"><?=e($h['created_at'])?> · <?=e($h['action'])?> · <?=e($h['username']??'')?></div><?php endforeach;?></div><?php endif;?>
        </div></div></div></div>
    <?php endif;?>

    <?php if($ok):?>
    <div class="cardx table-card mt-3"><div class="table-scroll"><table class="table table-hover align-middle mb-0" id="mbTable"><thead><tr><th>Short code</th><th>Name</th><th>Type</th><th>Ext.</th><th>Address</th><th>Destinations</th><th>Portal</th><th class="text-end"></th></tr></thead><tbody>
        <?php foreach($menus as $m): $mo=mobius_menu_is_ours($m,$cfg);?>
        <tr data-who="<?=$mo?'portal':'other'?>"<?=$view&&$view['menuID']===$m['menuID']?' class="table-active"':''?>>
            <td><code><?=e($m['shortcode'])?></code></td><td><?=e($m['name'])?></td><td class="small"><?=e($m['shortcodeType'])?></td><td class="small"><?=$m['isExtendable']?'yes':'no'?></td>
            <td class="small text-muted text-break" style="max-width:22rem"><?=e(mb_strimwidth($hideUrl($m['remoteURL']),0,70,'…'))?></td><td class="small"><?=e(implode(', ',$m['destinationID']))?></td>
            <td><?php if($mo):?><span class="badge bg-success">calls portal</span><?php if(!isset($portalCodes[rtrim($m['shortcode'],'#')])):?> <span class="badge bg-warning text-dark" title="Mobius sends this code to the portal, but the portal has no menu for it">not built</span><?php endif;?><?php else:?><span class="badge bg-secondary">other</span><?php endif;?></td>
            <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=mobius_menus&view=<?=urlencode($m['menuID'])?>" title="Open"><i class="fa-solid fa-eye"></i></a> <a class="btn btn-sm btn-outline-primary" href="?page=mobius_menus&edit=<?=urlencode($m['menuID'])?>" title="Change"><i class="fa-solid fa-pen"></i></a></td>
        </tr><?php endforeach;?>
        <?php if(!$menus):?><tr><td colspan="8" class="text-muted">No menus read from Mobius.</td></tr><?php endif;?>
    </tbody></table></div></div>
    <?php endif;?>

    <div class="cardx mt-3"><h3 class="mb-2"><i class="fa-solid fa-clock-rotate-left me-2"></i>History</h3><p class="small text-muted">Every change the portal made in Mobius, with the menu as it was before. <b>Put back</b> sends the earlier values to Mobius again.</p>
        <?php if(!$changes):?><p class="text-muted mb-0">No changes yet.</p><?php else:?><div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Code</th><th>Before → after</th><th></th></tr></thead><tbody>
        <?php foreach($changes as $h): $b=$h['before_json']?mobius_menu_public((array)json_decode($h['before_json'],true)):null; $a=$h['after_json']?mobius_menu_public((array)json_decode($h['after_json'],true)):null;
            $diff=[]; if($b&&$a) foreach(['name','shortcode','shortcodeType','isExtendable','remoteURL','destinationID'] as $k) if($b[$k]!=$a[$k]) $diff[]=$k.': '.(is_array($b[$k])?implode(',',$b[$k]):var_export($b[$k],true)).' → '.(is_array($a[$k])?implode(',',$a[$k]):var_export($a[$k],true));
            $refused=str_ends_with((string)$h['action'],'-refused');?>
        <tr><td class="small text-nowrap"><?=e($h['created_at'])?></td><td class="small"><?=e($h['username']??'')?></td><td><span class="badge <?=$refused?'bg-danger':'bg-light text-dark border'?>"><?=e($h['action'])?></span></td><td><code><?=e($h['shortcode'])?></code></td>
            <td class="small text-break" style="max-width:34rem"><?=$refused?e(mb_strimwidth((string)$h['answer'],0,200,'…')):($b?e($hideUrl(implode(' · ',$diff))?:'—'):'new menu')?></td>
            <td class="text-end"><?php if($b && !$refused):?><form method="post" class="d-inline" data-confirm="Put <?=e($h['shortcode'])?> back as it was before this change? This changes the live menu in Mobius."><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="restore"><input type="hidden" name="change_id" value="<?=(int)$h['id']?>"><input type="hidden" name="menu_id" value="<?=e($h['menu_id'])?>"><button class="btn btn-sm btn-outline-warning py-0">Put back</button></form><?php endif;?></td></tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
    </div>
    <script>
    (()=>{const f=document.getElementById('mbFilter'),w=document.getElementById('mbWho'),rows=[...document.querySelectorAll('#mbTable tbody tr[data-who]')];
      const run=()=>{const q=(f.value||'').toLowerCase(),who=w.value;rows.forEach(r=>{r.style.display=(!q||r.textContent.toLowerCase().includes(q))&&(!who||r.dataset.who===who)?'':'none';});};
      f&&f.addEventListener('input',run); w&&w.addEventListener('change',run);
      const b=document.getElementById('mbUsePortal'); b&&b.addEventListener('click',()=>{document.getElementById('mbUrl').value=b.dataset.url;document.getElementById('mbDest').value=b.dataset.dest;});})();
    </script>
    <?php layout_end(); exit;
