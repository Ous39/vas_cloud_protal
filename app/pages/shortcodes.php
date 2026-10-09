<?php
declare(strict_types=1);
// Page: ?page=shortcodes — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_shortcodes'); $db=portal_pdo(); $edit=null;
    if(isset($_GET['id'])){ $st=$db->prepare('SELECT * FROM portal_short_codes WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit=$st->fetch(); }
    if($_SERVER['REQUEST_METHOD']==='POST'){ $token=make_confirmation('save_shortcode',['id'=>$_POST['id']??null,'data'=>$_POST['data']??[],'return_to'=>'?page=shortcodes']); redirect('?page=confirm&token='.$token); }
    // a short code that already has a menu opens the form filled in, so registering it is one click and a name
    $form = $edit ?: (isset($_GET['new']) ? ['channel_type'=>'USSD','short_code'=>trim((string)$_GET['new']),'service_name'=>'','provider'=>'Comium','status'=>'Active','description'=>''] : []);
    $rows=$db->query('SELECT c.*, (SELECT COUNT(*) FROM portal_project_channels pc WHERE pc.channel_id=c.id) AS linked FROM portal_short_codes c ORDER BY c.short_code, c.channel_type, c.service_name')->fetchAll();
    $registry=[]; foreach($rows as $r) if($r['channel_type']==='USSD') $registry[$r['short_code']]=$r;
    $overview=menu_overview(); $cfg=ussd_proxy_config(); $unregistered=array_values(array_diff(array_keys($overview), array_keys($registry)));
    layout_start('Channel / Short Code Management'); ussd_subnav('shortcodes');
    ?>
    <?php if($unregistered):?><div class="alert alert-warning py-2"><b>Menus that are not in the register yet:</b>
        <?php foreach($unregistered as $uc):?><a class="btn btn-sm btn-outline-dark ms-1" href="?page=shortcodes&new=<?=urlencode($uc)?>"><i class="fa-solid fa-plus me-1"></i><?=e($uc)?></a><?php endforeach;?>
        <div class="small mt-1">These short codes answer customers (or are being built) but nobody has written down what they are for. Click one, give it a name, save.</div></div><?php endif;?>
    <div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit Channel':'Add Channel' ?></h3><p class="text-muted">Manage USSD and IVR channels. Saving requires confirmation.</p>
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
        <label>Channel Type</label><select class="form-select mb-2" name="data[channel_type]"><?php foreach(['USSD','IVR'] as $v):?><option value="<?=e($v)?>" <?=($form['channel_type']??'USSD')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select>
        <label>Short Code</label><input class="form-control mb-2" name="data[short_code]" value="<?=e($form['short_code']??'')?>" placeholder="*123# or 141">
        <label>Service Name</label><input class="form-control mb-2" name="data[service_name]" value="<?=e($form['service_name']??'')?>" placeholder="VAS Main Menu"<?=isset($_GET['new'])&&!$edit?' autofocus':''?>>
        <label>Provider</label><input class="form-control mb-2" name="data[provider]" value="<?=e($form['provider']??'')?>" placeholder="Comium">
        <label>Status</label><select class="form-select mb-2" name="data[status]"><?php foreach(['Active','Inactive','Pending','Suspended'] as $v):?><option value="<?=e($v)?>" <?=($form['status']??'Pending')===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select>
        <label>Description</label><textarea class="form-control mb-2" name="data[description]" rows="3"><?=e($form['description']??'')?></textarea>
        <button class="btn btn-primary w-100">Preview & Confirm Save</button><?php if($edit||isset($_GET['new'])):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=shortcodes">Cancel</a><?php endif;?></form></div></div>
    <div class="col-lg-8"><div class="cardx"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h3 class="mb-0">Channel Register</h3><span class="badge bg-primary"><?=count($rows)?> channels</span><input class="form-control form-control-sm w-auto ms-auto" id="scFilter" placeholder="Search short code, service, provider…" style="min-width:16rem"></div>
        <p class="small text-muted mt-2 mb-2">The register says what each short code is <i>for</i>; the <b>Menu</b> and <b>On phones</b> columns show what is really built and answering today.</p>
        <div class="table-scroll"><table class="table table-hover" id="scTable"><thead><tr><th>Type</th><th>Short Code</th><th>Service Name</th><th>Provider</th><th>Status</th><th>Menu</th><th>On phones</th><th>Projects</th><th></th></tr></thead><tbody>
        <?php foreach($rows as $r): $ov=$r['channel_type']==='USSD'?($overview[$r['short_code']]??null):null; $stt=$r['channel_type']==='USSD'?shortcode_state($r['short_code'],$cfg,$registry):null;?>
        <tr><td><span class="badge bg-dark"><?=e($r['channel_type'])?></span></td><td><strong><?=e($r['short_code'])?></strong></td><td><?=e($r['service_name'])?></td><td><?=e($r['provider'])?></td>
            <td><span class="badge status-<?=e(strtolower($r['status']))?>"><?=e($r['status'])?></span></td>
            <td><?php if($r['channel_type']!=='USSD'):?><span class="text-muted">—</span><?php elseif(!$ov):?><span class="badge bg-secondary" title="Nothing has been built in the Menu Builder for this short code">no menu</span> <a class="small" href="?page=ussd_menu&new_short_code=<?=urlencode($r['short_code'])?>">build</a>
                <?php else:?><a href="?page=ussd_menu&short_code=<?=urlencode($r['short_code'])?>"><?=$ov['active']?> on<?=$ov['draft']?' · '.$ov['draft'].' draft':''?></a> <?php if($ov['errors']):?><span class="badge bg-danger"><?=$ov['errors']?> to fix</span><?php elseif($ov['warns']):?><span class="badge bg-warning text-dark"><?=$ov['warns']?> to check</span><?php else:?><span class="badge bg-success">ok</span><?php endif;?> <a class="small" href="?page=ussd_sim&sc=<?=urlencode($r['short_code'])?>">try</a><?php endif;?></td>
            <td><?php if(!$stt||!$ov):?><span class="text-muted">—</span><?php elseif($stt['live']):?><span class="badge bg-success">answering</span><?php else:?><span class="badge bg-secondary" title="Not answering because <?=e($stt['why'])?>">not live</span><?php endif;?></td>
            <td><?=e($r['linked'])?></td><td class="sticky-actions"><a class="btn btn-sm btn-warning" href="?page=shortcodes&id=<?=e($r['id'])?>">Edit</a></td></tr>
        <?php endforeach;?><?php if(!$rows):?><tr><td colspan="9" class="text-muted">Nothing registered yet.</td></tr><?php endif;?></tbody></table></div></div></div></div>
    <script nonce="<?=e(csp_nonce())?>">document.getElementById('scFilter')?.addEventListener('input',function(e){var q=e.target.value.toLowerCase();document.querySelectorAll('#scTable tbody tr').forEach(function(r){r.hidden=q!==''&&r.textContent.toLowerCase().indexOf(q)<0;});});</script>
    <?php layout_end(); exit;
